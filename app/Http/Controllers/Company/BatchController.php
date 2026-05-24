<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Batch\CreateBatchRequest;
use App\Models\Batch;
use App\Models\Location;
use App\Models\ProductCode;
use App\Services\Batch\BatchService;
use App\Services\Batch\QrCodeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BatchController — CRUD batch untuk company users.
 *
 * WORKFLOW ROLES (FINAL — 11 roles):
 * ─────────────────────────────────────────────────────────────────────
 * operator_lab_station:
 *   ✅ Buat batch baru di Lab Kimia (awal pipeline)
 *   ✅ Input: kode produk MIK, berat awal, lokasi Lab Kimia, timestamp
 *   ✅ Output: batch dengan process_stage = 'lab_kimia', status = 'active'
 *
 * operator_wet:
 *   ✅ Lihat daftar batch (khususnya stage lab_kimia yang siap diterima)
 *   ❌ TIDAK bisa buat batch baru (403 dari create/store)
 *
 * operator_dry:
 *   ✅ Lihat daftar batch (khususnya stage wet_process yang siap diproses)
 *   ❌ TIDAK bisa buat batch baru
 *
 * operator_lab_ltj:
 *   ✅ Lihat daftar batch (khususnya batch MON di warehouse)
 *   ❌ TIDAK bisa buat batch baru (hanya analisis via LabController)
 *
 * operator_warehouse:
 *   ✅ Lihat daftar batch (kelola stok warehouse)
 *   ❌ TIDAK bisa buat batch baru
 *
 * company_admin:
 *   ✅ Semua akses penuh
 *
 * company_auditor:
 *   ✅ Lihat daftar + detail (read-only)
 *   ❌ TIDAK bisa buat, edit, atau hapus
 */
class BatchController extends Controller
{
    // ─── Roles yang boleh membuat batch baru (Lab Kimia) ─────────────────
    private const CAN_CREATE = ['company_admin', 'operator_lab_station', 'operator_wet'];

    // ─── Roles operator aktif (mengelola pipeline, bukan auditor/BIM) ─────
    private const ACTIVE_OPERATORS = [
        'company_admin',
        'operator_lab_station',
        'operator_lab_ltj',
        'operator_wet',
        'operator_dry',
        'operator_warehouse',
    ];

    public function __construct(
        private BatchService  $batchService,
        private QrCodeService $qrCodeService
    ) {}

