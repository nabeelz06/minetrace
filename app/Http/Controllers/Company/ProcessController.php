<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchTransformation;
use App\Models\Checkpoint;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Models\ProductCode;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ProcessController — Pengelolaan seluruh tahap proses produksi.
 *
 * ════════════════════════════════════════════════════════════════
 * TERMINOLOGI:
 *   Mineral Ikutan Timah (MIT/MIK):
 *     - Hasil samping penambangan timah (belum dipisahkan)
 *     - ZIR + ILM + MON masih tercampur dalam satu batch
 *     - Dibuat oleh operator_lab_station di Lab Kimia
 *
 *   Konsentrat Mineral Ikutan:
 *     - Hasil pemisahan Dry Process (Magnetic Separator)
 *     - 3 batch terpisah: MON (Monasit), ZIR (Zirkon), ILM (Ilmenit)
 *     - Dibuat oleh operator_dry, langsung masuk Warehouse
 *
 * ALUR PROSES LENGKAP:
 *   Lab Kimia (MIT) → Receiving → Wet Process → Dry Process
 *       → Warehouse [ZIR+ILM → Ekspor | MON → Project Plant]
 *       → Project Plant (Lab LTJ) → Warehouse [LTJ hasil] → Ekspor
 * ════════════════════════════════════════════════════════════════
 */
class ProcessController extends Controller
{
    /** Konfigurasi stage: label, icon, color, prev/next stage, lokasi */
    private const STAGES = [
        'lab_kimia'     => [
            'label' => 'Lab Kimia',      'label_en' => 'Chemical Lab',
            'icon'  => '🧪', 'color' => 'violet', 'location_type' => 'lab_kimia',
            'prev_stage' => null, 'next_stage' => 'receiving',
            'desc'  => 'Pembuatan batch Mineral Ikutan Timah (MIT)',
            'desc_en' => 'Creation of Tin Mineral By-product (MIT) batches',
        ],
        'receiving'     => [
            'label' => 'Receiving Area',  'label_en' => 'Receiving Area',
            'icon'  => '📥', 'color' => 'blue', 'location_type' => 'receiving',
            'prev_stage' => 'lab_kimia', 'next_stage' => 'wet_process',
            'desc'  => 'Penerimaan batch MIT dari Lab Kimia',
            'desc_en' => 'Receiving MIT batches from Chemical Lab',
        ],
        'wet_process'   => [
            'label' => 'Wet Process',     'label_en' => 'Wet Process',
            'icon'  => '💧', 'color' => 'cyan', 'location_type' => 'wet_process',
            'prev_stage' => 'receiving', 'next_stage' => 'dry_process',
            'desc'  => 'Shaking table — ekstraksi timah (Sn) dari MIT',
            'desc_en' => 'Shaking table — tin (Sn) extraction from MIT',
        ],
        'dry_process'   => [
            'label' => 'Dry Process',     'label_en' => 'Dry Process',
            'icon'  => '🔥', 'color' => 'amber', 'location_type' => 'dry_process',
            'prev_stage' => 'wet_process', 'next_stage' => 'warehouse',
            'desc'  => 'Magnetic separator — pisahkan MIT menjadi MON + ZIR + ILM',
            'desc_en' => 'Magnetic separator — split MIT into MON + ZIR + ILM',
        ],
        'project_plant' => [
            'label' => 'Project Plant',   'label_en' => 'Project Plant',
            'icon'  => '⚗️', 'color' => 'emerald', 'location_type' => 'project_plant',
            'prev_stage' => 'warehouse', 'next_stage' => 'warehouse',
            'desc'  => 'Lab Analisis LTJ — analisis kandungan La, Ce, Pr, Nd, Y dari Monasit',
            'desc_en' => 'LTJ Lab Analysis — La, Ce, Pr, Nd, Y content from Monazite',
        ],
        'warehouse'     => [
            'label' => 'LTJ Warehouse',   'label_en' => 'LTJ Warehouse',
            'icon'  => '🏭', 'color' => 'teal', 'location_type' => 'warehouse',
            'prev_stage' => 'dry_process', 'next_stage' => null,
            'desc'  => 'Gudang konsentrat: ZIR, ILM, MON, dan hasil unsur LTJ',
            'desc_en' => 'Concentrate warehouse: ZIR, ILM, MON, and LTJ elements',
        ],
    ];

    /** Role yang berwenang per stage */
    private const STAGE_ROLES = [
        'lab_kimia'     => ['company_admin', 'operator_lab_station'],
        'receiving'     => ['company_admin', 'operator_wet'],
        'wet_process'   => ['company_admin', 'operator_wet'],
        'dry_process'   => ['company_admin', 'operator_dry'],
        'project_plant' => ['company_admin', 'operator_lab_ltj'],
        'warehouse'     => ['company_admin', 'operator_warehouse'],
    ];

    /** Semua operator roles (untuk checkpoint) */
    private const ALL_OPERATOR_ROLES = [
        'company_admin',
        'operator_lab_station',
        'operator_lab_ltj',
        'operator_wet',
        'operator_dry',
        'operator_warehouse',
        'company_auditor', // read-only, tapi bisa lihat
    ];

