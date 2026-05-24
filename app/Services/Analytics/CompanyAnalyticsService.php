<?php

namespace App\Services\Analytics;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\ExportLog;
use App\Models\LabAnalysis;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Models\ProductCode;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * CompanyAnalyticsService — kalkulasi semua KPI dan analytics per company.
 *
 * Arsitektur fleksibel: setiap method menerima $companyId sebagai parameter,
 * sehingga mudah digunakan untuk company manapun tanpa perubahan kode.
 * Saat PT Vale atau company baru onboard, service ini langsung bisa dipakai.
 */
class CompanyAnalyticsService
{
    /**
     * Ambil semua KPI utama untuk header cards dashboard.
     * Single query per metric untuk performa optimal.
     */
    public function getKpiCards(int $companyId): array
    {
        $today     = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // ─── Active batches ────────────────────────────────────────────────
        $activeBatches = Batch::where('company_id', $companyId)
            ->whereIn('status', ['active', 'in_process', 'in_transit'])
            ->count();

        $activeBatchesLastMonth = Batch::where('company_id', $companyId)
            ->whereIn('status', ['active', 'in_process', 'in_transit'])
            ->whereMonth('created_at', $lastMonth->month)
            ->count();

        // ─── Total tonase aktif ────────────────────────────────────────────
        $totalWeightKg = Batch::where('company_id', $companyId)
            ->whereIn('status', ['active', 'in_process', 'in_transit', 'in_warehouse'])
            ->sum('current_weight');

        $totalWeightLastMonth = Batch::where('company_id', $companyId)
            ->whereIn('status', ['active', 'in_process', 'in_transit', 'in_warehouse'])
            ->where('created_at', '<', $thisMonth)
            ->sum('current_weight');

        // ─── Export bulan ini ──────────────────────────────────────────────
        $exportThisMonth = ExportLog::where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->where('exported_at', '>=', $thisMonth)
            ->count();

        $exportLastMonth = ExportLog::where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->whereBetween('exported_at', [$lastMonth, $lastMonthEnd])
            ->count();

        // ─── Active alerts ────────────────────────────────────────────────
        $activeAlerts = Batch::where('company_id', $companyId)
            ->where('has_discrepancy', true)
            ->whereIn('status', ['active', 'in_process', 'in_transit', 'in_warehouse'])
            ->count();

        // ─── Batch dibuat hari ini ─────────────────────────────────────────
        $batchesToday = Batch::where('company_id', $companyId)
            ->whereDate('created_at', $today)
            ->count();

        // ─── Helper hitung % perubahan ─────────────────────────────────────
        $calcChange = function($current, $previous) {
            if ($previous == 0) return $current > 0 ? 100 : 0;
            return round((($current - $previous) / $previous) * 100, 1);
        };

        return [
            'active_batches' => [
                'value'   => $activeBatches,
                'label'   => 'Batch Aktif',
                'label_en'=> 'Active Batches',
                'change'  => $calcChange($activeBatches, $activeBatchesLastMonth),
                'icon'    => 'cube',
                'color'   => 'blue',
                'today'   => $batchesToday,
            ],
            'total_tonnage' => [
                'value'    => $totalWeightKg,
                'label'    => 'Total Tonase Aktif',
                'label_en' => 'Active Tonnage',
                'change'   => $calcChange($totalWeightKg, $totalWeightLastMonth),
                'icon'     => 'scale',
                'color'    => 'amber',
                'unit'     => 'kg',
            ],
            'exports_this_month' => [
                'value'   => $exportThisMonth,
                'label'   => 'Ekspor Bulan Ini',
                'label_en'=> 'Exports This Month',
                'change'  => $calcChange($exportThisMonth, $exportLastMonth),
                'icon'    => 'ship',
                'color'   => 'emerald',
            ],
            'active_alerts' => [
                'value'   => $activeAlerts,
                'label'   => 'Alert Aktif',
                'label_en'=> 'Active Alerts',
                'change'  => 0,
                'icon'    => 'alert',
                'color'   => $activeAlerts > 0 ? 'red' : 'green',
            ],
        ];
    }

