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
                'monthly_summary'    => $this->generateMonthlyReport($company, $dateFrom, $format),
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
                new \App\Exports\LabAnalysisExport($analyses, $company, $from, $to),</div>
                    
                    <div class="space-y-3">
                        <p class="text-xs font-semibold text-foreground">
                            {{ lang === 'en' ? 'Output Concentrates' : 'Konsentrat Output' }}
                        </p>
                        
                        <div 
                            v-for="(output, idx) in transformForm.outputs" 
                            :key="idx"
                            class="rounded-xl border p-3 space-y-2"
                            :class="{
                                'border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10': output_products?.[idx]?.code === 'MON',
                                'border-blue-200 dark:border-blue-800/40 bg-blue-50 dark:bg-blue-950/10': output_products?.[idx]?.code === 'ZIR',
                                'border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-950/10': output_products?.[idx]?.code === 'ILM'
                            }"
                        >
                            <div class="flex items-center gap-2">
                                <div 
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-bold text-white"
                                    :style="{
                                        'MON': 'background:#ef4444',
                                        'ZIR': 'background:#3b82f6',
                                        'ILM': 'background:#f59e0b'
                                    }[output_products?.[idx]?.code ?? ''] ?? 'background:#6b7280'"
                                >
                                    {{ (output_products?.[idx]?.code ?? `#${idx+1}`).slice(0,3) }}
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-foreground">
                                        {{ getProductLabel(output_products?.[idx]?.code) }}
                                    </p>
                                    <p class="text-[10px] text-muted-foreground">
                                        {{ output_products?.[idx]?.is_export_blocked ? '🔒 Lab LTJ' : '✓ Ekspor' }}
                                    </p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-muted-foreground">
                                        Berat (kg) <span class="text-red-500">*</span>
                                    </label>
                                    <input 
                                        v-model="output.weight_kg" 
                                        type="number" 
                                        step="0.01" 
                                        min="0.01"
                                        @input="updatePercentage(idx)"
                                        class="w-full h-8 px-2.5 rounded-lg border border-border bg-background text-xs font-mono text-foreground"
                                    />
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-muted-foreground">
                                        % (auto)
                                    </label>
                                    <input 
                                        v-model="output.percentage" 
                                        type="number" 
                                        readonly 
                                        class="w-full h-8 px-2.5 rounded-lg border border-border bg-muted text-xs font-mono text-muted-foreground cursor-not-allowed"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-muted/30 p-3 space-y-2">
                        <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Mass Balance
                        </p>
                        <div class="grid grid-cols-3 gap-3 text-xs">
                            <div>
                                <p class="text-muted-foreground">Input</p>
                                <p class="font-bold font-mono text-foreground">{{ inputBatchWeight.toFixed(2) }} kg</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Output</p>
                                <p class="font-bold font-mono text-foreground">{{ totalOutputWeight.toFixed(2) }} kg</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Susut</p>
                                <p 
                                    class="font-bold font-mono" 
                                    :class="parseFloat(transformLossPct) > 5 ? 'text-amber-600' : 'text-emerald-600'"
                                >
                                    {{ transformLossKg.toFixed(2) }} kg ({{ transformLossPct }}%)
                                </p>
                            </div>
                        </div>
                        <p v-if="totalOutputWeight > inputBatchWeight" class="text-xs font-bold text-red-500">
                            ⚠ Total output melebihi berat input!
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-foreground">Catatan</label>
                        <textarea 
                            v-model="transformForm.notes" 
                            rows="2" 
                            class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"
                        ></textarea>
                    </div>
                    
                    <div class="flex gap-3">
                        <button 
                            @click="showTransformModal = false" 
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted"
                        >
                            {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                        </button>
                        <button 
                            @click="submitTransform" 
                            :disabled="transformForm.processing || !transformIsValid"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            style="background: linear-gradient(135deg, #f59e0b, #d97706)"
                        >
                            {{ transformForm.processing ? 'Memproses...' : '⚡ Eksekusi Transformasi' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <Transition name="modal">
            <div 
                v-if="showSendLtjModal" 
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" 
                style="background: rgba(0,0,0,0.4); backdrop-filter: blur(6px);" 
                @click.self="showSendLtjModal = false"
            >
                <div class="w-full max-w-md rounded-2xl border border-border bg-card shadow-xl p-6 space-y-4 dialog-in">
                    
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="font-heading text-base font-bold text-foreground">
                                ⚗️ {{ lang === 'en' ? 'Send MON to LTJ Lab' : 'Kirim MON ke Lab LTJ' }}
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ lang === 'en' ? 'Send Monazite to Project Plant for LTJ analysis.' : 'Kirim Monasit ke Project Plant untuk analisis LTJ.' }}
                            </p>
                        </div>
                        <button @click="showSendLtjModal = false" class="text-muted-foreground hover:text-foreground">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div 
                        v-if="selectedForLtj" 
                        class="rounded-xl border border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10 p-3"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-red-500 flex items-center justify-center text-[10px] font-bold text-white">
                                    MON
                                </div>
                                <div>
                                    <p class="text-xs font-bold font-mono text-foreground">{{ selectedForLtj.batch_code }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ lang === 'en' ? 'Monazite — Radioactive' : 'Monasit — Radioaktif ☢' }}
                                    </p>
                                </div>
                            </div>
                            <p class="text-sm font-bold text-red-700 dark:text-red-300">
                                {{ selectedForLtj.current_weight?.toLocaleString('id-ID') }} kg
                            </p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-foreground">
                            {{ lang === 'en' ? 'Notes (optional)' : 'Catatan (opsional)' }}
                        </label>
                        <textarea 
                            v-model="sendLtjForm.notes" 
                            rows="2" 
                            class="w-full px-3 py-2 rounded-xl border border-border bg-background text-sm text-foreground resize-none"
                        ></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button 
                            @click="showSendLtjModal = false" 
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted"
                        >
                            {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                        </button>
                        <button 
                            @click="submitSendLtj" 
                            :disabled="sendLtjForm.processing"
                            class="flex-[2] h-10 rounded-xl text-sm font-bold text-white disabled:opacity-60"
                            style="background: linear-gradient(135deg, #7c3aed, #5b21b6)"
                        >
                            {{ sendLtjForm.processing ? 'Mengirim...' : '→ Kirim ke Lab LTJ' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>

<style scoped>
/* ─── Pie chart hole + text theming ────────────────────────────────────────── */
.pie-hole {
    fill: hsl(var(--card));
}
.pie-text-main {
    fill: hsl(var(--foreground));
}
.pie-text-sub {
    fill: hsl(var(--muted-foreground));
}

/* ─── Animations ────────────────────────────────────────────────────────────── */
@keyframes fade-in {
    from {
        opacity: 0;
        transform: translateY(5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.animate-fade-in {
    animation: fade-in 0.28s ease-out both;
}
.delay-50 { animation-delay: 50ms; }
.delay-75 { animation-delay: 75ms; }
.delay-100 { animation-delay: 100ms; }
.delay-150 { animation-delay: 150ms; }
.delay-200 { animation-delay: 200ms; }
.delay-250 { animation-delay: 250ms; }
.delay-300 { animation-delay: 300ms; }

/* ─── Modals ────────────────────────────────────────────────────────────────── */
.modal-enter-active {
    transition: opacity 0.2s ease;
}
.modal-leave-active {
    transition: opacity 0.15s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

@keyframes dialog-in {
    from {
        opacity: 0;
        transform: scale(0.94) translateY(6px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
.dialog-in {
    animation: dialog-in 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
}
</style>
                "{$filename}.xlsx"
            );
        }g

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

    // ── Gunakan bulan dari $from, akhir bulan dari $to ────────────────────
    // Jika user pilih rentang satu bulan (Jun 1 – Jun 30), gunakan tepat.
    // Jika user pilih custom range, gunakan $from – $to apa adanya.
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