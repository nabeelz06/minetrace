<?php

namespace App\Services\Analytics;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * BimAnalyticsService — analytics lintas-company untuk regulator BIM.
 *
 * Arsitektur fleksibel: saat company baru (PT Vale, MIND ID) onboard,
 * semua stats ini langsung mencakup mereka tanpa perubahan kode.
 */
class BimAnalyticsService
{
    /**
     * Overview semua company yang aktif.
     */
    public function getSystemOverview(): array
    {
        $totalCompanies  = Company::where('is_active', true)->where('is_regulator', false)->count();
        $totalBatches    = Batch::count();
        $activeBatches   = Batch::whereIn('status', ['active', 'in_process', 'in_transit'])->count();
        $totalExports    = ExportLog::where('status', 'confirmed')->count();
        $activeAlerts    = Batch::where('has_discrepancy', true)->whereIn('status', ['active', 'in_process'])->count();
        $totalWeightKg   = Batch::whereIn('status', ['active', 'in_process', 'in_transit', 'in_warehouse'])->sum('current_weight');

        return [
            'total_companies'   => $totalCompanies,
            'total_batches'     => $totalBatches,
            'active_batches'    => $activeBatches,
            'total_exports'     => $totalExports,
            'active_alerts'     => $activeAlerts,
            'total_weight_kg'   => (float)$totalWeightKg,
        ];
    }

    /**
     * Status per company — card summary untuk BIM dashboard.
     */
    public function getCompanyStatuses(): array
    {
        $companies = Company::where('is_active', true)
            ->where('is_regulator', false)
            ->get();

        return $companies->map(function($company) {
            $activeBatches = Batch::where('company_id', $company->id)
                ->whereIn('status', ['active', 'in_process', 'in_transit', 'in_warehouse'])
                ->count();

            $alerts = Batch::where('company_id', $company->id)
                ->where('has_discrepancy', true)
                ->whereIn('status', ['active', 'in_process'])
                ->count();

            $lastActivity = Batch::where('company_id', $company->id)
                ->latest()
                ->first();

            $exportsThisMonth = ExportLog::where('company_id', $company->id)
                ->where('exported_at', '>=', Carbon::now()->startOfMonth())
                ->count();

            $totalWeight = Batch::where('company_id', $company->id)
                ->whereIn('status', ['active', 'in_process', 'in_transit', 'in_warehouse'])
                ->sum('current_weight');

            return [
                'id'               => $company->id,
                'name'             => $company->name,
                'code'             => $company->code,
                'industry'         => $company->industry,
                'primary_color'    => $company->primary_color,
                'is_active'        => $company->is_active,
                'active_batches'   => $activeBatches,
                'alerts'           => $alerts,
                'exports_month'    => $exportsThisMonth,
                'total_weight_kg'  => (float)$totalWeight,
                'last_activity'    => $lastActivity?->updated_at?->diffForHumans(),
                'onboarded_at'     => $company->onboarded_at?->format('d M Y'),
            ];
        })->toArray();
    }

    /**
     * Tren produksi gabungan semua company (30 hari).
     */
    public function getCombinedProductionTrend(int $days = 30): array
    {
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $companies = Company::where('is_active', true)
            ->where('is_regulator', false)
            ->pluck('code', 'id');

        $data = Batch::where('created_at', '>=', $start)
            ->select(
                DB::raw('DATE(created_at) as date'),
                'company_id',
                DB::raw('COUNT(*) as batch_count'),
                DB::raw('SUM(initial_weight) as total_weight')
            )
            ->groupBy('date', 'company_id')
            ->orderBy('date')
            ->get()
            ->groupBy('date');

        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $date   = Carbon::now()->subDays($days - 1 - $i)->format('Y-m-d');
            $dayData = $data->get($date) ?? collect();

            $point  = [
                'date'  => $date,
                'label' => Carbon::parse($date)->format('d M'),
                'total' => (int)$dayData->sum('batch_count'),
            ];

            foreach ($companies as $id => $code) {
                $row = $dayData->firstWhere('company_id', $id);
                $point[strtolower($code)] = (int)($row?->batch_count ?? 0);
            }

            $trend[] = $point;
        }

        return $trend;
    }

    /**
     * Export monitor — semua ekspor lintas company.
     */
    public function getExportMonitor(int $limit = 20): array
    {
        return ExportLog::with(['company', 'createdByUser'])
            ->where('status', 'confirmed')
            ->latest('exported_at')
            ->limit($limit)
            ->get()
            ->map(fn($exp) => [
                'id'            => $exp->id,
                'company'       => $exp->company?->name,
                'company_code'  => $exp->company?->code,
                'type'          => $exp->export_type,
                'destination'   => $exp->destination_company,
                'country'       => $exp->destination_country,
                'weight_kg'     => (float)$exp->total_weight_kg,
                'value_usd'     => (float)($exp->value_usd ?? 0),
                'batch_count'   => count($exp->batch_ids ?? []),
                'exported_at'   => $exp->exported_at?->format('d M Y'),
                'time_diff'     => $exp->exported_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Alert monitor lintas company.
     */
    public function getAlertMonitor(): array
    {
        return Batch::with(['company', 'productCode', 'currentLocation'])
            ->where('has_discrepancy', true)
            ->whereIn('status', ['active', 'in_process', 'in_transit'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn($batch) => [
                'id'          => $batch->id,
                'batch_code'  => $batch->batch_code,
                'company'     => $batch->company?->name,
                'product'     => $batch->productCode?->code,
                'stage'       => $batch->process_stage,
                'loss_pct'    => $batch->mass_loss_pct,
                'weight_kg'   => $batch->current_weight,
                'location'    => $batch->currentLocation?->name,
                'updated_at'  => $batch->updated_at?->diffForHumans(),
            ])
            ->toArray();
    }
}