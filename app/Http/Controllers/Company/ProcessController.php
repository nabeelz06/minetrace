<?php

namespace App\Http\Controllers\Company;

use App\Services\Process\StageTransitionService;
use App\Support\ProcessFlow;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchTransformation;
use App\Models\Checkpoint;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Models\ProductCode;
use App\Services\Hardware\RfidAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ProcessController — Pengelolaan seluruh tahap proses produksi mineral.
 *
 * ════════════════════════════════════════════════════════════════════════
 * DESIGN PATTERNS YANG DIIMPLEMENTASIKAN:
 *
 * [Strategy]       STAGES const mendefinisikan perilaku per-stage:
 *                  prev/next stage, lokasi, role, label. Stage berbeda =
 *                  strategi berbeda tanpa if-else besar.
 *
 * [State]          Batch melewati state machine yang ketat:
 *                  active → in_process → in_transit → in_warehouse → exported
 *                  Setiap transisi di-validasi sebelum dieksekusi.
 *
 * [Template Method] receiveBatch() adalah template umum. Stage-specific behavior
 *                  (checkpoint enum, validasi khusus MON) di-inject via match().
 *
 * [Command]        Setiap method publik (receiveBatch, forwardBatch, dryTransform,
 *                  warehouseSendToLtj) adalah Command yang discrete dan reversible.
 *
 * [Facade]         ProcessController menyembunyikan kompleksitas: DB transaction,
 *                  checkpoint creation, location resolution, mass balance check.
 *
 * [Observer]       Checkpoint::create() dipanggil setiap transisi batch — observer
 *                  yang merekam semua perubahan state untuk audit trail.
 *
 * ALUR PROSES (State Machine):
 *   Lab Kimia (active) → Receiving → Wet Process → Dry Process
 *   → Warehouse [ZIR+ILM→Ekspor | MON→in_transit→Project Plant]
 *   → Project Plant (in_process) → Warehouse (in_warehouse) → Ekspor
 * ════════════════════════════════════════════════════════════════════════
 */
class ProcessController extends Controller
{
    public function __construct(private readonly StageTransitionService $transitions) {}

    // ─── [Strategy Pattern] Konfigurasi per-stage ────────────────────────
    // Definisi tahap: satu sumber di App\Support\ProcessFlow (dipakai juga RFID & frontend).
    private const STAGES = ProcessFlow::STAGES;

    // ─── Role per stage (Strategy for authorization) ──────────────────────
    // Peran per tahap: ProcessFlow::roles($stage)

    private const ALL_OPERATOR_ROLES = [
        'company_admin', 'operator_lab_station', 'operator_lab_ltj',
        'operator_wet', 'operator_dry', 'operator_warehouse', 'company_auditor',
    ];

    // ─── [State] Valid batch status transitions per action ────────────────
    private const STATUS_AFTER_RECEIVE  = ProcessFlow::STATUS_AFTER_RECEIVE;
    private const STATUS_AFTER_SEND     = ProcessFlow::STATUS_AFTER_SEND;
    private const STATUS_WAREHOUSE      = ProcessFlow::STATUS_WAREHOUSE;
    private const STATUS_TRANSFORMED    = 'transformed';

    // ═══════════════════════════════════════════════════════════════════
    // OVERVIEW — Ringkasan lintas stage
    // ═══════════════════════════════════════════════════════════════════