    /**
     * Daftar batch — semua company roles boleh lihat.
     * Filter default disesuaikan per role agar tampilan relevan.
     */
    public function index(Request $request): Response
    {
        $user      = Auth::user();
        $companyId = $user->company_id;
        $userRole  = $user->getRoleNames()->first();

        $query = Batch::with(['productCode', 'currentLocation'])
            ->ofCompany($companyId)
            ->latest();

        // ─── Filter dari request ───────────────────────────────────────────
        if ($request->filled('search')) {
            $query->where('batch_code', 'ILIKE', "%{$request->search}%");
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('stage') && $request->stage !== 'all') {
            $query->where('process_stage', $request->stage);
        }
        if ($request->filled('product')) {
            $query->where('product_code_id', $request->product);
        }
        if ($request->boolean('alerts_only')) {
            $query->where('has_discrepancy', true);
        }

        // ─── Default filter per role (jika tidak ada filter manual) ─────────
        // Setiap operator hanya melihat batch yang relevan dengan tugasnya
        $hasManualFilter = $request->hasAny(['status', 'stage', 'search', 'product']);

        if (!$hasManualFilter) {
            match($userRole) {
                // Lab station: tampilkan batch milik sendiri + yang masih di lab_kimia
                'operator_lab_station' => $query->whereIn('process_stage', ['lab_kimia'])
                                                ->whereNotIn('status', ['exported', 'terminated']),

                // Wet: tampilkan batch dari lab_kimia (menunggu receiving) + aktif di wet
                'operator_wet'         => $query->whereIn('process_stage', ['lab_kimia', 'receiving', 'wet_process'])
                                                ->whereNotIn('status', ['exported', 'terminated', 'transformed']),

                // Dry: tampilkan batch dari wet_process yang siap + aktif di dry
                'operator_dry'         => $query->whereIn('process_stage', ['wet_process', 'dry_process'])
                                                ->whereNotIn('status', ['exported', 'terminated', 'transformed']),

                // Lab LTJ: tampilkan MON di warehouse + aktif di project_plant
                'operator_lab_ltj'     => $query->whereIn('process_stage', ['warehouse', 'project_plant'])
                                                ->whereNotIn('status', ['exported', 'terminated']),

                // Warehouse: tampilkan semua yang masuk warehouse (belum diekspor)
                'operator_warehouse'   => $query->where('process_stage', 'warehouse')
                                                ->whereNotIn('status', ['exported', 'terminated']),

                // Auditor & admin: semua batch (tanpa filter tambahan)
                default                => null,
            };
        }

        $batches = $query->paginate(20)->withQueryString();

        // ─── Transform data untuk frontend ────────────────────────────────
        $tz = 'Asia/Jakarta';
        $batches->getCollection()->transform(fn($b) => [
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
            'export_status'   => $b->export_status,
            'rfid_tag'        => $b->rfid_tag,
            // Timestamp detail WIB
            'created_at'      => $b->created_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'created_at_date' => $b->created_at?->timezone($tz)->format('l, d F Y'),
            'created_at_time' => $b->created_at?->timezone($tz)->format('H:i:s') . ' WIB',
            'created_at_diff' => $b->created_at?->diffForHumans(),
            'updated_at'      => $b->updated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'updated_at_diff' => $b->updated_at?->diffForHumans(),
        ]);

        // ─── Stats card ────────────────────────────────────────────────────
        $stats = [
            'total'           => Batch::ofCompany($companyId)->count(),
            'active'          => Batch::ofCompany($companyId)
                                    ->whereIn('status', ['active', 'in_transit', 'in_process'])
                                    ->count(),
            'in_warehouse'    => Batch::ofCompany($companyId)
                                    ->where('status', 'in_warehouse')
                                    ->count(),
            'has_alert'       => Batch::ofCompany($companyId)
                                    ->where('has_discrepancy', true)
                                    ->count(),
            'total_weight_kg' => Batch::ofCompany($companyId)
                                    ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
                                    ->sum('current_weight'),
        ];

        // ─── Stage distribution ───────────────────────────────────────────
        $stageCounts = Batch::ofCompany($companyId)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed'])
            ->selectRaw('process_stage, count(*) as cnt')
            ->groupBy('process_stage')
            ->pluck('cnt', 'process_stage')
            ->toArray();

        return Inertia::render('Company/Batches/Index', [
            'batches'      => $batches,
            'stats'        => $stats,
            'stage_counts' => $stageCounts,
            'filters'      => $request->only(['search', 'status', 'stage', 'product', 'alerts_only']),
            'productCodes' => ProductCode::ofCompany($companyId)
                                ->where('is_active', true)
                                ->get(['id', 'code', 'name']),
            'user_role'    => $userRole,
            // Flag untuk frontend — apakah user boleh buat batch
            'can_create'   => in_array($userRole, self::CAN_CREATE),
        ]);
    }