    /**
     * Distribusi batch per stage saat ini (untuk workflow progress bar).
     */
    public function getStageDistribution(int $companyId): array
    {
        $stages = [
            'lab_kimia'     => ['label' => 'Lab Kimia',     'label_en' => 'Chemical Lab',   'color' => '#7c3aed'],
            'receiving'     => ['label' => 'Receiving',     'label_en' => 'Receiving',       'color' => '#2563eb'],
            'wet_process'   => ['label' => 'Wet Process',   'label_en' => 'Wet Process',     'color' => '#0891b2'],
            'dry_process'   => ['label' => 'Dry Process',   'label_en' => 'Dry Process',     'color' => '#ea580c'],
            'project_plant' => ['label' => 'Project Plant', 'label_en' => 'Project Plant',   'color' => '#059669'],
            'warehouse'     => ['label' => 'Warehouse',     'label_en' => 'Warehouse',       'color' => '#3E5C74'],
        ];

        $counts = Batch::where('company_id', $companyId)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed', 'split'])
            ->whereIn('process_stage', array_keys($stages))
            ->select('process_stage', DB::raw('count(*) as total'), DB::raw('sum(current_weight) as total_weight'))
            ->groupBy('process_stage')
            ->get()
            ->keyBy('process_stage');

        $total = $counts->sum('total') ?: 1;

        return collect($stages)->map(function($stage, $key) use ($counts, $total) {
            $data = $counts->get($key);
            return [
                'stage'       => $key,
                'label'       => $stage['label'],
                'label_en'    => $stage['label_en'],
                'color'       => $stage['color'],
                'count'       => (int)($data?->total ?? 0),
                'weight_kg'   => (float)($data?->total_weight ?? 0),
                'percentage'  => round((($data?->total ?? 0) / $total) * 100, 1),
            ];
        })->values()->toArray();
    }

    /**
     * Tren produksi batch per hari (30 hari terakhir) untuk line chart.
     */
    public function getProductionTrend(int $companyId, int $days = 30): array
    {
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $data = Batch::where('company_id', $companyId)
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as batch_count'),
                DB::raw('SUM(initial_weight) as total_weight')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::now()->subDays($days - 1 - $i)->format('Y-m-d');
            $row  = $data->get($date);
            $trend[] = [
                'date'         => $date,
                'label'        => Carbon::parse($date)->format('d M'),
                'batch_count'  => (int)($row?->batch_count ?? 0),
                'total_weight' => round((float)($row?->total_weight ?? 0), 1),
            ];
        }

