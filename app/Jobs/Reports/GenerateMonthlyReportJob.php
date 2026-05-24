<?php

namespace App\Jobs\Reports;

use App\Models\Company;
use App\Models\Batch;
use App\Models\ExportLog;
use App\Models\LabAnalysis;
use App\Services\Analytics\CompanyAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * GenerateMonthlyReportJob — generate dan kirim laporan bulanan per company.
 *
 * Dipanggil dari scheduler setiap tanggal 1 pukul 07:00 WIB.
 * Setiap company dapat mengaktifkan/menonaktifkan laporan ini
 * melalui halaman Settings.
 *
 * Arsitektur fleksibel:
 * - Job ini dijalankan per company secara independent
 * - Saat company baru ditambah, laporan otomatis aktif jika setting enabled
 * - Laporan mencakup: KPI, batch summary, export summary, mass balance alerts
 */
class GenerateMonthlyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private int    $companyId,
        private string $reportMonth,   // Format: 'Y-m' misal '2026-04'
        private bool   $sendEmail = true
    ) {}

    public function handle(CompanyAnalyticsService $analytics): void
    {
        $company = Company::with('companySettings')->findOrFail($this->companyId);

        // ─── Cek apakah laporan aktif untuk company ini ────────────────────
        $isEnabled = \App\Models\CompanySetting::get($this->companyId, 'monthly_report_enabled', true);
        if (!$isEnabled) {
            logger()->info("Monthly report disabled for {$company->name}");
            return;
        }

        $monthStart = Carbon::createFromFormat('Y-m', $this->reportMonth)->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();
        $monthLabel = $monthStart->translatedFormat('F Y');

        // ─── Kumpulkan data laporan ────────────────────────────────────────
        $data = $this->collectReportData($company, $monthStart, $monthEnd, $analytics);

        // ─── Generate PDF ──────────────────────────────────────────────────
        $pdf  = Pdf::loadView('pdf.reports.monthly', array_merge($data, [
            'company'     => $company,
            'month_label' => $monthLabel,
            'generated_at'=> now()->format('d M Y H:i') . ' WIB',
        ]))->setPaper('a4', 'portrait');

        $filename = "reports/{$this->companyId}/monthly-{$this->reportMonth}.pdf";
        Storage::put($filename, $pdf->output());

        logger()->info("Monthly report generated: {$filename}");

        // ─── Kirim email jika dikonfigurasi ───────────────────────────────
        if ($this->sendEmail) {
            $email = \App\Models\CompanySetting::get($this->companyId, 'monthly_report_email');
            if ($email) {
                $this->sendReportEmail($company, $email, $filename, $monthLabel, $data);
            }
        }
    }

    /**
     * Kumpulkan semua data yang dibutuhkan untuk laporan.
     */
    private function collectReportData(Company $company, Carbon $start, Carbon $end, CompanyAnalyticsService $analytics): array
    {
        $companyId = $company->id;

        // ─── Batch summary bulan ini ───────────────────────────────────────
        $batches = Batch::where('company_id', $companyId)
            ->whereBetween('created_at', [$start, $end])
            ->with('productCode')
            ->get();

        $batchSummary = [
            'total_created'   => $batches->count(),
            'total_weight_kg' => $batches->sum('initial_weight'),
            'by_product'      => $batches->groupBy('productCode.code')
                ->map(fn($group, $code) => [
                    'code'     => $code,
                    'count'    => $group->count(),
                    'weight'   => round($group->sum('current_weight'), 2),
                ])->values()->toArray(),
            'by_stage' => Batch::where('company_id', $companyId)
                ->select('process_stage', \Illuminate\Support\Facades\DB::raw('count(*) as cnt'))
                ->whereNotIn('status', ['exported','terminated','transformed'])
                ->groupBy('process_stage')
                ->get()
                ->map(fn($r) => ['stage' => $r->process_stage, 'count' => $r->cnt])
                ->toArray(),
        ];

        // ─── Export summary ────────────────────────────────────────────────
        $exports = ExportLog::where('company_id', $companyId)
            ->whereBetween('exported_at', [$start, $end])
            ->where('status', 'confirmed')
            ->get();

        $exportSummary = [
            'total'         => $exports->count(),
            'international' => $exports->where('export_type', 'international')->count(),
            'domestic'      => $exports->where('export_type', 'domestic')->count(),
            'total_weight'  => round($exports->sum('total_weight_kg'), 2),
            'total_value'   => round($exports->sum('value_usd'), 2),
        ];

        // ─── Lab analysis summary ──────────────────────────────────────────
        $labs = LabAnalysis::where('company_id', $companyId)
            ->whereBetween('analyzed_at', [$start, $end])
            ->get();

        $labSummary = [
            'total'       => $labs->count(),
            'validated'   => $labs->where('is_validated', true)->count(),
            'avg_reo_pct' => round($labs->avg('total_reo_pct') ?? 0, 2),
        ];

        // ─── Mass balance alerts bulan ini ────────────────────────────────
        $alerts = Batch::where('company_id', $companyId)
            ->where('has_discrepancy', true)
            ->whereBetween('updated_at', [$start, $end])
            ->select('process_stage', \Illuminate\Support\Facades\DB::raw('count(*) as cnt'), \Illuminate\Support\Facades\DB::raw('avg(mass_loss_pct) as avg_loss'))
            ->groupBy('process_stage')
            ->get()
            ->toArray();

        return [
            'batch_summary'  => $batchSummary,
            'export_summary' => $exportSummary,
            'lab_summary'    => $labSummary,
            'alerts'         => $alerts,
        ];
    }

    /**
     * Kirim laporan via email.
     */
    private function sendReportEmail(Company $company, string $email, string $filePath, string $monthLabel, array $data): void
    {
        try {
            Mail::send('emails.monthly-report', [
                'company'    => $company,
                'month'      => $monthLabel,
                'summary'    => $data,
            ], function($message) use ($email, $company, $monthLabel, $filePath) {
                $message->to($email)
                    ->subject("Laporan Bulanan MINETRA — {$company->name} — {$monthLabel}")
                    ->attach(Storage::path($filePath), [
                        'as'   => "Laporan-{$company->code}-{$monthLabel}.pdf",
                        'mime' => 'application/pdf',
                    ]);
            });

            logger()->info("Monthly report email sent to {$email} for {$company->name}");
        } catch (\Exception $e) {
            logger()->error("Failed to send monthly report email: " . $e->getMessage());
        }
    }
}