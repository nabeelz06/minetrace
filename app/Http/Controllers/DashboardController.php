<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\LabAnalysis;
use App\Models\Location;
use App\Models\User;
use App\Services\Analytics\BimAnalyticsService;
use App\Services\Analytics\CompanyAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

/**
 * DashboardController — redirect + render dashboard per role.
 *
 * Setiap operator mendapat dashboard yang fokus pada tugas mereka:
 * - Lab Station : batch MIK aktif + form buat batch
 * - Wet Process : batch menunggu di receiving + aktif di wet process
 * - Dry Process : batch dari wet process yang siap diproses
 * - Lab LTJ     : batch MON dari warehouse + form input analisis LTJ
 * - Warehouse   : semua batch masuk + stok + siap ekspor
 */
class DashboardController extends Controller
{
    public function __construct(
        private CompanyAnalyticsService $companyAnalytics,
        private BimAnalyticsService     $bimAnalytics,
    ) {}

    /**
     * Redirect ke dashboard sesuai role.
     */
    public function redirect(): RedirectResponse
    {
        $user = Auth::user();
        $role = $user->getRoleNames()->first();

        return match($role) {
            'super_admin'          => redirect()->route('super-admin.dashboard'),
            'bim_admin',
            'bim_viewer',
            'government_auditor'   => redirect()->route('bim.dashboard'),
            default                => redirect()->route('company.dashboard'),
        };
    }

