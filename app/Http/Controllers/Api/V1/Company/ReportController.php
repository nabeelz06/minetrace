<?php

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\ExportLog;
use App\Models\LabAnalysis;
use App\Models\Location;
use App\Models\ProductCode;
use App\Services\Analytics\CompanyAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

/**
 * ReportController — generate laporan dalam format PDF dan Excel.
 *
 * Laporan yang tersedia:
 * 1. Batch Traceability Report — semua batch dalam periode
 * 2. Mass Balance Report — loss per stage
 * 3. Export Summary Report — semua ekspor dalam periode
 * 4. Lab Analysis Report — hasil lab dalam periode
 * 5. Monthly Summary — digunakan juga oleh scheduler
 */
class ReportController extends Controller
{
    public function __construct(
        private CompanyAnalyticsService $analytics
    ) {}

    /**
     * Halaman Reports — form pilih jenis dan periode laporan.
     */
    public function index(): Response
    {
        $companyId = Auth::user()->company_id;

        return Inertia::render('Company/Reports/Index', [
            'available_reports' => [
                [
                    'id'          => 'batch_traceability',
                    'name'        => 'Laporan Traceability Batch',
                    'name_en'     => 'Batch Traceability Report',
                    'description' => 'Seluruh perjalanan batch dari Lab Kimia hingga ekspor/smelter',
                    'icon'        => '📦',
                    'formats'     => ['excel', 'pdf'],
                ],
                [
                    'id'          => 'mass_balance',
                    'name'        => 'Laporan Mass Balance',
                    'name_en'     => 'Mass Balance Report',
                    'description' => 'Analisis susut material per tahap proses',
                    'icon'        => '⚖️',
                    'formats'     => ['excel', 'pdf'],
                ],
                [
                    'id'          => 'export_summary',
                    'name'        => 'Laporan Ringkasan Ekspor',
                    'name_en'     => 'Export Summary Report',
                    'description' => 'Semua ekspor internasional dan domestik dalam periode',
                    'icon'        => '🚢',
                    'formats'     => ['excel', 'pdf'],
                ],
                [
                    'id'          => 'lab_analysis',
                    'name'        => 'Laporan Analisis Lab LTJ',
                    'name_en'     => 'LTJ Lab Analysis Report',
                    'description' => 'Hasil analisis kimia LTJ (La, Ce, Pr, Nd, Y) dalam periode',
                    'icon'        => '🔬',
                    'formats'     => ['excel', 'pdf'],
                ],
                [
                    'id'          => 'monthly_summary',
                    'name'        => 'Laporan Bulanan Lengkap',
                    'name_en'     => 'Monthly Summary Report',
                    'description' => 'Ringkasan komprehensif semua aktivitas dalam 1 bulan',
                    'icon'        => '📊',
                    'formats'     => ['pdf'],
                ],
            ],
            'recent_months' => $this->getRecentMonths(6),
        ]);
    }