        return $trend;
    }

    /**
     * Distribusi berat per product code (untuk pie/donut chart).
     */
    public function getProductDistribution(int $companyId): array
    {
        return Batch::join('product_codes', 'batches.product_code_id', '=', 'product_codes.id')
            ->where('batches.company_id', $companyId)
            ->whereIn('batches.status', ['active', 'in_process', 'in_warehouse', 'in_transit'])
            ->select(
                'product_codes.code',
                'product_codes.name',
                DB::raw('COUNT(batches.id) as batch_count'),
                DB::raw('SUM(batches.current_weight) as total_weight')
            )
            ->groupBy('product_codes.id', 'product_codes.code', 'product_codes.name')
            ->orderByDesc('total_weight')
            ->limit(8)
            ->get()
            ->map(fn($row) => [
                'code'         => $row->code,
                'name'         => $row->name,
                'batch_count'  => (int)$row->batch_count,
                'total_weight' => round((float)$row->total_weight, 1),
            ])
            ->toArray();
    }

    /**
     * Mass balance summary per stage — untuk alert overview.
     */
    public function getMassBalanceSummary(int $companyId): array
    {
        $thresholds = MassBalanceThreshold::where('company_id', $companyId)
            ->get()
            ->keyBy('process_stage');

        $discrepancies = Batch::where('company_id', $companyId)
            ->where('has_discrepancy', true)
            ->whereIn('status', ['active', 'in_process', 'in_warehouse', 'in_transit'])
            ->select('process_stage', DB::raw('count(*) as count'), DB::raw('avg(mass_loss_pct) as avg_loss'))
            ->groupBy('process_stage')
            ->get();

        return $discrepancies->map(fn($row) => [
            'stage'       => $row->process_stage,
            'count'       => (int)$row->count,
            'avg_loss_pct'=> round((float)$row->avg_loss, 2),
            'threshold'   => $thresholds->get($row->process_stage)?->warning_threshold_pct,
        ])->toArray();
    }

    /**
     * Aktivitas terbaru (recent checkpoints + batch events).
     */
    public function getRecentActivity(int $companyId, int $limit = 10): array
    {
        $checkpoints = Checkpoint::with(['batch.productCode', 'recordedByUser', 'location'])
            ->whereHas('batch', fn($q) => $q->where('company_id', $companyId))
            ->latest('recorded_at')
            ->limit($limit)
            ->get()
            ->map(fn($cp) => [
                'type'       => 'checkpoint',
                'title'      => $cp->stage_label ?? str_replace('_', ' ', $cp->checkpoint_stage),
                'batch_code' => $cp->batch?->batch_code,
                'product'    => $cp->batch?->productCode?->code,
                'location'   => $cp->location?->name,
                'user'       => $cp->recordedByUser?->name ?? 'System',
                'weight'     => $cp->weight_recorded,
                'has_alert'  => $cp->has_discrepancy,
                'timestamp'  => $cp->recorded_at?->toIso8601String(),
                'time_diff'  => $cp->recorded_at?->diffForHumans(),
            ]);

        return $checkpoints->toArray();
    }

    /**
     * Export summary bulan ini.
     */
    public function getExportSummary(int $companyId): array
    {
        $thisMonth = Carbon::now()->startOfMonth();

        $exports = ExportLog::where('company_id', $companyId)
            ->where('exported_at', '>=', $thisMonth)
            ->select(
                'export_type',
                DB::raw('count(*) as count'),
                DB::raw('sum(total_weight_kg) as total_weight'),
                DB::raw('sum(value_usd) as total_value')
            )
            ->groupBy('export_type')
            ->get();

        return [
            'international' => [
                'count'        => (int)($exports->firstWhere('export_type', 'international')?->count ?? 0),
                'weight_kg'    => (float)($exports->firstWhere('export_type', 'international')?->total_weight ?? 0),
                'value_usd'    => (float)($exports->firstWhere('export_type', 'international')?->total_value ?? 0),
            ],
            'domestic' => [
                'count'        => (int)($exports->firstWhere('export_type', 'domestic')?->count ?? 0),
                'weight_kg'    => (float)($exports->firstWhere('export_type', 'domestic')?->total_weight ?? 0),
                'value_usd'    => (float)($exports->firstWhere('export_type', 'domestic')?->total_value ?? 0),
            ],
        ];
    }

    /**
     * Warehouse capacity — hanya untuk lokasi tipe warehouse.
     */
    public function getWarehouseCapacity(int $companyId): array
    {
        $warehouses = Location::where('company_id', $companyId)
            ->where('location_type', 'warehouse')
            ->where('is_active', true)
            ->whereNotNull('max_capacity_kg')
            ->get();

        return $warehouses->map(function($wh) use ($companyId) {
            $currentLoad = Batch::where('company_id', $companyId)
                ->where('current_location_id', $wh->id)
                ->where('status', 'in_warehouse')
                ->sum('current_weight');

            $capacityPct = $wh->max_capacity_kg > 0
                ? round(($currentLoad / $wh->max_capacity_kg) * 100, 1)
                : 0;

            return [
                'location_id'     => $wh->id,
                'code'            => $wh->code,
                'name'            => $wh->name,
                'current_kg'      => (float)$currentLoad,
                'max_kg'          => (float)$wh->max_capacity_kg,
                'capacity_pct'    => $capacityPct,
                'warning_pct'     => (float)$wh->capacity_warning_pct,
                'is_warning'      => $capacityPct >= $wh->capacity_warning_pct,
                'is_critical'     => $capacityPct >= 95,
            ];
        })->toArray();
    }
}