    public function overview(): Response
    {
        $companyId = Auth::user()->company_id;
        $tz        = 'Asia/Jakarta';

        // ─── [Facade] Sembunyikan kompleksitas kalkulasi per-stage ────────
        $stageSummaries = collect(self::STAGES)->map(function ($config, $stage) use ($companyId) {
            $batches = Batch::where('company_id', $companyId)
                ->where('process_stage', $stage)
                ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
                ->get(['id', 'current_weight', 'has_discrepancy', 'status']);

            $incomingCount = 0;
            if ($config['prev_stage']) {
                // Termasuk batch in_transit dari warehouse ke project_plant
                $incomingCount = $this->countIncoming($stage, $companyId);
            }

            return [
                'stage'          => $stage,
                'label'          => $config['label'],
                'label_en'       => $config['label_en'],
                'icon'           => $config['icon'],   // nama ikon lucide (bukan emoji)
                'icon_name'      => $config['icon'],
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

        $totalActive   = Batch::where('company_id', $companyId)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])->count();
        $totalAlerts   = Batch::where('company_id', $companyId)
            ->where('has_discrepancy', true)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])->count();
        $totalWeight   = Batch::where('company_id', $companyId)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])->sum('current_weight');
        $totalExported = Batch::where('company_id', $companyId)
            ->where('status', 'exported')->count();

        return Inertia::render('Company/Process/Overview', [
            'stage_summaries'    => $stageSummaries,
            'recent_checkpoints' => $recentCheckpoints,
            'global_stats'       => [
                'total_active'   => $totalActive,
                'total_alerts'   => $totalAlerts,
                'total_weight'   => round($totalWeight, 2),
                'stage_count'    => count(self::STAGES),
                'total_exported' => $totalExported,
            ],
            'current_time' => Carbon::now($tz)->format('l, d F Y — H:i:s') . ' WIB',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // STAGE DETAIL
    // ═══════════════════════════════════════════════════════════════════

    public function stage(Request $request, string $stage, RfidAuthorizationService $rfidAuth): Response
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        $companyId = Auth::user()->company_id;
        $config    = self::STAGES[$stage];
        $tz        = 'Asia/Jakarta';

        // ─── Batch CURRENT di stage ini ───────────────────────────────────
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

        // ─── Batch INCOMING dari stage sebelumnya ─────────────────────────
        $incomingBatches = $this->getIncomingBatches($stage, $companyId, $tz);

        // ─── Stats ─────────────────────────────────────────────────────────
        $stats = [
            'current_count'  => Batch::where('company_id', $companyId)
                ->where('process_stage', $stage)
                ->whereNotIn('status', ['exported', 'terminated', 'transformed'])->count(),
            'incoming_count' => count($incomingBatches),
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

        // ─── Activity log ──────────────────────────────────────────────────
        $activityLog = Checkpoint::with(['batch.productCode', 'recordedByUser', 'location'])
            ->whereHas('batch', fn($q) => $q->where('company_id', $companyId))
            ->where(fn($q) => $q
                ->where('checkpoint_stage', 'LIKE', "%{$stage}%")
                ->orWhereIn('checkpoint_stage', [
                    "{$stage}_receive", "{$stage}_checkpoint",
                    "{$stage}_forward", "{$stage}_inbound",
                ])
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
            ->where('process_stage', $stage)->first();

        // ─── Lokasi stage ini dan next stage ──────────────────────────────
        $locations = Location::where('company_id', $companyId)
            ->where('location_type', $config['location_type'])
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name', 'city'])
            ->toArray();

        $nextStageLocations = [];
        if ($config['next_stage'] && isset(self::STAGES[$config['next_stage']])) {
            $nextConfig = self::STAGES[$config['next_stage']];
            $nextStageLocations = Location::where('company_id', $companyId)
                ->where('location_type', $nextConfig['location_type'])
                ->where('is_active', true)
                ->get(['id', 'code', 'name', 'full_name'])
                ->toArray();
        }

        // ─── Output products untuk dry_process ────────────────────────────
        $outputProducts = [];
        if ($stage === 'dry_process') {
            $outputProducts = ProductCode::where('company_id', $companyId)
                ->whereIn('code', ['MON', 'ZIR', 'ILM'])
                ->where('is_active', true)
                ->get(['id', 'code', 'name', 'is_export_blocked'])
                ->toArray();
        }

        // ─── Navigation semua stage ────────────────────────────────────────
        $allStages = collect(self::STAGES)->map(fn($c, $s) => [
            'stage'      => $s,
            'label'      => $c['label'],
            'label_en'   => $c['label_en'],
            'icon'       => $c['icon'],
            'icon_name'  => $c['icon'],
            'href'       => "/company/process/{$s}",
            'is_current' => $s === $stage,
            'count'      => Batch::where('company_id', $companyId)
                               ->where('process_stage', $s)
                               ->whereNotIn('status', ['exported', 'terminated', 'transformed'])->count(),
        ])->values()->toArray();

        // ─── Warehouse inventory breakdown (hanya untuk stage warehouse) ───
        $warehouseInventory = [];
        $warehouseByExport  = [];

        if ($stage === 'warehouse') {
            $whBatches = Batch::with('productCode')
                ->where('company_id', $companyId)
                ->where('process_stage', 'warehouse')
                ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
                ->get();

            $warehouseInventory = $whBatches
                ->groupBy(fn($b) => $b->productCode?->code ?? 'OTHER')
                ->map(fn($grp, $code) => [
                    'code'             => $code,
                    'name'             => $grp->first()?->productCode?->name ?? $code,
                    'count'            => $grp->count(),
                    'total_weight_kg'  => round($grp->sum('current_weight'), 2),
                    'export_available' => $grp->where('export_status', 'available')->count(),
                    'export_blocked'   => $grp->where('export_status', 'not_applicable')->count(),
                ])
                ->sortByDesc('total_weight_kg')
                ->values()
                ->toArray();

            $warehouseByExport = [
                'available'       => round($whBatches->where('export_status', 'available')->sum('current_weight'), 2),
                'not_applicable'  => round($whBatches->where('export_status', 'not_applicable')->sum('current_weight'), 2),
                'reserved'        => round($whBatches->where('export_status', 'reserved')->sum('current_weight'), 2),
                'total_weight'    => round($whBatches->sum('current_weight'), 2),
                'count_available' => $whBatches->where('export_status', 'available')->count(),
                'count_blocked'   => $whBatches->where('export_status', 'not_applicable')->count(),
                'count_reserved'  => $whBatches->where('export_status', 'reserved')->count(),
            ];
        }

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
            'warehouse_inventory'  => $warehouseInventory,
            'warehouse_by_export'  => $warehouseByExport,
            'rfid_auth_mode'       => $rfidAuth->getMode($companyId, $stage),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // RECEIVE BATCH — [Template Method] pola umum, detail via match()
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Terima batch dari stage sebelumnya.
     *
     * [State] Transisi: prev_stage/* → current_stage/in_process
     * [Template Method] Validasi, update, checkpoint — urutan tetap.
     *                   Variabel: checkpoint enum, lokasi, validasi khusus.
     *
     * Route: POST /company/process/{stage}/receive-batch
     */
    public function receiveBatch(Request $request, string $stage): RedirectResponse
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        $allowedRoles = ProcessFlow::roles($stage);
        abort_unless(Auth::user()->hasAnyRole($allowedRoles), 403);

        $validated = $request->validate([
            'batch_id'    => 'required|integer|exists:batches,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'notes'       => 'nullable|string|max:1000',
        ]);

        $batch  = Batch::with('productCode')
            ->where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        $config = self::STAGES[$stage];

        try {
            $this->transitions->receive($batch, $stage, [
                'user_id'     => Auth::id(),
                'location_id' => $validated['location_id'] ?? null,
                'notes'       => $validated['notes'] ?? null,
                'source'      => 'web',
            ]);
        } catch (\DomainException $e) {
            // Guard serah-terima gagal (tahap/status/produk tidak sesuai, atau sudah diproses).
            return back()->with('error', $e->getMessage());
        }

        $batch->refresh();

        return redirect()
            ->route('company.process.stage', $stage)
            ->with('success',
                "✅ Batch {$batch->batch_code} ({$batch->productCode?->code}) berhasil diterima di {$config['label']}. " .
                "Berat: " . number_format($batch->current_weight, 2) . " kg.");
    }

    // ═══════════════════════════════════════════════════════════════════
    // RECORD CHECKPOINT — Catat berat tanpa pindah stage
    // ═══════════════════════════════════════════════════════════════════

    /** Route: POST /company/process/{stage}/checkpoint */
    public function recordCheckpoint(Request $request, string $stage): RedirectResponse
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        abort_unless(Auth::user()->hasAnyRole([
            'company_admin', 'operator_lab_station', 'operator_lab_ltj',
            'operator_wet', 'operator_dry', 'operator_warehouse',
        ]), 403);

        $validated = $request->validate([
            'batch_id'        => 'required|integer|exists:batches,id',
            'weight_recorded' => 'required|numeric|min:0',
            'location_id'     => 'nullable|integer|exists:locations,id',
            'notes'           => 'nullable|string|max:1000',
            'is_verified'     => 'nullable|boolean',
        ]);

        $batch = Batch::where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        return DB::transaction(function () use ($validated, $batch, $stage) {
            $tz             = 'Asia/Jakarta';
            $previousWeight = (float) $batch->current_weight;
            $newWeight      = (float) $validated['weight_recorded'];
            $loss           = $previousWeight - $newWeight;
            $lossPct        = $previousWeight > 0
                ? round(($loss / $previousWeight) * 100, 2) : 0;

            $threshold      = MassBalanceThreshold::where('company_id', $batch->company_id)
                ->where('process_stage', $stage)->first();
            $hasDiscrepancy = $threshold ? $lossPct >= $threshold->warning_threshold_pct : false;

            // ─── [Strategy] ENUM checkpoint per stage ─────────────────────
            $checkpointStage = match ($stage) {
                'receiving'     => 'receiving_verify',
                'wet_process'   => 'wet_process_input',
                'dry_process'   => 'dry_process_input',
                'project_plant' => 'project_plant_input',
                'warehouse'     => 'warehouse_transfer',
                'lab_kimia'     => 'lab_sample_taken',
                default         => 'in_transit',
            };

            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $validated['location_id'] ?? $batch->current_location_id,
                'checkpoint_stage' => $checkpointStage,
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
                'recorded_by' => Auth::id(),
                'recorded_at' => now(),
            ]);

            $batch->update([
                'current_weight'  => $newWeight,
                'mass_loss_pct'   => $batch->initial_weight > 0
                    ? round((($batch->initial_weight - $newWeight) / $batch->initial_weight) * 100, 2) : 0,
                'has_discrepancy' => $hasDiscrepancy,
                'updated_by'      => Auth::id(),
            ]);

            $message = "Checkpoint batch {$batch->batch_code} dicatat. Berat: {$newWeight} kg, Susut: {$lossPct}%.";
            if ($hasDiscrepancy) $message .= " ⚠️ Susut melebihi threshold!";

            return back()->with($hasDiscrepancy ? 'warning' : 'success', $message);
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // FORWARD BATCH — Pindah ke stage berikutnya dengan update berat
    // ═══════════════════════════════════════════════════════════════════

    /** Route: POST /company/process/{stage}/forward-batch */
    public function forwardBatch(Request $request, string $stage): RedirectResponse
    {
        abort_unless(array_key_exists($stage, self::STAGES), 404);

        $config    = self::STAGES[$stage];
        $nextStage = $config['next_stage'];
        abort_unless($nextStage && isset(self::STAGES[$nextStage]), 422);

        $allowedRoles = ProcessFlow::roles($stage);
        abort_unless(Auth::user()->hasAnyRole($allowedRoles), 403);

        $validated = $request->validate([
            'batch_id'        => 'required|integer|exists:batches,id',
            'weight_recorded' => 'required|numeric|min:0.01',
            'location_id'     => 'nullable|integer|exists:locations,id',
            'notes'           => 'nullable|string|max:1000',
            'is_verified'     => 'nullable|boolean',
        ]);

        $batch = Batch::where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        try {
            $result = $this->transitions->dispatch($batch, $stage, [
                'user_id'     => Auth::id(),
                'weight'      => (float) $validated['weight_recorded'],
                'notes'       => $validated['notes'] ?? null,
                'is_verified' => $validated['is_verified'] ?? false,
                'source'      => 'web',
            ]);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Batch tetap di tahap ini (in_transit) sampai tahap tujuan menekan "Terima".
        $nextLabel = self::STAGES[$nextStage]['label'];
        $message   = "✅ Batch {$batch->batch_code} dikirim ke {$nextLabel} (menunggu diterima). Berat: {$validated['weight_recorded']} kg.";
        if ($result['has_discrepancy']) {
            $message .= " ⚠️ Susut {$result['loss_pct']}% melebihi threshold!";
        }

        return redirect()->route('company.process.stage', $stage)
            ->with($result['has_discrepancy'] ? 'warning' : 'success', $message);
    }

    /** Alias untuk forward dari wet_process */
    public function wetForward(Request $request): RedirectResponse
    {
        return $this->forwardBatch($request, 'wet_process');
    }

    // ═══════════════════════════════════════════════════════════════════
    // DRY PROCESS TRANSFORMATION — [Command] 1 MIT → 3 konsentrat
    // ═══════════════════════════════════════════════════════════════════

    /** Route: POST /company/process/dry/transform */
    public function dryTransform(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_dry']), 403);

        $validated = $request->validate([
            'batch_id'                  => 'required|integer|exists:batches,id',
            'notes'                     => 'nullable|string|max:1000',
            'outputs'                   => 'required|array|min:1|max:3',
            'outputs.*.product_code_id' => 'required|integer|exists:product_codes,id',
            'outputs.*.weight_kg'       => 'required|numeric|min:0.01',
            'outputs.*.percentage'      => 'nullable|numeric|min:0|max:100',
        ]);

        $companyId  = Auth::user()->company_id;
        $inputBatch = Batch::where('company_id', $companyId)->findOrFail($validated['batch_id']);

        // ─── [Chain of Responsibility] Validasi state ─────────────────────
        if ($inputBatch->process_stage !== 'dry_process') {
            return back()->with('error', "Batch {$inputBatch->batch_code} bukan di Dry Process.");
        }
        if (!in_array($inputBatch->status, ['active', 'in_process'])) {
            return back()->with('error', "Status batch tidak valid: {$inputBatch->status}.");
        }

        $totalOutput = collect($validated['outputs'])->sum('weight_kg');
        if ($totalOutput > $inputBatch->current_weight) {
            return back()->with('error',
                "Total output ({$totalOutput} kg) melebihi berat input ({$inputBatch->current_weight} kg).");
        }

        return DB::transaction(function () use ($inputBatch, $validated, $companyId) {
            $tz          = 'Asia/Jakarta';
            $totalOutput = collect($validated['outputs'])->sum('weight_kg');
            $lossKg      = $inputBatch->current_weight - $totalOutput;
            $lossPct     = $inputBatch->current_weight > 0
                ? round(($lossKg / $inputBatch->current_weight) * 100, 2) : 0;

            $warehouseLoc = Location::where('company_id', $companyId)
                ->where('location_type', 'warehouse')->where('is_active', true)->first();

            // ─── [State] Archive batch input ──────────────────────────────
            $inputBatch->update([
                'status'     => self::STATUS_TRANSFORMED,
                'updated_by' => Auth::id(),
            ]);

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
                'recorded_by' => Auth::id(),
                'recorded_at' => now(),
            ]);

            // ─── [Factory Method] Buat 3 batch output baru ───────────────
            $outputBatches    = [];
            $outputBatchCodes = [];

            foreach ($validated['outputs'] as $idx => $output) {
                $productCode = ProductCode::findOrFail($output['product_code_id']);
                $batchCode   = $this->generateUniqueBatchCode($productCode->code, $tz);

                $outputBatch = Batch::create([
                    'company_id'          => $companyId,
                    'batch_code'          => $batchCode,
                    'product_code_id'     => $productCode->id,
                    'parent_batch_id'     => $inputBatch->id,
                    'initial_weight'      => $output['weight_kg'],
                    'current_weight'      => $output['weight_kg'],
                    'process_stage'       => 'warehouse',
                    'status'              => self::STATUS_WAREHOUSE,
                    'current_location_id' => $warehouseLoc?->id,
                    'export_status'       => $productCode->is_export_blocked ? 'not_applicable' : 'available',
                    'metadata'            => [
                        'from_transformation' => $inputBatch->batch_code,
                        'from_batch_id'       => $inputBatch->id,
                        'product_code'        => $productCode->code,
                        'percentage'          => $output['percentage'] ?? null,
                        'transformation_date' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    ],
                    'created_by' => Auth::id(),
                ]);

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
                        'source_batch' => $inputBatch->batch_code,
                        'product_code' => $productCode->code,
                        'percentage'   => $output['percentage'] ?? null,
                    ],
                    'recorded_by' => Auth::id(),
                    'recorded_at' => now(),
                ]);

                $outputBatches[]    = $outputBatch;
                $outputBatchCodes[] = "{$productCode->code}: {$batchCode} ({$output['weight_kg']} kg)";
            }

            // ─── [Memento] Audit trail transformasi ───────────────────────
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

            return redirect()->route('company.process.stage', 'dry_process')
                ->with('success',
                    "✅ Transformasi selesai! {$inputBatch->batch_code} → " .
                    implode(' | ', $outputBatchCodes) . ". Semua konsentrat masuk ke Warehouse.");
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // WAREHOUSE → SEND MON TO LAB LTJ
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Kirim batch Monasit dari Warehouse ke Lab Analisis LTJ.
     *
     * ════════════════════════════════════════════════════════════════
     * FIX UTAMA (State Machine):
     *
     * SEBELUMNYA (salah):
     *   process_stage → 'project_plant' (langsung)
     *   status        → 'in_process'    (langsung)
     *   Akibat: Lab LTJ tidak melihat batch di "Incoming" karena
     *           getIncomingBatches() cari dari warehouse in_warehouse
     *
     * SEKARANG (benar):
     *   process_stage → 'warehouse'    (tetap, belum pindah)
     *   status        → 'in_transit'   (dalam perjalanan)
     *   Akibat: Lab LTJ MELIHAT batch di "Incoming" panel
     *           Setelah klik "Terima" → receiveBatch('project_plant')
     *           → batch berpindah ke project_plant/in_process
     * ════════════════════════════════════════════════════════════════
     *
     * Route: POST /company/process/warehouse/send-to-ltj
     */
    public function warehouseSendToLtj(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_warehouse']), 403);

        $validated = $request->validate([
            'batch_id' => 'required|integer|exists:batches,id',
            'notes'    => 'nullable|string|max:1000',
        ]);

        $batch = Batch::with('productCode')
            ->where('company_id', Auth::user()->company_id)
            ->findOrFail($validated['batch_id']);

        // ─── [Chain of Responsibility] Validasi ───────────────────────────
        if ($batch->productCode?->code !== 'MON') {
            return back()->with('error',
                "Hanya batch Monasit (MON) yang dapat dikirim ke Lab LTJ. Produk: {$batch->productCode?->code}.");
        }
        if ($batch->process_stage !== 'warehouse') {
            return back()->with('error',
                "Batch {$batch->batch_code} tidak berada di Warehouse. Stage: {$batch->process_stage}.");
        }
        if ($batch->status === 'in_transit') {
            return back()->with('error',
                "Batch {$batch->batch_code} sudah dalam perjalanan ke Lab LTJ. Menunggu penerimaan.");
        }

        return DB::transaction(function () use ($batch, $validated) {
            $tz = 'Asia/Jakarta';

            // ─── [State] Set in_transit — TETAP di warehouse stage ────────
            // Batch belum "diterima" Lab LTJ. Lab LTJ harus klik "Terima"
            // di panel Incoming untuk memindahkan ke project_plant.
            $batch->update([
                'status'     => self::STATUS_AFTER_SEND, // 'in_transit'
                'updated_by' => Auth::id(),
                // process_stage TIDAK berubah — tetap 'warehouse'
                // current_location_id TIDAK berubah — tetap di gudang
            ]);

            // ─── [Observer] Catat checkpoint pengiriman ───────────────────
            // 'warehouse_transfer' = pengiriman keluar warehouse
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $batch->current_location_id,
                'checkpoint_stage' => 'warehouse_transfer',
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => $validated['notes']
                    ?? "Batch MON dikirim ke Lab Analisis LTJ — menunggu penerimaan. " .
                       Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                'checkpoint_data'  => [
                    'action'         => 'send_to_ltj_lab',
                    'from_stage'     => 'warehouse',
                    'to_stage'       => 'project_plant',
                    'status_change'  => 'in_transit',
                    'sent_by'        => Auth::user()->name,
                    'timestamp_wib'  => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'recorded_by' => Auth::id(),
                'recorded_at' => now(),
            ]);

            return redirect()->route('company.process.stage', 'warehouse')
                ->with('success',
                    "✅ Batch Monasit {$batch->batch_code} ({$batch->current_weight} kg) " .
                    "berhasil dikirim ke Lab Analisis LTJ. " .
                    "Operator Lab LTJ perlu menerima batch di halaman Project Plant.");
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Query batch yang SAH diterima di $stage — dari aturan ProcessFlow::receive_from
     * (tahap sebelumnya, status/produk yang disyaratkan). Satu definisi dengan guard receive().
     */
    private function incomingQuery(string $stage, int $companyId)
    {
        $rules = ProcessFlow::STAGES[$stage]['receive_from'] ?? [];

        return Batch::where('company_id', $companyId)
            ->whereNotIn('status', ProcessFlow::CLOSED_STATUSES)
            ->where(function ($q) use ($rules) {
                foreach ($rules as $rule) {
                    $q->orWhere(function ($r) use ($rule) {
                        $r->where('process_stage', $rule['stage']);
                        if (isset($rule['require_status'])) {
                            $r->where('status', $rule['require_status']);
                        }
                        if (isset($rule['require_product'])) {
                            $r->whereHas('productCode', fn ($p) => $p->where('code', $rule['require_product']));
                        }
                    });
                }
            });
    }

    private function countIncoming(string $stage, int $companyId): int
    {
        if (empty(ProcessFlow::STAGES[$stage]['receive_from'])) {
            return 0;
        }

        return $this->incomingQuery($stage, $companyId)->count();
    }

    /** Batch incoming untuk panel kiri Stage.vue (status in_transit = sudah dikirim tahap asal). */
    private function getIncomingBatches(string $stage, int $companyId, string $tz): array
    {
        if (empty(ProcessFlow::STAGES[$stage]['receive_from'])) {
            return [];
        }

        return $this->incomingQuery($stage, $companyId)
            ->with(['productCode', 'currentLocation', 'checkpoints'])
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($b) => $this->formatBatch($b, $tz))
            ->toArray();
    }

    /**
     * Format batch untuk response JSON ke Vue.
     * [Flyweight] Reuse format yang sama di semua tempat.
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
            'export_status'   => $b->export_status,
            'rfid_tag'        => $b->rfid_tag,
            'location'        => $b->currentLocation ? [
                'id'   => $b->currentLocation->id,
                'code' => $b->currentLocation->code,
                'name' => $b->currentLocation->name,
            ] : null,
            'last_checkpoint' => $lastCp ? [
                'stage'       => $lastCp->checkpoint_stage,
                'recorded_at' => $lastCp->recorded_at?->diffForHumans(),
            ] : null,
            'created_at'      => $b->created_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'created_at_date' => $b->created_at?->timezone($tz)->format('l, d F Y'),
            'created_at_time' => $b->created_at?->timezone($tz)->format('H:i:s') . ' WIB',
            'created_at_diff' => $b->created_at?->diffForHumans(),
            'updated_at'      => $b->updated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'updated_at_diff' => $b->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Generate batch code unik dengan while-loop untuk race condition safety.
     * [Builder] Konstruksi kode batch step by step.
     */
    private function generateUniqueBatchCode(string $productCode, string $tz): string
    {
        $date   = Carbon::now($tz);
        $prefix = 'B-' . $date->format('Ymd') . '-' . strtoupper($productCode) . '-';
        $last   = Batch::where('batch_code', 'LIKE', $prefix . '%')
            ->orderBy('batch_code', 'desc')->first();
        $seq    = $last ? (intval(substr($last->batch_code, -3)) + 1) : 1;
        $code   = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $attempt = 0;
        while (Batch::where('batch_code', $code)->exists() && $attempt < 20) {
            $seq++;
            $code = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
            $attempt++;
        }

        return $code;
    }
}