    // ═══════════════════════════════════════════════════════════════════
    // OVERVIEW
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Overview semua tahap proses — ringkasan lintas stage.
     */
    public function overview(): Response
    {
        $companyId = Auth::user()->company_id;
        $tz        = 'Asia/Jakarta';

        // ─── Hitung metrics per stage ──────────────────────────────────────
        $stageSummaries = collect(self::STAGES)->map(function ($config, $stage) use ($companyId) {
            $batches = Batch::where('company_id', $companyId)
                ->where('process_stage', $stage)
                ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
                ->get(['id', 'current_weight', 'has_discrepancy', 'status']);

            // Incoming count (batch dari stage sebelumnya yang siap diterima)
            $incomingCount = 0;
            if ($config['prev_stage']) {
                $incomingCount = Batch::where('company_id', $companyId)
                    ->where('process_stage', $config['prev_stage'])
                    ->whereNotIn('status', ['exported', 'terminated', 'transformed', 'split'])
                    ->count();
            }

            return [
                'stage'          => $stage,
                'label'          => $config['label'],
                'label_en'       => $config['label_en'],
                'icon'           => $config['icon'],
                'color'          => $config['color'],
                'desc'           => $config['desc'],
                'desc_en'        => $config['desc_en'],
                'count'          => $batches->count(),
                'incoming_count' => $incomingCount,
                'total_weight'   => round($batches->sum('current_weight'), 2),
                'alert_count'    => $batches->where('has_discrepancy', true)->count(),
                'href'           => "/company/process/{$stage}",
            ];
        })->values()->toArray();

        // ─── Activity feed terbaru ─────────────────────────────────────────
        $recentCheckpoints = Checkpoint::with(['batch.productCode', 'location', 'recordedByUser'])
            ->whereHas('batch', fn($q) => $q->where('company_id', $companyId))
            ->latest('recorded_at')
            ->limit(30)
            ->get()
            ->map(fn($cp) => [
                'id'               => $cp->id,
                'batch_code'       => $cp->batch?->batch_code,
                'batch_id'         => $cp->batch_id,
                'product'          => $cp->batch?->productCode?->code,
                'stage'            => $cp->checkpoint_stage,
                'weight_recorded'  => $cp->weight_recorded,
                'weight_loss_pct'  => $cp->weight_loss_pct,
                'has_discrepancy'  => $cp->has_discrepancy,
                'location'         => $cp->location?->name,
                'recorded_by'      => $cp->recordedByUser?->name,
                'recorded_at'      => $cp->recorded_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                'recorded_at_diff' => $cp->recorded_at?->diffForHumans(),
            ]);

        // ─── Global stats ──────────────────────────────────────────────────
        $totalActive = Batch::where('company_id', $companyId)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
            ->count();
        $totalAlerts = Batch::where('company_id', $companyId)
            ->where('has_discrepancy', true)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
            ->count();
        $totalWeight = Batch::where('company_id', $companyId)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
            ->sum('current_weight');

        return Inertia::render('Company/Process/Overview', [
            'stage_summaries'    => $stageSummaries,
            'recent_checkpoints' => $recentCheckpoints,
            'global_stats'       => [
                'total_active'  => $totalActive,
                'total_alerts'  => $totalAlerts,
                'total_weight'  => round($totalWeight, 2),
                'stage_count'   => count(self::STAGES),
            ],
            'current_time'       => Carbon::now($tz)->format('l, d F Y — H:i:s') . ' WIB',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // STAGE DETAIL PAGE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Halaman detail satu stage.
     *
     * Menampilkan DUA panel:
     * 1. INCOMING — batch dari stage sebelumnya yang siap diterima
     * 2. CURRENT  — batch yang sedang ada di stage ini
     *
     * Ini adalah fix utama untuk masalah "batch hilang setelah diterima".
     */
    public function stage(Request $request, string $stage): Response
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404, 'Stage tidak ditemukan.');

        $companyId = Auth::user()->company_id;
        $config    = self::STAGES[$stage];
        $tz        = 'Asia/Jakarta';

        // ─── Batch CURRENT (di stage ini) ─────────────────────────────────
        $query = Batch::with([
                'productCode',
                'currentLocation',
                'checkpoints' => fn($q) => $q->latest('recorded_at')->limit(1),
            ])
            ->where('company_id', $companyId)
            ->where('process_stage', $stage)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
            ->latest('updated_at');

        if ($request->filled('search')) {
            $query->where('batch_code', 'ILIKE', "%{$request->search}%");
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->boolean('alerts_only')) {
            $query->where('has_discrepancy', true);
        }

        $batches = $query->paginate(15)->withQueryString();
        $batches->getCollection()->transform(fn($b) => $this->formatBatch($b, $tz));

        // ─── Batch INCOMING (dari stage sebelumnya) ────────────────────────
        // INI ADALAH FIX UTAMA: batch dari prev_stage ditampilkan agar
        // operator dapat menerimanya ke stage ini
        $incomingBatches = $this->getIncomingBatches($stage, $companyId, $tz);

        // ─── Stats ─────────────────────────────────────────────────────────
        $currentCount  = Batch::where('company_id', $companyId)
            ->where('process_stage', $stage)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
            ->count();
        $incomingCount = count($incomingBatches);

        $stats = [
            'current_count'  => $currentCount,
            'incoming_count' => $incomingCount,
            'alerts'         => Batch::where('company_id', $companyId)
                                    ->where('process_stage', $stage)
                                    ->where('has_discrepancy', true)->count(),
            'total_weight'   => round(
                Batch::where('company_id', $companyId)
                    ->where('process_stage', $stage)
                    ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
                    ->sum('current_weight'), 2
            ),
            'avg_loss_pct'   => round(
                Batch::where('company_id', $companyId)
                    ->where('process_stage', $stage)
                    ->avg('mass_loss_pct') ?? 0, 2
            ),
        ];

        // ─── Activity log (checkpoint di stage ini) ────────────────────────
        $activityLog = Checkpoint::with(['batch.productCode', 'recordedByUser', 'location'])
            ->whereHas('batch', fn($q) => $q->where('company_id', $companyId))
            ->where(fn($q) => $q
                ->where('checkpoint_stage', 'LIKE', "%{$stage}%")
                ->orWhere('checkpoint_stage', "{$stage}_receive")
                ->orWhere('checkpoint_stage', "{$stage}_checkpoint")
                ->orWhere('checkpoint_stage', "{$stage}_forward")
                ->orWhere('checkpoint_stage', "{$stage}_inbound")
            )
            ->latest('recorded_at')
            ->limit(30)
            ->get()
            ->map(fn($cp) => [
                'id'               => $cp->id,
                'batch_code'       => $cp->batch?->batch_code,
                'batch_id'         => $cp->batch_id,
                'product'          => $cp->batch?->productCode?->code,
                'stage'            => $cp->checkpoint_stage,
                'weight_recorded'  => $cp->weight_recorded,
                'weight_previous'  => $cp->weight_previous,
                'weight_loss_pct'  => $cp->weight_loss_pct,
                'has_discrepancy'  => $cp->has_discrepancy,
                'is_verified'      => $cp->is_verified,
                'notes'            => $cp->notes,
                'location'         => $cp->location?->name,
                'recorded_by'      => $cp->recordedByUser?->name,
                'recorded_at'      => $cp->recorded_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                'recorded_at_diff' => $cp->recorded_at?->diffForHumans(),
            ]);

        // ─── Threshold mass balance ─────────────────────────────────────────
        $threshold = MassBalanceThreshold::where('company_id', $companyId)
            ->where('process_stage', $stage)
            ->first();

        // ─── Lokasi stage ini ──────────────────────────────────────────────
        $locations = Location::where('company_id', $companyId)
            ->where('location_type', $config['location_type'])
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name', 'city'])
            ->toArray();

        // ─── Lokasi next stage (untuk forward) ────────────────────────────
        $nextStageLocations = [];
        if ($config['next_stage'] && isset(self::STAGES[$config['next_stage']])) {
            $nextConfig = self::STAGES[$config['next_stage']];
            $nextStageLocations = Location::where('company_id', $companyId)
                ->where('location_type', $nextConfig['location_type'])
                ->where('is_active', true)
                ->get(['id', 'code', 'name', 'full_name'])
                ->toArray();
        }

        // ─── Output products (khusus dry_process: MON, ZIR, ILM) ──────────
        $outputProducts = [];
        if ($stage === 'dry_process') {
            $outputProducts = ProductCode::where('company_id', $companyId)
                ->whereIn('code', ['MON', 'ZIR', 'ILM'])
                ->where('is_active', true)
                ->get(['id', 'code', 'name', 'is_export_blocked'])
                ->toArray();
        }

        // ─── All stages nav bar ────────────────────────────────────────────
        $allStages = collect(self::STAGES)->map(fn($c, $s) => [
            'stage'      => $s,
            'label'      => $c['label'],
            'label_en'   => $c['label_en'],
            'icon'       => $c['icon'],
            'href'       => "/company/process/{$s}",
            'is_current' => $s === $stage,
            'count'      => Batch::where('company_id', $companyId)->where('process_stage', $s)
                               ->whereNotIn('status', ['exported', 'terminated', 'transformed'])->count(),
        ])->values()->toArray();

        return Inertia::render('Company/Process/Stage', [
            'stage'                => $stage,
            'stage_config'         => array_merge($config, ['stage' => $stage]),
            'batches'              => $batches,
            'incoming_batches'     => $incomingBatches,
            'stats'                => $stats,
            'activity_log'         => $activityLog,
            'threshold'            => $threshold ? [
                'warning_pct'  => $threshold->warning_threshold_pct,
                'critical_pct' => $threshold->critical_threshold_pct,
            ] : null,
            'locations'            => $locations,
            'next_stage_locations' => $nextStageLocations,
            'output_products'      => $outputProducts,
            'all_stages'           => $allStages,
            'filters'              => $request->only(['search', 'status', 'alerts_only']),
            'user_role'            => Auth::user()->getRoleNames()->first(),
            'current_time'         => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // RECEIVE BATCH — Pindahkan batch DARI stage sebelumnya KE stage ini
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Terima batch dari stage sebelumnya — TANPA mengubah berat.
     *
     * Contoh:
     *   Lab Kimia → Receiving      : operator_wet menerima MIT dari Lab
     *   Receiving → Wet Process    : operator_wet mulai shaking table
     *   Wet Process → Dry Process  : (gunakan forwardBatch karena ada perubahan berat)
     *   Warehouse → Project Plant  : operator_warehouse kirim MON ke Lab LTJ
     *
     * Route: POST /company/process/{stage}/receive-batch
     */
    public function receiveBatch(Request $request, string $stage): RedirectResponse
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        // Validasi role berdasarkan stage tujuan
        $allowedRoles = self::STAGE_ROLES[$stage] ?? ['company_admin'];
        abort_unless(Auth::user()->hasAnyRole($allowedRoles), 403,
            'Anda tidak memiliki akses untuk menerima batch di stage ini.');

        $validated = $request->validate([
            'batch_id'    => 'required|integer|exists:batches,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'notes'       => 'nullable|string|max:1000',
        ]);

        $batch = Batch::with('productCode')
            ->where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        // Validasi: batch harus dari stage sebelumnya
        $config    = self::STAGES[$stage];
        $prevStage = $config['prev_stage'];

        if ($prevStage && $batch->process_stage !== $prevStage) {
            return back()->with('error',
                "Batch {$batch->batch_code} bukan berada di stage {$prevStage}. " .
                "Stage saat ini: {$batch->process_stage}.");
        }

        // Khusus project_plant: hanya batch MON
        if ($stage === 'project_plant' && $batch->productCode?->code !== 'MON') {
            return back()->with('error',
                "Hanya batch Monasit (MON) yang dapat dikirim ke Lab Analisis LTJ.");
        }

        return DB::transaction(function () use ($batch, $stage, $validated, $config) {
            $tz = 'Asia/Jakarta';

            // Tentukan lokasi tujuan
            $locationId = $validated['location_id'];
            if (!$locationId) {
                $defaultLoc = Location::where('company_id', Auth::user()->company_id)
                    ->where('location_type', $config['location_type'])
                    ->where('is_active', true)
                    ->first();
                $locationId = $defaultLoc?->id ?? $batch->current_location_id;
            }

            // Update batch: pindah ke stage ini
            $batch->update([
                'process_stage'       => $stage,
                'status'              => 'in_process',
                'current_location_id' => $locationId,
                'updated_by'          => Auth::id(),
            ]);

            // Lakukan mapping exact enum string berdasarkan nama $stage
            $exactCheckpointStage = match ($stage) {
                'receiving'     => 'receiving_verify',
                'wet_process'   => 'wet_process_input',
                'dry_process'   => 'dry_process_input',
                'project_plant' => 'project_plant_input',
                'warehouse'     => 'warehouse_inbound',
                'smelter'       => 'smelter_input',
                default         => $stage, 
            };

            // Catat checkpoint penerimaan
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $locationId,
                'checkpoint_stage' => $exactCheckpointStage, 
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => $validated['notes']
                    ?? "Batch diterima di {$config['label']} — " . Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                'checkpoint_data'  => [
                    'action'     => 'receive',
                    'from_stage' => $config['prev_stage'],
                    'to_stage'   => $stage,
                    'timestamp'  => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'recorded_by'  => Auth::id(),
                'recorded_at'  => now(),
            ]);

            return redirect()
                ->route('company.process.stage', $stage)
                ->with('success',
                    "✅ Batch {$batch->batch_code} ({$batch->productCode?->code}) berhasil diterima di {$config['label']}. " .
                    "Berat: " . number_format($batch->current_weight, 2) . " kg.");
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // RECORD CHECKPOINT — Catat berat di stage saat ini (tanpa pindah stage)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Catat checkpoint — update berat batch tanpa memindahkan stage.
     * Digunakan untuk monitoring dan mass balance selama proses berlangsung.
     *
     * PERBAIKAN UTAMA: Role check sekarang menggunakan 5 operator role BARU.
     * Sebelumnya masih 'operator_lab' dan 'operator_process' (tidak exist).
     */
    public function recordCheckpoint(Request $request, string $stage): RedirectResponse
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        // ─── PERBAIKAN: pakai role baru yang sesuai database ──────────────
        abort_unless(Auth::user()->hasAnyRole([
            'company_admin',
            'operator_lab_station',
            'operator_lab_ltj',
            'operator_wet',
            'operator_dry',
            'operator_warehouse',
        ]), 403, 'Anda tidak memiliki akses untuk mencatat checkpoint.');

        $validated = $request->validate([
            'batch_id'        => 'required|integer|exists:batches,id',
            'weight_recorded' => 'required|numeric|min:0',
            'location_id'     => 'nullable|integer|exists:locations,id',
            'notes'           => 'nullable|string|max:1000',
            'is_verified'     => 'nullable|boolean',
        ], [
            'batch_id.required'        => 'Pilih batch terlebih dahulu.',
            'weight_recorded.required' => 'Berat tercatat wajib diisi.',
            'weight_recorded.min'      => 'Berat tidak boleh negatif.',
        ]);

        $batch = Batch::where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        return DB::transaction(function () use ($validated, $batch, $stage) {
            $tz             = 'Asia/Jakarta';
            $previousWeight = (float) $batch->current_weight;
            $newWeight      = (float) $validated['weight_recorded'];
            $loss           = $previousWeight - $newWeight;
            $lossPct        = $previousWeight > 0
                ? round(($loss / $previousWeight) * 100, 2)
                : 0;

            // Cek threshold mass balance
            $threshold = MassBalanceThreshold::where('company_id', $batch->company_id)
                ->where('process_stage', $stage)
                ->first();
            $hasDiscrepancy = $threshold
                ? $lossPct >= $threshold->warning_threshold_pct
                : false;

            // Mapping stage ke Enum yang valid di database untuk checkpoint internal
            $exactCheckpointStage = match ($stage) {
                'receiving'     => 'receiving_verify',
                'wet_process'   => 'wet_process_input',
                'dry_process'   => 'dry_process_input',
                'project_plant' => 'project_plant_input',
                'warehouse'     => 'warehouse_transfer', 
                'lab_kimia'     => 'lab_sample_taken', 
                'smelter'       => 'smelter_input',
                default         => 'in_transit',
            };

            // Catat checkpoint
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $validated['location_id'] ?? $batch->current_location_id,
                'checkpoint_stage' => $exactCheckpointStage, 
                'weight_recorded'  => $newWeight,
                'weight_previous'  => $previousWeight,
                'weight_loss'      => $loss,
                'weight_loss_pct'  => $lossPct,
                'has_discrepancy'  => $hasDiscrepancy,
                'is_verified'      => $validated['is_verified'] ?? false,
                'notes'            => $validated['notes'] ?? null,
                'checkpoint_data'  => [
                    'action'        => 'checkpoint',
                    'stage'         => $stage,
                    'timestamp_wib' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'recorded_by'  => Auth::id(),
                'recorded_at'  => now(),
            ]);

            // Update berat batch
            $batch->update([
                'current_weight'  => $newWeight,
                'mass_loss_pct'   => $batch->initial_weight > 0
                    ? round((($batch->initial_weight - $newWeight) / $batch->initial_weight) * 100, 2)
                    : 0,
                'has_discrepancy' => $hasDiscrepancy,
                'updated_by'      => Auth::id(),
            ]);

            $message = "Checkpoint batch {$batch->batch_code} dicatat. Berat: {$newWeight} kg, Susut: {$lossPct}%.";
            if ($hasDiscrepancy) {
                $message .= " ⚠️ Susut melebihi threshold!";
            }

            return back()->with($hasDiscrepancy ? 'warning' : 'success', $message);
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // FORWARD BATCH — Pindahkan batch ke stage berikutnya dengan update berat
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Forward batch ke stage berikutnya — WITH weight update.
     *
     * Digunakan untuk:
     * - Receiving → Wet Process  (konfirmasi berat di receiving, lanjut)
     * - Wet Process → Dry Process (setelah shaking table, catat berat baru)
     *
     * Route: POST /company/process/{stage}/forward-batch
     */
    public function forwardBatch(Request $request, string $stage): RedirectResponse
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        $config    = self::STAGES[$stage];
        $nextStage = $config['next_stage'];
        abort_unless($nextStage && isset(self::STAGES[$nextStage]), 422,
            'Stage ini tidak memiliki next stage yang valid.');

        $allowedRoles = self::STAGE_ROLES[$stage] ?? ['company_admin'];
        abort_unless(Auth::user()->hasAnyRole($allowedRoles), 403);

        $validated = $request->validate([
            'batch_id'        => 'required|integer|exists:batches,id',
            'weight_recorded' => 'required|numeric|min:0.01',
            'location_id'     => 'nullable|integer|exists:locations,id',
            'notes'           => 'nullable|string|max:1000',
            'is_verified'     => 'nullable|boolean',
        ], [
            'weight_recorded.required' => 'Berat setelah proses wajib diisi.',
            'weight_recorded.min'      => 'Berat harus lebih dari 0.',
        ]);

        $batch = Batch::where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        if ($batch->process_stage !== $stage) {
            return back()->with('error',
                "Batch {$batch->batch_code} tidak berada di stage {$stage}. " .
                "Stage saat ini: {$batch->process_stage}.");
        }

        return DB::transaction(function () use ($validated, $batch, $stage, $nextStage) {
            $tz             = 'Asia/Jakarta';
            $previousWeight = (float) $batch->current_weight;
            $newWeight      = (float) $validated['weight_recorded'];
            $loss           = $previousWeight - $newWeight;
            $lossPct        = $previousWeight > 0
                ? round(($loss / $previousWeight) * 100, 2)
                : 0;

            // Cek threshold
            $threshold = MassBalanceThreshold::where('company_id', $batch->company_id)
                ->where('process_stage', $stage)->first();
            $hasDiscrepancy = $threshold ? $lossPct >= $threshold->warning_threshold_pct : false;

            // Tentukan lokasi tujuan (next stage)
            $locationId = $validated['location_id'];
            if (!$locationId) {
                $nextConfig = self::STAGES[$nextStage];
                $nextLoc    = Location::where('company_id', Auth::user()->company_id)
                    ->where('location_type', $nextConfig['location_type'])
                    ->where('is_active', true)->first();
                $locationId = $nextLoc?->id ?? $batch->current_location_id;
            }

            $nextConfig = self::STAGES[$nextStage];

            // Mapping stage asal ke nilai Enum Output/Forward yang valid di database
            $exactForwardStage = match ($stage) {
                'wet_process'   => 'wet_process_output',
                'dry_process'   => 'dry_process_output',
                'project_plant' => 'project_plant_output',
                'warehouse'     => 'warehouse_outbound',
                'receiving', 'lab_kimia' => 'in_transit', 
                default         => 'in_transit',
            };

            // Catat checkpoint "forward" di stage asal menggunakan mapping yang valid
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $batch->current_location_id,
                'checkpoint_stage' => $exactForwardStage, 
                'weight_recorded'  => $newWeight,
                'weight_previous'  => $previousWeight,
                'weight_loss'      => $loss,
                'weight_loss_pct'  => $lossPct,
                'has_discrepancy'  => $hasDiscrepancy,
                'is_verified'      => $validated['is_verified'] ?? false,
                'notes'            => $validated['notes'] ?? "Dikirim ke {$nextConfig['label']}",
                'checkpoint_data'  => [
                    'action'        => 'forward',
                    'from_stage'    => $stage,
                    'to_stage'      => $nextStage,
                    'timestamp_wib' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'recorded_by'  => Auth::id(),
                'recorded_at'  => now(),
            ]);

            // Update batch — pindah ke next stage
            $batch->update([
                'process_stage'       => $nextStage,
                'status'              => 'in_process',
                'current_weight'      => $newWeight,
                'mass_loss_pct'       => $batch->initial_weight > 0
                    ? round((($batch->initial_weight - $newWeight) / $batch->initial_weight) * 100, 2)
                    : 0,
                'has_discrepancy'     => $hasDiscrepancy,
                'current_location_id' => $locationId,
                'updated_by'          => Auth::id(),
            ]);

            $message = "✅ Batch {$batch->batch_code} berhasil dikirim ke {$nextConfig['label']}. Berat: {$newWeight} kg.";
            if ($hasDiscrepancy) {
                $message .= " ⚠️ Susut {$lossPct}% melebihi threshold!";
            }

            return redirect()
                ->route('company.process.stage', $stage)
                ->with($hasDiscrepancy ? 'warning' : 'success', $message);
        });
    }

    /**
     * Alias untuk forward dari wet_process.
     * Route: POST /company/process/wet/forward
     */
    public function wetForward(Request $request): RedirectResponse
    {
        return $this->forwardBatch($request, 'wet_process');
    }

    // ═══════════════════════════════════════════════════════════════════
    // DRY PROCESS TRANSFORMATION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Transformasi Dry Process — 1 batch MIT → 3 batch konsentrat.
     *
     * Hasil Magnetic Separator:
     *   MON (Monasit) — radioaktif, diblokir ekspor, dikirim ke Lab LTJ
     *   ZIR (Zirkon)  — dapat diekspor
     *   ILM (Ilmenit) — dapat diekspor
     *
     * Semua 3 batch output langsung masuk ke Warehouse (status: in_warehouse).
     *
     * Route: POST /company/process/dry/transform
     */
    public function dryTransform(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_dry']), 403,
            'Hanya Operator Dry Process yang dapat melakukan transformasi.');

        $validated = $request->validate([
            'batch_id'                      => 'required|integer|exists:batches,id',
            'notes'                         => 'nullable|string|max:1000',
            'outputs'                       => 'required|array|min:1|max:3',
            'outputs.*.product_code_id'     => 'required|integer|exists:product_codes,id',
            'outputs.*.weight_kg'           => 'required|numeric|min:0.01',
            'outputs.*.percentage'          => 'nullable|numeric|min:0|max:100',
        ], [
            'outputs.required'                   => 'Minimal satu output konsentrat wajib diisi.',
            'outputs.*.product_code_id.required' => 'Kode produk output wajib dipilih.',
            'outputs.*.weight_kg.required'       => 'Berat output wajib diisi.',
            'outputs.*.weight_kg.min'            => 'Berat output harus lebih dari 0.',
        ]);

        $companyId  = Auth::user()->company_id;
        $inputBatch = Batch::where('company_id', $companyId)
            ->findOrFail($validated['batch_id']);

        // State guards
        if ($inputBatch->process_stage !== 'dry_process') {
            return back()->with('error',
                "Batch {$inputBatch->batch_code} bukan di Dry Process. Stage: {$inputBatch->process_stage}.");
        }
        if (!in_array($inputBatch->status, ['active', 'in_process'])) {
            return back()->with('error',
                "Status batch tidak valid untuk transformasi: {$inputBatch->status}.");
        }

        // Validasi total output ≤ input
        $totalOutput = collect($validated['outputs'])->sum('weight_kg');
        if ($totalOutput > $inputBatch->current_weight) {
            return back()->with('error',
                "Total berat output ({$totalOutput} kg) tidak boleh melebihi berat input ({$inputBatch->current_weight} kg).");
        }

        return DB::transaction(function () use ($inputBatch, $validated, $companyId) {
            $tz          = 'Asia/Jakarta';
            $totalOutput = collect($validated['outputs'])->sum('weight_kg');
            $lossKg      = $inputBatch->current_weight - $totalOutput;
            $lossPct     = $inputBatch->current_weight > 0
                ? round(($lossKg / $inputBatch->current_weight) * 100, 2)
                : 0;

            // Lokasi warehouse untuk output
            $warehouseLoc = Location::where('company_id', $companyId)
                ->where('location_type', 'warehouse')
                ->where('is_active', true)
                ->first();

            // ─── Archive batch input ───────────────────────────────────────
            $inputBatch->update([
                'status'     => 'transformed',
                'updated_by' => Auth::id(),
            ]);

            // Checkpoint transformasi pada input
            Checkpoint::create([
                'batch_id'         => $inputBatch->id,
                'location_id'      => $inputBatch->current_location_id,
                'checkpoint_stage' => 'dry_process_input', 
                'weight_recorded'  => $inputBatch->current_weight,
                'weight_previous'  => $inputBatch->current_weight,
                'weight_loss'      => $lossKg,
                'weight_loss_pct'  => $lossPct,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => $validated['notes'] ?? 'Transformasi Dry Process — Magnetic Separator',
                'checkpoint_data'  => [
                    'action'              => 'dry_transform',
                    'total_output_weight' => $totalOutput,
                    'loss_kg'             => $lossKg,
                    'loss_pct'            => $lossPct,
                    'timestamp_wib'       => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'recorded_by'  => Auth::id(),
                'recorded_at'  => now(),
            ]);

            // ─── Buat 3 batch output baru ─────────────────────────────────
            $outputBatches    = [];
            $outputBatchCodes = [];

            foreach ($validated['outputs'] as $idx => $output) {
                $productCode = ProductCode::findOrFail($output['product_code_id']);

                // Generate batch code unik untuk output
                $date   = Carbon::now($tz);
                $prefix = 'B-' . $date->format('Ymd') . '-' . strtoupper($productCode->code) . '-';
                $last   = Batch::where('batch_code', 'LIKE', $prefix . '%')
                    ->orderBy('batch_code', 'desc')->first();
                $seq = $last ? (intval(substr($last->batch_code, -3)) + 1) : 1;
                $batchCode = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);

                // Ensure uniqueness
                $attempt = 0;
                while (Batch::where('batch_code', $batchCode)->exists() && $attempt < 10) {
                    $seq++;
                    $batchCode = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
                    $attempt++;
                }

                $outputBatch = Batch::create([
                    'company_id'          => $companyId,
                    'batch_code'          => $batchCode,
                    'product_code_id'     => $productCode->id,
                    'parent_batch_id'     => $inputBatch->id,
                    'initial_weight'      => $output['weight_kg'],
                    'current_weight'      => $output['weight_kg'],
                    'process_stage'       => 'warehouse',
                    'status'              => 'in_warehouse',
                    'current_location_id' => $warehouseLoc?->id,
                    'export_status'       => $productCode->is_export_blocked ? 'not_applicable' : 'available',
                    'metadata'            => [
                        'from_transformation'  => $inputBatch->batch_code,
                        'from_batch_id'        => $inputBatch->id,
                        'product_code'         => $productCode->code,
                        'percentage'           => $output['percentage'] ?? null,
                        'transformation_date'  => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    ],
                    'created_by' => Auth::id(),
                ]);

                // Checkpoint inbound warehouse untuk setiap output
                Checkpoint::create([
                    'batch_id'         => $outputBatch->id,
                    'location_id'      => $warehouseLoc?->id,
                    'checkpoint_stage' => 'warehouse_inbound',
                    'weight_recorded'  => $output['weight_kg'],
                    'weight_previous'  => 0,
                    'weight_loss'      => 0,
                    'weight_loss_pct'  => 0,
                    'has_discrepancy'  => false,
                    'is_verified'      => true,
                    'notes'            => "Output Dry Process dari {$inputBatch->batch_code}. Produk: {$productCode->code}.",
                    'checkpoint_data'  => [
                        'source_batch'  => $inputBatch->batch_code,
                        'product_code'  => $productCode->code,
                        'percentage'    => $output['percentage'] ?? null,
                    ],
                    'recorded_by'  => Auth::id(),
                    'recorded_at'  => now(),
                ]);

                $outputBatches[]    = $outputBatch;
                $outputBatchCodes[] = "{$productCode->code}: {$batchCode} ({$output['weight_kg']} kg)";
            }

            // ─── Audit trail transformasi ──────────────────────────────────
            BatchTransformation::create([
                'input_batch_id'         => $inputBatch->id,
                'output_batch_ids'       => collect($outputBatches)->pluck('id')->toArray(),
                'transformation_type'    => 'dry_process_extraction', 
                'location_id'            => $inputBatch->current_location_id,
                'transformation_details' => [
                    'machine'             => 'Magnetic Separator', 
                    'input_weight'        => $inputBatch->current_weight,
                    'total_output_weight' => $totalOutput,
                    'loss_kg'             => $lossKg,
                    'loss_pct'            => $lossPct,
                    'outputs'             => collect($outputBatches)->map(fn($b, $i) => [
                        'batch_code' => $b->batch_code,
                        'product'    => $b->productCode?->code,
                        'weight_kg'  => $validated['outputs'][$i]['weight_kg'],
                        'percentage' => $validated['outputs'][$i]['percentage'] ?? null,
                    ])->toArray(),
                    'timestamp_wib' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'notes'          => $validated['notes'] ?? 'Transformasi Dry Process — Magnetic Separator',
                'performed_by'   => Auth::id(),
                'transformed_at' => now(),
            ]);

            $outputList = implode(' | ', $outputBatchCodes);

            return redirect()
                ->route('company.process.stage', 'dry_process')
                ->with('success',
                    "✅ Transformasi Dry Process selesai! " .
                    "Batch {$inputBatch->batch_code} → {$outputList}. " .
                    "Semua konsentrat telah masuk ke Warehouse.");
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // WAREHOUSE → SEND MON TO LAB LTJ
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Kirim batch Monasit (MON) dari Warehouse ke Lab Analisis LTJ.
     *
     * Hanya MON yang boleh dikirim. ZIR dan ILM langsung ke ekspor.
     * Setelah analisis di Lab LTJ (project_plant), hasilnya kembali ke warehouse.
     *
     * Route: POST /company/process/warehouse/send-to-ltj
     */
    public function warehouseSendToLtj(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_warehouse']), 403);

        $validated = $request->validate([
            'batch_id'    => 'required|integer|exists:batches,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'notes'       => 'nullable|string|max:1000',
        ]);

        $batch = Batch::with('productCode')
            ->where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        // Guard: hanya MON
        if ($batch->productCode?->code !== 'MON') {
            return back()->with('error',
                "Hanya batch Monasit (MON) yang dapat dikirim ke Lab LTJ. " .
                "Produk batch ini: {$batch->productCode?->code}.");
        }

        // Guard: harus di warehouse
        if ($batch->process_stage !== 'warehouse') {
            return back()->with('error',
                "Batch {$batch->batch_code} tidak berada di Warehouse. " .
                "Stage saat ini: {$batch->process_stage}.");
        }

        return DB::transaction(function () use ($batch, $validated) {
            $tz = 'Asia/Jakarta';

            // Lokasi Project Plant
            $locationId = $validated['location_id'];
            if (!$locationId) {
                $ppLoc = Location::where('company_id', Auth::user()->company_id)
                    ->where('location_type', 'project_plant')
                    ->where('is_active', true)->first();
                $locationId = $ppLoc?->id ?? $batch->current_location_id;
            }

            // Update batch
            $batch->update([
                'process_stage'       => 'project_plant',
                'status'              => 'in_process',
                'current_location_id' => $locationId,
                'updated_by'          => Auth::id(),
            ]);

            // Checkpoint
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $locationId,
                'checkpoint_stage' => 'project_plant_input',
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => $validated['notes']
                    ?? "Batch MON dikirim ke Lab Analisis LTJ (Project Plant) — " . Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                'checkpoint_data'  => [
                    'action'     => 'send_to_ltj_lab',
                    'from_stage' => 'warehouse',
                    'to_stage'   => 'project_plant',
                ],
                'recorded_by'  => Auth::id(),
                'recorded_at'  => now(),
            ]);

            return redirect()
                ->route('company.process.stage', 'warehouse')
                ->with('success',
                    "✅ Batch Monasit {$batch->batch_code} ({$batch->current_weight} kg) " .
                    "berhasil dikirim ke Lab Analisis LTJ (Project Plant).");
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dapatkan batch dari stage sebelumnya yang siap diterima di stage ini.
     * Ini adalah core fix untuk masalah "batch hilang setelah dikirim".
     */
    private function getIncomingBatches(string $stage, int $companyId, string $tz): array
    {
        $config    = self::STAGES[$stage];
        $prevStage = $config['prev_stage'] ?? null;

        if (!$prevStage) return [];

        // Project Plant: khusus MON dari warehouse yang belum dianalisis
        if ($stage === 'project_plant') {
            return Batch::with(['productCode', 'currentLocation'])
                ->where('company_id', $companyId)
                ->where('process_stage', 'warehouse')
                ->where('status', 'in_warehouse')
                ->whereHas('productCode', fn($q) => $q->where('code', 'MON'))
                ->whereDoesntHave('labAnalyses', fn($q) => $q->where('is_validated', true))
                ->latest('updated_at')->limit(20)
                ->get()->map(fn($b) => $this->formatBatch($b, $tz))->toArray();
        }

        // Stage lain: ambil dari prev_stage yang belum di-transform/export
        return Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->where('process_stage', $prevStage)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed', 'split'])
            ->latest('updated_at')->limit(20)
            ->get()->map(fn($b) => $this->formatBatch($b, $tz))->toArray();
    }

    /**
     * Format batch untuk response JSON — dengan timestamp detail WIB.
     */
    private function formatBatch(Batch $b, string $tz): array
    {
        $lastCp = $b->checkpoints ? $b->checkpoints->last() : null;

        return [
            'id'              => $b->id,
            'batch_code'      => $b->batch_code,
            'lot_number'      => $b->lot_number,
            'product'         => $b->productCode ? [
                'id'                => $b->productCode->id,
                'code'              => $b->productCode->code,
                'name'              => $b->productCode->name,
                'is_hazardous'      => $b->productCode->is_hazardous,
                'is_export_blocked' => $b->productCode->is_export_blocked,
                'radioactive_level' => $b->productCode->radioactive_level,
            ] : null,
            'process_stage'   => $b->process_stage,
            'status'          => $b->status,
            'initial_weight'  => $b->initial_weight,
            'current_weight'  => $b->current_weight,
            'mass_loss_pct'   => $b->mass_loss_pct,
            'has_discrepancy' => $b->has_discrepancy,
            'location'        => $b->currentLocation ? [
                'id'   => $b->currentLocation->id,
                'code' => $b->currentLocation->code,
                'name' => $b->currentLocation->name,
            ] : null,
            'last_checkpoint' => $lastCp ? [
                'stage'       => $lastCp->checkpoint_stage,
                'recorded_at' => $lastCp->recorded_at?->diffForHumans(),
            ] : null,
            // Timestamp detail WIB
            'created_at'      => $b->created_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'created_at_date' => $b->created_at?->timezone($tz)->format('l, d F Y'),
            'created_at_time' => $b->created_at?->timezone($tz)->format('H:i:s') . ' WIB',
            'created_at_diff' => $b->created_at?->diffForHumans(),
            'updated_at'      => $b->updated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'updated_at_diff' => $b->updated_at?->diffForHumans(),
        ];
    }
}