    /**
     * Form buat batch baru.
     *
     * ✅ company_admin        → boleh
     * ✅ operator_lab_station → boleh (tugas utama mereka)
     * ❌ semua role lain      → 403
     */
    public function create(): Response
    {
        // ─── PERBAIKAN UTAMA: nama role lama 'operator_lab' → 'operator_lab_station' ─
        abort_unless(
            Auth::user()->hasAnyRole(self::CAN_CREATE),
            403,
            'Hanya Operator Lab Kimia Stasiun dan Admin yang dapat membuat batch baru.'
        );

        $companyId = Auth::user()->company_id;

        return Inertia::render('Company/Batches/Create', [
            // Hanya produk raw_material yang bisa jadi batch awal Lab Kimia
            'productCodes' => ProductCode::ofCompany($companyId)
                ->where('is_active', true)
                ->whereIn('category', ['raw_material'])
                ->get(['id', 'code', 'name', 'is_hazardous', 'radioactive_level', 'description']),

            // Hanya lokasi Lab Kimia yang tersedia untuk stage awal
            'locations'    => Location::ofCompany($companyId)
                ->where('is_active', true)
                ->where('location_type', 'lab_kimia')
                ->get(['id', 'code', 'name', 'full_name', 'city']),

            'user_name'    => Auth::user()->name,
            'user_role'    => Auth::user()->getRoleNames()->first(),
            'timestamp_now'=> Carbon::now('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    /**
     * Simpan batch baru — batch pertama dalam pipeline (Lab Kimia).
     *
     * ✅ company_admin        → boleh
     * ✅ operator_lab_station → boleh
     * ❌ semua role lain      → 403
     */
    public function store(CreateBatchRequest $request): RedirectResponse
    {
        abort_unless(
            Auth::user()->hasAnyRole(self::CAN_CREATE),
            403,
            'Hanya Operator Lab Kimia Stasiun dan Admin yang dapat membuat batch baru.'
        );

        $batch = $this->batchService->createBatch([
            'company_id'      => Auth::user()->company_id,
            'product_code_id' => $request->product_code_id,
            'location_id'     => $request->location_id,
            'initial_weight'  => $request->initial_weight,
            'process_stage'   => 'lab_kimia',   // selalu dimulai dari Lab Kimia
            'status'          => 'active',
            'notes'           => $request->notes,
            'created_by'      => Auth::id(),
            'rfid_tag' => $request->rfid_tag ?? null,
        ]);

        $this->qrCodeService->generateForBatch($batch);

        return redirect()
            ->route('company.batches.show', $batch)
            ->with('success', "✅ Batch {$batch->batch_code} berhasil dibuat di Lab Kimia pada " .
                Carbon::now('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB.');
    }

    /**
     * Detail batch lengkap dengan timeline.
     * Semua company roles boleh lihat selama batch milik company yang sama.
     */
    public function show(Batch $batch): Response
    {
        // Pastikan batch milik company yang sama
        abort_unless(
            $batch->company_id === Auth::user()->company_id,
            403,
            'Batch ini tidak ditemukan atau Anda tidak memiliki akses.'
        );

        $batch->load([
            'productCode',
            'currentLocation',
            'parentBatch.productCode',
            'childBatches.productCode',
            'checkpoints.location',
            'checkpoints.recordedByUser',
            'labAnalyses.analyzedByUser',
        ]);

        $user     = Auth::user();
        $userRole = $user->getRoleNames()->first();
        $tz       = 'Asia/Jakarta';

        return Inertia::render('Company/Batches/Show', [
            'batch'    => [
                'id'              => $batch->id,
                'batch_code'      => $batch->batch_code,
                'lot_number'      => $batch->lot_number,
                'process_stage'   => $batch->process_stage,
                'status'          => $batch->status,
                'initial_weight'  => $batch->initial_weight,
                'current_weight'  => $batch->current_weight,
                'mass_loss_pct'   => $batch->mass_loss_pct,
                'has_discrepancy' => $batch->has_discrepancy,
                'export_status'   => $batch->export_status,
                'rfid_tag'        => $batch->rfid_tag,
                'notes'           => $batch->notes,
                // Timestamp detail
                'created_at'      => $batch->created_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                'created_at_full' => $batch->created_at?->timezone($tz)->format('l, d F Y — H:i:s') . ' WIB',
                'created_at_diff' => $batch->created_at?->diffForHumans(),
                'updated_at'      => $batch->updated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                'updated_at_diff' => $batch->updated_at?->diffForHumans(),
                'product'         => $batch->productCode ? [
                    'id'                 => $batch->productCode->id,
                    'code'               => $batch->productCode->code,
                    'name'               => $batch->productCode->name,
                    'name_en'            => $batch->productCode->name_en,
                    'is_hazardous'       => $batch->productCode->is_hazardous,
                    'is_export_blocked'  => $batch->productCode->is_export_blocked,
                    'radioactive_level'  => $batch->productCode->radioactive_level,
                    'hazard_types'       => $batch->productCode->hazard_types ?? [],
                    'export_block_reason'=> $batch->productCode->export_block_reason,
                ] : null,
                'location'        => $batch->currentLocation ? [
                    'id'            => $batch->currentLocation->id,
                    'code'          => $batch->currentLocation->code,
                    'name'          => $batch->currentLocation->name,
                    'location_type' => $batch->currentLocation->location_type,
                ] : null,
                'parent_batch'    => $batch->parentBatch ? [
                    'id'         => $batch->parentBatch->id,
                    'batch_code' => $batch->parentBatch->batch_code,
                    'product'    => $batch->parentBatch->productCode?->code,
                ] : null,
                'child_batches'   => $batch->childBatches->map(fn($c) => [
                    'id'         => $c->id,
                    'batch_code' => $c->batch_code,
                    'product'    => $c->productCode?->code,
                    'status'     => $c->status,
                    'weight'     => $c->current_weight,
                ])->toArray(),
                'checkpoints'     => $batch->checkpoints->map(fn($cp) => [
                    'id'               => $cp->id,
                    'checkpoint_stage' => $cp->checkpoint_stage,
                    'weight_recorded'  => $cp->weight_recorded,
                    'weight_previous'  => $cp->weight_previous,
                    'weight_loss'      => $cp->weight_loss,
                    'weight_loss_pct'  => $cp->weight_loss_pct,
                    'has_discrepancy'  => $cp->has_discrepancy,
                    'is_verified'      => $cp->is_verified,
                    'notes'            => $cp->notes,
                    'location'         => $cp->location?->name,
                    'recorded_by'      => $cp->recordedByUser?->name,
                    'recorded_at'      => $cp->recorded_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                    'recorded_at_diff' => $cp->recorded_at?->diffForHumans(),
                    'rfid_scan_code'   => $cp->rfid_scan_code,
                ])->toArray(),
                'lab_analyses'    => $batch->labAnalyses->map(fn($la) => [
                    'id'            => $la->id,
                    'la_pct'        => $la->la_pct,
                    'ce_pct'        => $la->ce_pct,
                    'pr_pct'        => $la->pr_pct,
                    'nd_pct'        => $la->nd_pct,
                    'y_pct'         => $la->y_pct,
                    'tho2_pct'      => $la->tho2_pct,
                    'u3o8_pct'      => $la->u3o8_pct,
                    'total_reo_pct' => $la->total_reo_pct,
                    'la_kg'         => $la->la_kg,
                    'ce_kg'         => $la->ce_kg,
                    'pr_kg'         => $la->pr_kg,
                    'nd_kg'         => $la->nd_kg,
                    'y_kg'          => $la->y_kg,
                    'lab_method'    => $la->lab_method,
                    'is_validated'  => $la->is_validated,
                    'validated_at'  => $la->validated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                    'analyzed_by'   => $la->analyzedByUser?->name,
                    'analyzed_at'   => $la->analyzed_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                    'notes'         => $la->notes,
                ])->toArray(),
            ],
            'timeline'  => $this->batchService->getTimeline($batch),
            'user_role' => $userRole,
            // Flags aksi yang boleh dilakukan user ini pada batch ini
            'can_actions' => [
                'create_batch'    => in_array($userRole, self::CAN_CREATE),
                'record_cp'       => in_array($userRole, self::ACTIVE_OPERATORS),
                'input_lab'       => in_array($userRole, ['company_admin', 'operator_lab_ltj']),
                'manage_warehouse'=> in_array($userRole, ['company_admin', 'operator_warehouse']),
                'create_export'   => in_array($userRole, ['company_admin', 'operator_warehouse']),
            ],
        ]);
    }

    /**
     * Generate QR Code — semua company roles boleh selama batch milik company-nya.
     */
    public function generateQR(Batch $batch): JsonResponse
    {
        abort_unless(
            $batch->company_id === Auth::user()->company_id,
            403,
            'Akses ditolak.'
        );

        $base64 = $this->qrCodeService->getBase64($batch);

        return response()->json([
            'success'   => true,
            'qr_base64' => $base64,
            'batch'     => [
                'id'         => $batch->id,
                'batch_code' => $batch->batch_code,
            ],
        ]);
    }
}