    /**
     * Generate dan download laporan.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'report_type' => 'required|in:batch_traceability,mass_balance,export_summary,lab_analysis,monthly_summary',
            'format'      => 'required|in:excel,pdf',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
        ]);

        $companyId = Auth::user()->company_id;
        $company   = Auth::user()->company;
        $dateFrom  = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo    = Carbon::parse($validated['date_to'])->endOfDay();

        return match($validated['report_type']) {
            'batch_traceability' => $this->generateBatchTraceabilityReport($company, $dateFrom, $dateTo, $validated['format']),
            'mass_balance'       => $this->generateMassBalanceReport($company, $dateFrom, $dateTo, $validated['format']),
            'export_summary'     => $this->generateExportSummaryReport($company, $dateFrom, $dateTo, $validated['format']),
            'lab_analysis'       => $this->generateLabAnalysisReport($company, $dateFrom, $dateTo, $validated['format']),
            'monthly_summary'    => $this->generateMonthlySummaryReport($company, $dateFrom, $validated['format']),
            default              => back()->withErrors(['report_type' => 'Jenis laporan tidak valid.']),
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // BATCH TRACEABILITY REPORT
    // ─────────────────────────────────────────────────────────────────────

    private function generateBatchTraceabilityReport($company, Carbon $from, Carbon $to, string $format)
    {
        $batches = Batch::with(['productCode', 'currentLocation', 'checkpoints', 'labAnalyses'])
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $filename = "BatchTraceability-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return Excel::download(
                new \App\Exports\BatchTraceabilityExport($batches, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.batch-traceability', [
            'batches'     => $batches,
            'company'     => $company,
            'date_from'   => $from->format('d F Y'),
            'date_to'     => $to->format('d F Y'),
            'generated_at'=> now()->format('d M Y H:i') . ' WIB',
        ])->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    // ─────────────────────────────────────────────────────────────────────
    // MASS BALANCE REPORT
    // ─────────────────────────────────────────────────────────────────────

    private function generateMassBalanceReport($company, Carbon $from, Carbon $to, string $format)
    {
        $stages = ['lab_kimia','receiving','wet_process','dry_process','project_plant','warehouse'];

        $data = collect($stages)->map(function($stage) use ($company, $from, $to) {
            $batches = Batch::where('company_id', $company->id)
                ->where('process_stage', $stage)
                ->whereBetween('updated_at', [$from, $to])
                ->get();

            return [
                'stage'            => $stage,
                'total_batches'    => $batches->count(),
                'total_input_kg'   => round($batches->sum('initial_weight'), 2),
                'total_current_kg' => round($batches->sum('current_weight'), 2),
                'avg_loss_pct'     => round($batches->avg('mass_loss_pct') ?? 0, 2),
                'alert_count'      => $batches->where('has_discrepancy', true)->count(),
            ];
        });

        $filename = "MassBalance-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return Excel::download(
                new \App\Exports\MassBalanceExport($data, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.mass-balance', [
            'data'        => $data,
            'company'     => $company,
            'date_from'   => $from->format('d F Y'),
            'date_to'     => $to->format('d F Y'),
            'generated_at'=> now()->format('d M Y H:i') . ' WIB',
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    // ─────────────────────────────────────────────────────────────────────
    // EXPORT SUMMARY REPORT
    // ─────────────────────────────────────────────────────────────────────

    private function generateExportSummaryReport($company, Carbon $from, Carbon $to, string $format)
    {
        $exports = ExportLog::where('company_id', $company->id)
            ->whereBetween('exported_at', [$from, $to])
            ->where('status', 'confirmed')
            ->with('createdByUser')
            ->orderBy('exported_at')
            ->get();

        $filename = "ExportSummary-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return Excel::download(
                new \App\Exports\ExportSummaryExport($exports, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.export-summary', [
            'exports'     => $exports,
            'company'     => $company,
            'date_from'   => $from->format('d F Y'),
            'date_to'     => $to->format('d F Y'),
            'generated_at'=> now()->format('d M Y H:i') . ' WIB',
            'totals'      => [
                'count'         => $exports->count(),
                'weight_kg'     => round($exports->sum('total_weight_kg'), 2),
                'value_usd'     => round($exports->sum('value_usd'), 2),
                'international' => $exports->where('export_type', 'international')->count(),
                'domestic'      => $exports->where('export_type', 'domestic')->count(),
            ],
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    // ─────────────────────────────────────────────────────────────────────
    // LAB ANALYSIS REPORT
    // ─────────────────────────────────────────────────────────────────────

    private function generateLabAnalysisReport($company, Carbon $from, Carbon $to, string $format)
    {
        $analyses = LabAnalysis::where('company_id', $company->id)
            ->whereBetween('analyzed_at', [$from, $to])
            ->with(['batch.productCode', 'analyzedByUser'])
            ->orderBy('analyzed_at')
            ->get();

        $filename = "LabAnalysis-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return Excel::download(
                new \App\Exports\LabAnalysisExport($analyses, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.lab-analysis', [
            'analyses'    => $analyses,
            'company'     => $company,
            'date_from'   => $from->format('d F Y'),
            'date_to'     => $to->format('d F Y'),
            'generated_at'=> now()->format('d M Y H:i') . ' WIB',
            'stats'       => [
                'total'       => $analyses->count(),
                'validated'   => $analyses->where('is_validated', true)->count(),
                'avg_reo'     => round($analyses->avg('total_reo_pct') ?? 0, 2),
                'avg_la'      => round($analyses->avg('la_pct') ?? 0, 3),
                'avg_ce'      => round($analyses->avg('ce_pct') ?? 0, 3),
                'avg_nd'      => round($analyses->avg('nd_pct') ?? 0, 3),
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    private function generateMonthlySummaryReport($company, Carbon $from, string $format)
    {
        $monthStart = $from->copy()->startOfMonth();
        $monthEnd   = $from->copy()->endOfMonth();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.monthly', [
            'company'      => $company,
            'month_label'  => $monthStart->translatedFormat('F Y'),
            'generated_at' => now()->format('d M Y H:i') . ' WIB',
            'batch_summary' => [
                'total_created'   => Batch::where('company_id', $company->id)->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
                'total_weight_kg' => Batch::where('company_id', $company->id)->whereBetween('created_at', [$monthStart, $monthEnd])->sum('initial_weight'),
                'by_product'      => [],
                'by_stage'        => [],
            ],
            'export_summary' => [
                'total'         => ExportLog::where('company_id', $company->id)->whereBetween('exported_at', [$monthStart, $monthEnd])->count(),
                'international' => 0, 'domestic' => 0,
                'total_weight'  => ExportLog::where('company_id', $company->id)->whereBetween('exported_at', [$monthStart, $monthEnd])->sum('total_weight_kg'),
                'total_value'   => ExportLog::where('company_id', $company->id)->whereBetween('exported_at', [$monthStart, $monthEnd])->sum('value_usd'),
            ],
            'lab_summary' => [
                'total'       => LabAnalysis::where('company_id', $company->id)->whereBetween('analyzed_at', [$monthStart, $monthEnd])->count(),
                'validated'   => LabAnalysis::where('company_id', $company->id)->whereBetween('analyzed_at', [$monthStart, $monthEnd])->where('is_validated', true)->count(),
                'avg_reo_pct' => round(LabAnalysis::where('company_id', $company->id)->whereBetween('analyzed_at', [$monthStart, $monthEnd])->avg('total_reo_pct') ?? 0, 2),
            ],
            'alerts' => [],
        ])->setPaper('a4', 'portrait');

        return $pdf->download("Monthly-{$company->code}-{$monthStart->format('Y-m')}.pdf");
    }

    private function getRecentMonths(int $count): array
    {
        $months = [];
        for ($i = 0; $i < $count; $i++) {
            $month    = Carbon::now()->subMonths($i);
            $months[] = [
                'value' => $month->format('Y-m'),
                'label' => $month->translatedFormat('F Y'),
            ];
        }
        return $months;
    }
}