    /**
     * Dashboard Company — context berbeda per role.
     * Semua company roles masuk ke sini, tapi data yang dikirim berbeda.
     */
    public function company(): Response
    {
        $user      = Auth::user();
        $companyId = $user->company_id;
        $role      = $user->getRoleNames()->first();

        abort_unless($companyId, 403, 'User tidak terikat ke perusahaan manapun.');

        // ─── Data umum untuk semua roles ──────────────────────────────────
        $company = $user->company;

        // Format timestamp helper
        $now = Carbon::now('Asia/Jakarta');

        // ─── Dashboard data disesuaikan per role ──────────────────────────
        $dashboardData = match($role) {
            'operator_lab_station' => $this->getLabStationDashboard($companyId, $user),
            'operator_wet'         => $this->getWetProcessDashboard($companyId, $user),
            'operator_dry'         => $this->getDryProcessDashboard($companyId, $user),
            'operator_lab_ltj'     => $this->getLabLtjDashboard($companyId, $user),
            'operator_warehouse'   => $this->getWarehouseDashboard($companyId, $user),
            'company_auditor'      => $this->getAuditorDashboard($companyId),
            default                => $this->getAdminDashboard($companyId), // company_admin
        };

        return Inertia::render('Company/Dashboard', array_merge($dashboardData, [
            'role'          => $role,
            'company'       => [
                'name'          => $company->name,
                'code'          => $company->code,
                'primary_color' => $company->primary_color,
            ],
            'current_time'  => $now->format('l, d F Y — H:i:s'),
            'timestamp_wib' => $now->format('d/m/Y H:i') . ' WIB',
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPERATOR LAB STATION — fokus: buat batch MIK
    // ─────────────────────────────────────────────────────────────────────────

    private function getLabStationDashboard(int $companyId, $user): array
    {
        // Batch MIK yang dibuat hari ini
        $todayBatches = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->where('process_stage', 'lab_kimia')
            ->whereDate('created_at', today())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Batch MIK aktif bulan ini
        $monthBatches = Batch::where('company_id', $companyId)
            ->where('process_stage', 'lab_kimia')
            ->whereMonth('created_at', now()->month)
            ->count();

        // Recent activity dari user ini
        $recentActivity = Batch::with('productCode')
            ->where('company_id', $companyId)
            ->where('created_by', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($b) => $this->formatBatchActivity($b));

        // Product codes yang tersedia
        $availableProducts = \App\Models\ProductCode::ofCompany($companyId)
            ->where('is_active', true)
            ->whereIn('category', ['raw_material'])
            ->get(['id', 'code', 'name', 'is_hazardous', 'radioactive_level']);

        // Lokasi Lab Kimia
        $locations = Location::ofCompany($companyId)
            ->where('location_type', 'lab_kimia')
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name', 'city']);

        return [
            'page_context'      => 'lab_station',
            'page_title'        => 'Lab Kimia — Stasiun Uji',
            'page_subtitle'     => 'Buat batch mineral ikutan (MIK) dan catat hasil uji Lab Kimia',
            'stats'             => [
                'today_created'     => $todayBatches->count(),
                'month_created'     => $monthBatches,
                'total_today_weight'=> round($todayBatches->sum('current_weight'), 2),
                'my_total_batches'  => Batch::where('company_id', $companyId)->where('created_by', $user->id)->count(),
            ],
            'today_batches'     => $todayBatches,
            'recent_activity'   => $recentActivity,
            'available_products'=> $availableProducts,
            'locations'         => $locations,
            'can_create'        => true,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPERATOR WET — fokus: receiving + wet process
    // ─────────────────────────────────────────────────────────────────────────

    private function getWetProcessDashboard(int $companyId, $user): array
    {
        // Batch menunggu di receiving (dari Lab Station)
        $waitingReceiving = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->where('process_stage', 'lab_kimia')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Batch aktif di Wet Process
        $inWetProcess = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->whereIn('process_stage', ['receiving', 'wet_process'])
            ->whereNotIn('status', ['exported','terminated','transformed'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Activity log wet process
        $recentCheckpoints = Checkpoint::with(['batch.productCode', 'location'])
            ->whereHas('batch', fn($q) => $q->where('company_id', $companyId))
            ->whereIn('checkpoint_stage', ['receiving_verify','wet_process_checkpoint','wet_process_complete'])
            ->orderByDesc('recorded_at')
            ->limit(15)
            ->get()
            ->map(fn($cp) => $this->formatCheckpointActivity($cp));

        // Locations wet
        $locations = Location::ofCompany($companyId)
            ->whereIn('location_type', ['receiving', 'wet_process'])
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name', 'city', 'location_type']);

        return [
            'page_context'       => 'wet_process',
            'page_title'         => 'Wet Process — Stasiun Shaking Table',
            'page_subtitle'      => 'Terima batch MIK dari Lab Kimia, proses di shaking table, kirim ke Dry Process',
            'stats'              => [
                'waiting_receiving' => $waitingReceiving->count(),
                'in_wet_process'    => $inWetProcess->count(),
                'total_weight_active'=> round($inWetProcess->sum('current_weight'), 2),
                'processed_today'   => Batch::where('company_id', $companyId)
                    ->where('process_stage', 'wet_process')
                    ->whereDate('updated_at', today())->count(),
            ],
            'waiting_receiving'  => $waitingReceiving,
            'in_wet_process'     => $inWetProcess,
            'recent_checkpoints' => $recentCheckpoints,
            'locations'          => $locations,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPERATOR DRY — fokus: dry process + 3 output konsentrat
    // ─────────────────────────────────────────────────────────────────────────

    private function getDryProcessDashboard(int $companyId, $user): array
    {
        // Batch dari Wet Process yang siap diproses Dry
        $readyForDry = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->where('process_stage', 'wet_process')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Batch aktif di Dry Process
        $inDryProcess = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->where('process_stage', 'dry_process')
            ->whereNotIn('status', ['exported','terminated','transformed'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Output ZIR+ILM+MON hari ini
        $outputToday = Batch::with('productCode')
            ->where('company_id', $companyId)
            ->whereIn('process_stage', ['warehouse'])
            ->whereHas('productCode', fn($q) => $q->whereIn('code', ['MON','ZIR','ILM']))
            ->whereDate('created_at', today())
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Locations dry
        $locations = Location::ofCompany($companyId)
            ->where('location_type', 'dry_process')
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name', 'city']);

        // Product codes output dry (ZIR, ILM, MON)
        $outputProducts = \App\Models\ProductCode::ofCompany($companyId)
            ->whereIn('code', ['MON', 'ZIR', 'ILM'])
            ->where('is_active', true)
            ->get();

        return [
            'page_context'     => 'dry_process',
            'page_title'       => 'Dry Process — Magnetic Separator',
            'page_subtitle'    => 'Pisahkan mineral ikutan menjadi Monasit, Zirkon, dan Ilmenit',
            'stats'            => [
                'ready_for_dry'   => $readyForDry->count(),
                'in_dry_process'  => $inDryProcess->count(),
                'output_today'    => $outputToday->count(),
                'mon_today_kg'    => round($outputToday->where('product.code', 'MON')->sum('current_weight'), 2),
                'zir_today_kg'    => round($outputToday->where('product.code', 'ZIR')->sum('current_weight'), 2),
                'ilm_today_kg'    => round($outputToday->where('product.code', 'ILM')->sum('current_weight'), 2),
            ],
            'ready_for_dry'    => $readyForDry,
            'in_dry_process'   => $inDryProcess,
            'output_today'     => $outputToday,
            'locations'        => $locations,
            'output_products'  => $outputProducts,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPERATOR LAB LTJ — fokus: terima MON, analisis, kirim ke warehouse
    // ─────────────────────────────────────────────────────────────────────────

    private function getLabLtjDashboard(int $companyId, $user): array
    {
        // Batch MON di warehouse siap dianalisis
        $pendingMonazite = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->whereHas('productCode', fn($q) => $q->where('code', 'MON'))
            ->where('process_stage', 'warehouse')
            ->where('status', 'in_warehouse')
            ->whereDoesntHave('labAnalyses', fn($q) => $q->where('is_validated', true))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Batch LTJ aktif di Project Plant (sedang dianalisis)
        $inProjectPlant = Batch::with(['productCode', 'labAnalyses'])
            ->where('company_id', $companyId)
            ->where('process_stage', 'project_plant')
            ->whereNotIn('status', ['exported','terminated','transformed'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Lab analyses bulan ini
        $analyses = LabAnalysis::with(['batch.productCode'])
            ->where('company_id', $companyId)
            ->whereMonth('analyzed_at', now()->month)
            ->orderByDesc('analyzed_at')
            ->limit(10)
            ->get()
            ->map(fn($la) => [
                'id'            => $la->id,
                'batch_code'    => $la->batch?->batch_code,
                'batch_id'      => $la->batch_id,
                'product'       => $la->batch?->productCode?->code,
                'total_reo_pct' => $la->total_reo_pct,
                'la_pct'        => $la->la_pct,
                'ce_pct'        => $la->ce_pct,
                'nd_pct'        => $la->nd_pct,
                'is_validated'  => $la->is_validated,
                'analyzed_at'   => $la->analyzed_at?->format('d/m/Y H:i') . ' WIB',
                'analyzed_at_diff' => $la->analyzed_at?->diffForHumans(),
            ]);

        // Locations project plant
        $locations = Location::ofCompany($companyId)
            ->where('location_type', 'project_plant')
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name', 'city']);

        return [
            'page_context'      => 'lab_ltj',
            'page_title'        => 'Lab Analisis LTJ — Project Plant',
            'page_subtitle'     => 'Terima Monasit dari Gudang, analisis kandungan LTJ, kirim hasil ke Gudang',
            'stats'             => [
                'pending_monazite'  => $pendingMonazite->count(),
                'in_project_plant'  => $inProjectPlant->count(),
                'analyzed_month'    => LabAnalysis::where('company_id', $companyId)->whereMonth('analyzed_at', now()->month)->count(),
                'avg_reo_month'     => round(LabAnalysis::where('company_id', $companyId)->whereMonth('analyzed_at', now()->month)->avg('total_reo_pct') ?? 0, 2),
            ],
            'pending_monazite'  => $pendingMonazite,
            'in_project_plant'  => $inProjectPlant,
            'recent_analyses'   => $analyses,
            'locations'         => $locations,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPERATOR WAREHOUSE — fokus: kelola semua material masuk + ekspor
    // ─────────────────────────────────────────────────────────────────────────

    private function getWarehouseDashboard(int $companyId, $user): array
    {
        // Semua batch di warehouse
        $inWarehouse = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->where('process_stage', 'warehouse')
            ->where('status', 'in_warehouse')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Breakdown per produk
        $warehouseBreakdown = Batch::with('productCode')
            ->where('company_id', $companyId)
            ->where('process_stage', 'warehouse')
            ->where('status', 'in_warehouse')
            ->get()
            ->groupBy('productCode.code')
            ->map(fn($group, $code) => [
                'code'       => $code ?? '—',
                'count'      => $group->count(),
                'weight_kg'  => round($group->sum('current_weight'), 2),
                'is_blocked' => $group->first()?->productCode?->is_export_blocked ?? false,
            ])
            ->values();

        // Batch yang siap ekspor (bukan MON)
        $readyForExport = Batch::with('productCode')
            ->where('company_id', $companyId)
            ->where('process_stage', 'warehouse')
            ->where('status', 'in_warehouse')
            ->whereHas('productCode', fn($q) => $q->where('is_export_blocked', false))
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // MON yang siap dikirim ke Lab LTJ
        $readyForLtjLab = Batch::with('productCode')
            ->where('company_id', $companyId)
            ->where('process_stage', 'warehouse')
            ->where('status', 'in_warehouse')
            ->whereHas('productCode', fn($q) => $q->where('code', 'MON'))
            ->whereDoesntHave('labAnalyses', fn($q) => $q->where('is_validated', true))
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        // Ekspor terbaru
        $recentExports = ExportLog::where('company_id', $companyId)
            ->orderByDesc('exported_at')
            ->limit(5)
            ->get()
            ->map(fn($e) => [
                'id'          => $e->id,
                'export_type' => $e->export_type,
                'destination' => $e->destination_company,
                'weight_kg'   => $e->total_weight_kg,
                'status'      => $e->status,
                'exported_at' => $e->exported_at?->format('d/m/Y H:i') . ' WIB',
            ]);

        // Warehouse locations
        $locations = Location::ofCompany($companyId)
            ->where('location_type', 'warehouse')
            ->where('is_active', true)
            ->get();

        return [
            'page_context'       => 'warehouse',
            'page_title'         => 'Gudang LTJ — Warehouse Management',
            'page_subtitle'      => 'Kelola stok mineral konsentrat dan hasil LTJ, siapkan ekspor',
            'stats'              => [
                'total_in_warehouse' => $inWarehouse->count(),
                'total_weight_kg'    => round($inWarehouse->sum('current_weight'), 2),
                'ready_for_export'   => $readyForExport->count(),
                'ready_for_ltj_lab'  => $readyForLtjLab->count(),
                'mon_count'          => $inWarehouse->filter(fn($b) => $b['product']['code'] === 'MON')->count(),
                'zir_count'          => $inWarehouse->filter(fn($b) => $b['product']['code'] === 'ZIR')->count(),
                'ilm_count'          => $inWarehouse->filter(fn($b) => $b['product']['code'] === 'ILM')->count(),
            ],
            'in_warehouse'       => $inWarehouse,
            'warehouse_breakdown'=> $warehouseBreakdown,
            'ready_for_export'   => $readyForExport,
            'ready_for_ltj_lab'  => $readyForLtjLab,
            'recent_exports'     => $recentExports,
            'locations'          => $locations,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPANY ADMIN — overview komprehensif
    // ─────────────────────────────────────────────────────────────────────────

    private function getAdminDashboard(int $companyId): array
    {
        $kpiCards            = $this->companyAnalytics->getKpiCards($companyId);
        $stageDistribution   = $this->companyAnalytics->getStageDistribution($companyId);
        $productionTrend     = $this->companyAnalytics->getProductionTrend($companyId, 30);
        $massBalanceSummary  = $this->companyAnalytics->getMassBalanceSummary($companyId);
        $recentActivity      = $this->companyAnalytics->getRecentActivity($companyId, 8);
        $exportSummary       = $this->companyAnalytics->getExportSummary($companyId);
        $warehouseCapacity   = $this->companyAnalytics->getWarehouseCapacity($companyId);

        $recentBatches = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn($b) => $this->formatBatchForDashboard($b));

        return [
            'page_context'         => 'admin',
            'page_title'           => 'Dashboard Operasional',
            'page_subtitle'        => 'Ringkasan lengkap aktivitas produksi',
            'kpi_cards'            => $kpiCards,
            'stage_distribution'   => $stageDistribution,
            'production_trend'     => $productionTrend,
            'mass_balance_summary' => $massBalanceSummary,
            'recent_activity'      => $recentActivity,
            'export_summary'       => $exportSummary,
            'warehouse_capacity'   => $warehouseCapacity,
            'recent_batches'       => $recentBatches,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPANY AUDITOR — read-only overview
    // ─────────────────────────────────────────────────────────────────────────

    private function getAuditorDashboard(int $companyId): array
    {
        return [
            'page_context'    => 'auditor',
            'page_title'      => 'Dashboard Audit',
            'page_subtitle'   => 'Pantau seluruh aktivitas produksi (baca saja)',
            'stage_summary'   => $this->companyAnalytics->getStageDistribution($companyId),
            'export_summary'  => $this->companyAnalytics->getExportSummary($companyId),
            'mass_balance'    => $this->companyAnalytics->getMassBalanceSummary($companyId),
            'recent_batches'  => Batch::with(['productCode','currentLocation'])
                ->where('company_id', $companyId)
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn($b) => $this->formatBatchForDashboard($b)),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BIM DASHBOARD
    // ─────────────────────────────────────────────────────────────────────────

    public function bim(): Response
    {
        $overview        = $this->bimAnalytics->getSystemOverview();
        $companyStatuses = $this->bimAnalytics->getCompanyStatuses();
        $combinedTrend   = $this->bimAnalytics->getCombinedProductionTrend(30);
        $exportMonitor   = $this->bimAnalytics->getExportMonitor(10);
        $alertMonitor    = $this->bimAnalytics->getAlertMonitor();

        return Inertia::render('BIM/Dashboard', [
            'overview'         => $overview,
            'company_statuses' => $companyStatuses,
            'combined_trend'   => $combinedTrend,
            'export_monitor'   => $exportMonitor,
            'alert_monitor'    => $alertMonitor,
            'current_time'     => Carbon::now('Asia/Jakarta')->format('l, d F Y — H:i:s'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUPER ADMIN DASHBOARD
    // ─────────────────────────────────────────────────────────────────────────

    public function superAdmin(): Response
    {
        $systemStats = [
            'total_companies' => Company::where('is_regulator', false)->count(),
            'total_users'     => User::count(),
            'total_batches'   => Batch::count(),
            'total_exports'   => ExportLog::count(),
            'total_labs'      => LabAnalysis::count(),
            'active_alerts'   => Batch::where('has_discrepancy', true)->count(),
        ];

        $companies = Company::where('is_regulator', false)
            ->withCount(['users'])
            ->get()
            ->map(fn($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'code'        => $c->code,
                'industry'    => $c->industry,
                'is_active'   => $c->is_active,
                'users_count' => $c->users_count,
                'batches'     => Batch::where('company_id', $c->id)->count(),
                'color'       => $c->primary_color,
            ]);

        $recentUsers = User::with('company')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn($u) => [
                'id'      => $u->id,
                'name'    => $u->name,
                'email'   => $u->email,
                'role'    => $u->getRoleNames()->first(),
                'company' => $u->company?->name,
                'joined'  => $u->created_at?->format('d/m/Y H:i') . ' WIB',
                'joined_diff' => $u->created_at?->diffForHumans(),
            ]);

        return Inertia::render('SuperAdmin/Dashboard', [
            'system_stats' => $systemStats,
            'companies'    => $companies,
            'recent_users' => $recentUsers,
            'current_time' => Carbon::now('Asia/Jakarta')->format('l, d F Y — H:i:s'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Format batch untuk tampilan dashboard dengan timestamp detail.
     */
    private function formatBatchForDashboard(Batch $batch): array
    {
        return [
            'id'              => $batch->id,
            'batch_code'      => $batch->batch_code,
            'lot_number'      => $batch->lot_number,
            'product'         => $batch->productCode ? [
                'id'               => $batch->productCode->id,
                'code'             => $batch->productCode->code,
                'name'             => $batch->productCode->name,
                'is_hazardous'     => $batch->productCode->is_hazardous,
                'is_export_blocked'=> $batch->productCode->is_export_blocked,
                'radioactive_level'=> $batch->productCode->radioactive_level,
            ] : null,
            'process_stage'   => $batch->process_stage,
            'status'          => $batch->status,
            'initial_weight'  => $batch->initial_weight,
            'current_weight'  => $batch->current_weight,
            'mass_loss_pct'   => $batch->mass_loss_pct,
            'has_discrepancy' => $batch->has_discrepancy,
            'location'        => $batch->currentLocation ? [
                'id'   => $batch->currentLocation->id,
                'code' => $batch->currentLocation->code,
                'name' => $batch->currentLocation->name,
            ] : null,
            'export_status'   => $batch->export_status,
            'rfid_tag'        => $batch->rfid_tag,
            // ─── Timestamp detail ──────────────────────────────────────────
            'created_at'      => $batch->created_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            'created_at_date' => $batch->created_at?->timezone('Asia/Jakarta')->format('l, d F Y'),
            'created_at_time' => $batch->created_at?->timezone('Asia/Jakarta')->format('H:i:s') . ' WIB',
            'created_at_diff' => $batch->created_at?->diffForHumans(),
            'updated_at'      => $batch->updated_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            'updated_at_diff' => $batch->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Format activity dari batch.
     */
    private function formatBatchActivity(Batch $batch): array
    {
        return [
            'id'         => $batch->id,
            'batch_code' => $batch->batch_code,
            'product'    => $batch->productCode?->code,
            'weight_kg'  => $batch->initial_weight,
            'stage'      => $batch->process_stage,
            'status'     => $batch->status,
            'created_at' => $batch->created_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            'created_at_diff' => $batch->created_at?->diffForHumans(),
        ];
    }

    /**
     * Format checkpoint activity.
     */
    private function formatCheckpointActivity(Checkpoint $cp): array
    {
        return [
            'id'               => $cp->id,
            'batch_code'       => $cp->batch?->batch_code,
            'batch_id'         => $cp->batch_id,
            'product'          => $cp->batch?->productCode?->code,
            'stage'            => $cp->checkpoint_stage,
            'weight_recorded'  => $cp->weight_recorded,
            'weight_loss_pct'  => $cp->weight_loss_pct,
            'has_discrepancy'  => $cp->has_discrepancy,
            'is_verified'      => $cp->is_verified,
            'location'         => $cp->location?->name,
            'recorded_by'      => $cp->recordedByUser?->name,
            'recorded_at'      => $cp->recorded_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB',
            'recorded_at_diff' => $cp->recorded_at?->diffForHumans(),
        ];
    }
}