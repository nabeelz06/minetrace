<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\ExportLog;
use App\Models\LabAnalysis;
use App\Services\Analytics\CompanyAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

/**
 * ReportController — generate dan download laporan company.
 * Namespace: App\Http\Controllers\Company (bukan Api/V1)
 *
 * Laporan tersedia:
 *   1. Batch Traceability (Excel + PDF)
 *   2. Mass Balance (Excel + PDF)
 *   3. Export Summary (Excel + PDF)
 *   4. Lab Analysis LTJ (Excel + PDF)
 *   5. Monthly Summary (PDF)
 */
class ReportController extends Controller
{
    public function __construct(
        private CompanyAnalyticsService $analytics
    ) {}

    /**
     * Halaman index laporan — pilih jenis dan periode.
     */
    public function index(): Response
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'company_auditor']),
            403, 'Akses ditolak.'
        );

        $companyId = Auth::user()->company_id;

        // Statistik cepat untuk semua report types
        $quickStats = [
            'total_batches' => Batch::where('company_id', $companyId)->count(),
            'total_exports' => ExportLog::where('company_id', $companyId)->count(),
            'total_labs'    => LabAnalysis::where('company_id', $companyId)->count(),
            'this_month_batches' => Batch::where('company_id', $companyId)
                ->whereMonth('created_at', now()->month)
                ->count(),
        ];

        return Inertia::render('Company/Reports/Index', [
            'available_reports' => $this->getAvailableReports(),
            'recent_months'     => $this->getRecentMonths(6),
            'quick_stats'       => $quickStats,
        ]);
    }

    /**
     * Generate dan download laporan.
     * Response adalah file download langsung (bukan Inertia redirect).
     */
    public function generate(Request $request)
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'company_auditor']),
            403
        );

        $validated = $request->validate([
            'report_type' => 'required|in:batch_traceability,mass_balance,export_summary,lab_analysis,monthly_summary',
            'format'      => 'required|in:excel,pdf',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
        ], [
            'report_type.required' => 'Pilih jenis laporan terlebih dahulu.',
            'format.required'      => 'Pilih format laporan (Excel/PDF).',
            'date_from.required'   => 'Tanggal mulai wajib diisi.',
            'date_to.required'     => 'Tanggal selesai wajib diisi.',
            'date_to.after_or_equal' => 'Tanggal selesai harus sama atau sesudah tanggal mulai.',
        ]);

        $company  = Auth::user()->company;
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo   = Carbon::parse($validated['date_to'])->endOfDay();
        $format   = $validated['format'];

        // Validasi rentang tanggal tidak lebih dari 1 tahun
        if ($dateFrom->diffInDays($dateTo) > 366) {
            return back()->withErrors(['date_to' => 'Rentang laporan maksimal 1 tahun.']);
        }

        try {
            return match($validated['report_type']) {
                'batch_traceability' => $this->generateBatchReport($company, $dateFrom, $dateTo, $format),
                'mass_balance'       => $this->generateMassBalanceReport($company, $dateFrom, $dateTo, $format),
                'export_summary'     => $this->generateExportReport($company, $dateFrom, $dateTo, $format),
                'lab_analysis'       => $this->generateLabReport($company, $dateFrom, $dateTo, $format),
                'monthly_summary' => $this->generateMonthlyReport($company, $dateFrom, $dateTo, $format),
            };
        } catch (\Exception $e) {
            logger()->error('Report generation failed', [
                'type'    => $validated['report_type'],
                'company' => $company->code,
                'error'   => $e->getMessage(),
            ]);
            return back()->withErrors(['report_type' => 'Gagal generate laporan: ' . $e->getMessage()]);
        }
    }

    /**
     * Download laporan via GET request.
     * Route: GET /company/reports/download
     */
    public function download(Request $request)
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'company_auditor']),
            403
        );

        $validated = $request->validate([
            'report_type' => 'required|in:batch_traceability,mass_balance,export_summary,lab_analysis,monthly_summary',
            'format'      => 'required|in:excel,pdf',
            'date_from'   => 'required|date',
            'date_to'     => 'required|date|after_or_equal:date_from',
        ]);

        $company  = Auth::user()->company;
        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo   = Carbon::parse($validated['date_to'])->endOfDay();
        $format   = $validated['format'];

        if ($dateFrom->diffInDays($dateTo) > 366) {
            abort(422, 'Rentang laporan maksimal 1 tahun.');
        }

        try {
            return match($validated['report_type']) {
                'batch_traceability' => $this->generateBatchReport($company, $dateFrom, $dateTo, $format),
                'mass_balance'       => $this->generateMassBalanceReport($company, $dateFrom, $dateTo, $format),
                'export_summary'     => $this->generateExportReport($company, $dateFrom, $dateTo, $format),
                'lab_analysis'       => $this->generateLabReport($company, $dateFrom, $dateTo, $format),
                'monthly_summary' => $this->generateMonthlyReport($company, $dateFrom, $dateTo, $format),
            };
        } catch (\Exception $e) {
            logger()->error('Report download failed', [
                'type'    => $validated['report_type'],
                'company' => $company->code,
                'error'   => $e->getMessage(),
            ]);
            abort(500, 'Gagal generate laporan: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE: Report generators
    // ──────────────────────────────────────────────────────────────────────

    private function generateBatchReport($company, Carbon $from, Carbon $to, string $format)
    {
        $batches = Batch::with(['productCode', 'currentLocation', 'checkpoints.location', 'labAnalyses'])
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $filename = "BatchTraceability-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\BatchTraceabilityExport($batches, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.batch-traceability', [
            'batches'      => $batches,
            'company'      => $company,
            'date_from'    => $from->format('d F Y'),
            'date_to'      => $to->format('d F Y'),
            'generated_at' => now()->format('d M Y H:i') . ' WIB',
            'total_batches'=> $batches->count(),
            'total_weight' => $batches->sum('initial_weight'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    private function generateMassBalanceReport($company, Carbon $from, Carbon $to, string $format)
    {
        $stages = ['lab_kimia', 'receiving', 'wet_process', 'dry_process', 'project_plant', 'warehouse'];

        $data = collect($stages)->map(function ($stage) use ($company, $from, $to) {
            $batches = Batch::where('company_id', $company->id)
                ->where('process_stage', $stage)
                ->whereBetween('updated_at', [$from, $to])
                ->get();

            return [
                'stage'          => $stage,
                'stage_label'    => $this->stageLabel($stage),
                'total_batches'  => $batches->count(),
                'input_weight'   => round($batches->sum('initial_weight'), 2),
                'current_weight' => round($batches->sum('current_weight'), 2),
                'avg_loss_pct'   => round($batches->avg('mass_loss_pct') ?? 0, 2),
                'alert_count'    => $batches->where('has_discrepancy', true)->count(),
            ];
        });

        $filename = "MassBalance-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\MassBalanceExport($data, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.mass-balance', [
            'data'         => $data,
            'company'      => $company,
            'date_from'    => $from->format('d F Y'),
            'date_to'      => $to->format('d F Y'),
            'generated_at' => now()->format('d M Y H:i') . ' WIB',
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    private function generateExportReport($company, Carbon $from, Carbon $to, string $format)
    {
        $exports = ExportLog::where('company_id', $company->id)
            ->whereBetween('exported_at', [$from, $to])
            ->where('status', 'confirmed')
            ->with('createdByUser')
            ->orderBy('exported_at')
            ->get();

        $filename = "ExportSummary-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\ExportSummaryExport($exports, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.export-summary', [
            'exports'      => $exports,
            'company'      => $company,
            'date_from'    => $from->format('d F Y'),
            'date_to'      => $to->format('d F Y'),
            'generated_at' => now()->format('d M Y H:i') . ' WIB',
            'totals'       => [
                'count'         => $exports->count(),
                'weight_kg'     => round($exports->sum('total_weight_kg'), 2),
                'value_usd'     => round($exports->sum('value_usd'), 2),
                'international' => $exports->where('export_type', 'international')->count(),
                'domestic'      => $exports->where('export_type', 'domestic')->count(),
            ],
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    private function generateLabReport($company, Carbon $from, Carbon $to, string $format)
    {
        $analyses = LabAnalysis::where('company_id', $company->id)
            ->whereBetween('analyzed_at', [$from, $to])
            ->with(['batch.productCode', 'analyzedByUser'])
            ->orderBy('analyzed_at')
            ->get();

        $filename = "LabAnalysis-{$company->code}-{$from->format('Ymd')}-{$to->format('Ymd')}";

        if ($format === 'excel') {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\LabAnalysisExport($analyses, $company, $from, $to),
                "{$filename}.xlsx"
            );
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.lab-analysis', [
            'analyses'     => $analyses,
            'company'      => $company,
            'date_from'    => $from->format('d F Y'),
            'date_to'      => $to->format('d F Y'),
            'generated_at' => now()->format('d M Y H:i') . ' WIB',
            'stats'        => [
                'total'     => $analyses->count(),
                'validated' => $analyses->where('is_validated', true)->count(),
                'avg_reo'   => round($analyses->avg('total_reo_pct') ?? 0, 2),
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download("{$filename}.pdf");
    }

    private function generateMonthlyReport($company, Carbon $from, Carbon $to, string $format)
    {
        $tz = 'Asia/Jakarta';

        $start = $from->copy()->startOfMonth();
        $end   = $to->copy()->endOfMonth();

        // ── Batch summary dengan by_product dan by_stage ──────────────────────
        $monthBatches = Batch::with('productCode')
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $batchSummary = [
            'total_created'   => $monthBatches->count(),
            'total_weight_kg' => round($monthBatches->sum('initial_weight'), 2),
            'by_product'      => $monthBatches
                ->groupBy(fn($b) => $b->productCode?->code ?? '—')
                ->map(fn($grp, $code) => [
                    'code'   => $code,
                    'name'   => $grp->first()?->productCode?->name ?? $code,
                    'count'  => $grp->count(),
                    'weight' => round($grp->sum('initial_weight'), 2),
                ])
                ->sortByDesc('weight')
                ->values()
                ->toArray(),
            'by_stage'        => $monthBatches
                ->groupBy('process_stage')
                ->map(fn($grp, $stage) => [
                    'stage'       => $stage,
                    'stage_label' => $this->stageLabel($stage),
                    'count'       => $grp->count(),
                    'weight'      => round($grp->sum('initial_weight'), 2),
                ])
                ->sortByDesc('count')
                ->values()
                ->toArray(),
        ];

        // ── Export summary ─────────────────────────────────────────────────────
        $exportQuery = fn() => ExportLog::where('company_id', $company->id)
            ->whereBetween('exported_at', [$start, $end]);

        $exportSummary = [
            'total'         => $exportQuery()->count(),
            'international' => $exportQuery()->where('export_type', 'international')->count(),
            'domestic'      => $exportQuery()->where('export_type', 'domestic')->count(),
            'total_weight'  => round($exportQuery()->sum('total_weight_kg'), 2),
            'total_value'   => round($exportQuery()->sum('value_usd'), 2),
        ];

        // ── Lab summary ────────────────────────────────────────────────────────
        $labQuery = fn() => LabAnalysis::where('company_id', $company->id)
            ->whereBetween('analyzed_at', [$start, $end]);

        $labSummary = [
            'total'       => $labQuery()->count(),
            'validated'   => $labQuery()->where('is_validated', true)->count(),
            'avg_reo_pct' => round($labQuery()->where('is_validated', true)->avg('total_reo_pct') ?? 0, 2),
        ];

        // ── Mass balance alerts ────────────────────────────────────────────────
        $alerts = Batch::where('company_id', $company->id)
            ->where('has_discrepancy', true)
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw("process_stage, COUNT(*) as cnt, ROUND(AVG(mass_loss_pct)::numeric, 2) as avg_loss")
            ->groupBy('process_stage')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn($r) => [
                'process_stage' => $r->process_stage,
                'cnt'           => $r->cnt,
                'avg_loss'      => round($r->avg_loss, 2),
            ])
            ->toArray();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.reports.monthly', [
            'company'        => $company,
            'month_label'    => $start->locale('id')->translatedFormat('F Y') . ' — ' . $end->locale('id')->translatedFormat('d F Y'),
            'date_from'      => $start->format('d F Y'),
            'date_to'        => $end->format('d F Y'),
            'generated_at'   => now()->timezone($tz)->format('d M Y H:i') . ' WIB',
            'batch_summary'  => $batchSummary,
            'export_summary' => $exportSummary,
            'lab_summary'    => $labSummary,
            'alerts'         => $alerts,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("Monthly-{$company->code}-{$start->format('Y-m')}.pdf");
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE: Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function stageLabel(string $stage): string
    {
        return match($stage) {
            'lab_kimia'     => 'Lab Kimia',
            'receiving'     => 'Receiving Area',
            'wet_process'   => 'Wet Process',
            'dry_process'   => 'Dry Process',
            'project_plant' => 'Project Plant',
            'warehouse'     => 'LTJ Warehouse',
            default         => ucwords(str_replace('_', ' ', $stage)),
        };
    }

    private function getAvailableReports(): array
    {
        return [
            [
                'id'          => 'batch_traceability',
                'name'        => 'Laporan Traceability Batch',
                'name_en'     => 'Batch Traceability Report',
                'description' => 'Seluruh perjalanan batch dari Lab Kimia hingga ekspor/smelter, lengkap dengan checkpoint dan analisis lab',
                'icon'        => '📦',
                'formats'     => ['excel', 'pdf'],
                'color'       => 'blue',
            ],
            [
                'id'          => 'mass_balance',
                'name'        => 'Laporan Mass Balance',
                'name_en'     => 'Mass Balance Report',
                'description' => 'Analisis susut material per tahap proses — identifikasi kehilangan material yang tidak wajar',
                'icon'        => '⚖️',
                'formats'     => ['excel', 'pdf'],
                'color'       => 'amber',
            ],
            [
                'id'          => 'export_summary',
                'name'        => 'Laporan Ringkasan Ekspor',
                'name_en'     => 'Export Summary Report',
                'description' => 'Semua ekspor internasional dan penjualan domestik dalam periode tertentu',
                'icon'        => '🚢',
                'formats'     => ['excel', 'pdf'],
                'color'       => 'cyan',
            ],
            [
                'id'          => 'lab_analysis',
                'name'        => 'Laporan Analisis Lab LTJ',
                'name_en'     => 'LTJ Lab Analysis Report',
                'description' => 'Hasil analisis kimia LTJ (La, Ce, Pr, Nd, Y, ThO₂, U₃O₈) dalam periode tertentu',
                'icon'        => '🔬',
                'formats'     => ['excel', 'pdf'],
                'color'       => 'violet',
            ],
            [
                'id'          => 'monthly_summary',
                'name'        => 'Laporan Bulanan Lengkap',
                'name_en'     => 'Monthly Summary Report',
                'description' => 'Ringkasan komprehensif semua aktivitas (batch, ekspor, lab) dalam satu bulan',
                'icon'        => '📊',
                'formats'     => ['pdf'],
                'color'       => 'emerald',
            ],
        ];
    }

    private function getRecentMonths(int $count): array
    {
        $months = [];
        for ($i = 0; $i < $count; $i++) {
            $month    = now()->subMonths($i);
            $months[] = [
                'value'    => $month->format('Y-m'),
                'label'    => $month->translatedFormat('F Y'),
                'label_en' => $month->format('F Y'),
            ];
        }
        return $months;
    }
}