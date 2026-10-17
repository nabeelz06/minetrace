<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\LabAnalysis;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LabController — Input, validasi, dan manajemen analisis lab LTJ.
 *
 * ════════════════════════════════════════════════════════════════════
 * ALUR KERJA OPERATOR LAB LTJ:
 *
 * 1. Warehouse kirim batch MON → project_plant (via ProcessController)
 * 2. Operator Lab LTJ menerima MON di project_plant
 * 3. Input analisis: La%, Ce%, Pr%, Nd%, Y%, ThO2%, U3O8%
 *    → sistem auto-hitung berat per elemen (kg) dari berat batch
 *    → sistem auto-hitung total_reo_pct
 * 4. Validasi analisis → is_validated = true
 * 5. Kirim kembali ke Warehouse (sendToWarehouse):
 *    → batch MON kembali ke stage 'warehouse', status 'in_warehouse'
 *    → checkpoint 'warehouse_inbound' dicatat
 *    → metadata batch diupdate dengan hasil analisis LTJ
 *
 * CATATAN PENTING:
 *   Unsur LTJ (La, Ce, Pr, Nd, Y) TIDAK membuat batch baru.
 *   Data unsur tersimpan di tabel lab_analyses dan dapat diakses dari:
 *   - /company/lab            → daftar analisis + agregasi stok LTJ
 *   - /company/batches/{id}   → detail batch tab "Lab Analisis"
 *   - /company/process/warehouse → dashboard warehouse (stok MON teranalisis)
 * ════════════════════════════════════════════════════════════════════
 */
class LabController extends Controller
{
    private const TZ = 'Asia/Jakarta';

    // ══════════════════════════════════════════════════════════════════
    // INDEX — Daftar analisis + agregasi stok LTJ
    // ══════════════════════════════════════════════════════════════════

    /**
     * Daftar semua analisis lab + statistik stok unsur LTJ.
     *
     * Menampilkan dua bagian:
     * 1. Tabel analisis (persentase + berat elemen per batch)
     * 2. Agregasi total stok LTJ dalam kg (dari semua analisis tervalidasi)
     */
    public function index(Request $request): Response
    {
        $companyId = Auth::user()->company_id;
        $tz        = self::TZ;

        // ─── Query analisis ────────────────────────────────────────────────
        $query = LabAnalysis::with([
                'batch.productCode',
                'batch.currentLocation',
                'analyzedByUser',
                'validatedByUser',
            ])
            ->where('company_id', $companyId)
            ->latest('analyzed_at');

        if ($request->filled('search')) {
            $query->whereHas('batch', fn($q) =>
                $q->where('batch_code', 'ILIKE', "%{$request->search}%")
            );
        }
        if ($request->filled('validated') && $request->validated !== '') {
            $query->where('is_validated', $request->validated === '1');
        }

        $analyses = $query->paginate(20)->withQueryString();

        // Transform data dengan format timestamp detail dan berat elemen
        $analyses->getCollection()->transform(fn($a) => [
            'id'              => $a->id,
            'batch_id'        => $a->batch_id,
            'batch_code'      => $a->batch?->batch_code,
            'batch_weight_kg' => $a->batch?->current_weight,
            'batch_stage'     => $a->batch?->process_stage,
            'product_code'    => $a->batch?->productCode?->code,
            'product_name'    => $a->batch?->productCode?->name,
            'location'        => $a->batch?->currentLocation?->name,
            // Persentase unsur
            'la_pct'          => $a->la_pct,
            'ce_pct'          => $a->ce_pct,
            'pr_pct'          => $a->pr_pct,
            'nd_pct'          => $a->nd_pct,
            'y_pct'           => $a->y_pct,
            'tho2_pct'        => $a->tho2_pct,
            'u3o8_pct'        => $a->u3o8_pct,
            'total_reo_pct'   => $a->total_reo_pct,
            // Berat per elemen (kg) — dihitung otomatis saat store
            'la_kg'           => $a->la_kg,
            'ce_kg'           => $a->ce_kg,
            'pr_kg'           => $a->pr_kg,
            'nd_kg'           => $a->nd_kg,
            'y_kg'            => $a->y_kg,
            'total_reo_kg'    => round(
                ($a->la_kg ?? 0) + ($a->ce_kg ?? 0) +
                ($a->pr_kg ?? 0) + ($a->nd_kg ?? 0) + ($a->y_kg ?? 0), 3
            ),
            'lab_method'      => $a->lab_method,
            'equipment_used'  => $a->equipment_used,
            'sample_weight_g' => $a->sample_weight_g,
            'notes'           => $a->notes,
            'is_validated'    => $a->is_validated,
            'is_sent_to_warehouse' => $a->batch?->process_stage === 'warehouse'
                && $a->is_validated,
            // Timestamps detail WIB
            'analyzed_at'     => $a->analyzed_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'analyzed_at_date'=> $a->analyzed_at?->timezone($tz)->translatedFormat('l, d F Y'),
            'analyzed_at_diff'=> $a->analyzed_at?->diffForHumans(),
            'validated_at'    => $a->validated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'validated_at_diff'=> $a->validated_at?->diffForHumans(),
            'analyzed_by'     => $a->analyzedByUser?->name,
            'validated_by'    => $a->validatedByUser?->name,
        ]);

        // ─── Stats ringkasan ───────────────────────────────────────────────
        $stats = [
            'total'           => LabAnalysis::where('company_id', $companyId)->count(),
            'validated'       => LabAnalysis::where('company_id', $companyId)->where('is_validated', true)->count(),
            'pending'         => LabAnalysis::where('company_id', $companyId)->where('is_validated', false)->count(),
            'avg_reo_pct'     => round(
                LabAnalysis::where('company_id', $companyId)->where('is_validated', true)->avg('total_reo_pct') ?? 0,
                2
            ),
        ];

        // ─── AGREGASI STOK LTJ — dari semua analisis tervalidasi ──────────
        // Ini adalah "storage" unsur LTJ dalam kg dari semua batch MON
        // yang sudah dianalisis
        $ltjAggregate = LabAnalysis::where('company_id', $companyId)
            ->where('is_validated', true)
            ->selectRaw("
                COUNT(*) as batch_count,
                COALESCE(SUM(la_kg), 0)  as total_la_kg,
                COALESCE(SUM(ce_kg), 0)  as total_ce_kg,
                COALESCE(SUM(pr_kg), 0)  as total_pr_kg,
                COALESCE(SUM(nd_kg), 0)  as total_nd_kg,
                COALESCE(SUM(y_kg),  0)  as total_y_kg,
                COALESCE(AVG(la_pct), 0) as avg_la_pct,
                COALESCE(AVG(ce_pct), 0) as avg_ce_pct,
                COALESCE(AVG(pr_pct), 0) as avg_pr_pct,
                COALESCE(AVG(nd_pct), 0) as avg_nd_pct,
                COALESCE(AVG(y_pct),  0) as avg_y_pct,
                COALESCE(AVG(total_reo_pct), 0) as avg_reo_pct
            ")
            ->first();

        $totalLtjKg = round(
            ($ltjAggregate->total_la_kg ?? 0) +
            ($ltjAggregate->total_ce_kg ?? 0) +
            ($ltjAggregate->total_pr_kg ?? 0) +
            ($ltjAggregate->total_nd_kg ?? 0) +
            ($ltjAggregate->total_y_kg  ?? 0),
            3
        );

        // ─── Breakdown per elemen untuk tampilan stok ──────────────────────
        $ltjElementStock = [
            [
                'element' => 'La', 'name_id' => 'Lantanum',       'name_en' => 'Lanthanum',
                'symbol'  => 'La', 'atomic_number' => 57,
                'total_kg'=> round($ltjAggregate->total_la_kg ?? 0, 3),
                'avg_pct' => round($ltjAggregate->avg_la_pct ?? 0, 2),
                'color'   => '#7c3aed', 'bg' => 'rgba(124,58,237,0.1)',
                'use'      => 'Bahan baku optik, katalis',
                'use_en'   => 'Optical glass, catalyst material',
            ],
            [
                'element' => 'Ce', 'name_id' => 'Serium',          'name_en' => 'Cerium',
                'symbol'  => 'Ce', 'atomic_number' => 58,
                'total_kg'=> round($ltjAggregate->total_ce_kg ?? 0, 3),
                'avg_pct' => round($ltjAggregate->avg_ce_pct ?? 0, 2),
                'color'   => '#2563eb', 'bg' => 'rgba(37,99,235,0.1)',
                'use'      => 'Pemoles kaca, katalis otomotif',
                'use_en'   => 'Glass polishing, automotive catalyst',
            ],
            [
                'element' => 'Pr', 'name_id' => 'Praseodimium',    'name_en' => 'Praseodymium',
                'symbol'  => 'Pr', 'atomic_number' => 59,
                'total_kg'=> round($ltjAggregate->total_pr_kg ?? 0, 3),
                'avg_pct' => round($ltjAggregate->avg_pr_pct ?? 0, 2),
                'color'   => '#059669', 'bg' => 'rgba(5,150,105,0.1)',
                'use'      => 'Magnet permanen, alloy',
                'use_en'   => 'Permanent magnets, alloys',
            ],
            [
                'element' => 'Nd', 'name_id' => 'Neodimium',       'name_en' => 'Neodymium',
                'symbol'  => 'Nd', 'atomic_number' => 60,
                'total_kg'=> round($ltjAggregate->total_nd_kg ?? 0, 3),
                'avg_pct' => round($ltjAggregate->avg_nd_pct ?? 0, 2),
                'color'   => '#d97706', 'bg' => 'rgba(217,119,6,0.1)',
                'use'      => 'Magnet NdFeB (EV, wind turbine)',
                'use_en'   => 'NdFeB magnets (EV, wind turbines)',
            ],
            [
                'element' => 'Y',  'name_id' => 'Itrium',           'name_en' => 'Yttrium',
                'symbol'  => 'Y',  'atomic_number' => 39,
                'total_kg'=> round($ltjAggregate->total_y_kg ?? 0, 3),
                'avg_pct' => round($ltjAggregate->avg_y_pct ?? 0, 2),
                'color'   => '#dc2626', 'bg' => 'rgba(220,38,38,0.1)',
                'use'      => 'Fosfor, LED, superkonduktor',
                'use_en'   => 'Phosphors, LEDs, superconductors',
            ],
        ];

        // ─── Riwayat bulanan (chart data) — 6 bulan terakhir ──────────────
        $monthlyTrend = LabAnalysis::where('company_id', $companyId)
            ->where('is_validated', true)
            ->where('analyzed_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("
                DATE_TRUNC('month', analyzed_at) as month,
                COUNT(*) as count,
                COALESCE(SUM(la_kg + ce_kg + pr_kg + nd_kg + y_kg), 0) as total_ltj_kg,
                COALESCE(AVG(total_reo_pct), 0) as avg_reo_pct
            ")
            ->groupBy(DB::raw("DATE_TRUNC('month', analyzed_at)"))
            ->orderBy('month')
            ->get()
            ->map(fn($r) => [
                'month'        => Carbon::parse($r->month)->timezone($tz)->format('M Y'),
                'count'        => $r->count,
                'total_ltj_kg' => round($r->total_ltj_kg, 2),
                'avg_reo_pct'  => round($r->avg_reo_pct, 2),
            ])
            ->toArray();

        return Inertia::render('Company/Lab/Index', [
            'analyses'         => $analyses,
            'stats'            => $stats,
            'ltj_aggregate'    => [
                'batch_count'   => $ltjAggregate->batch_count ?? 0,
                'total_ltj_kg'  => $totalLtjKg,
                'avg_reo_pct'   => round($ltjAggregate->avg_reo_pct ?? 0, 2),
            ],
            'ltj_element_stock'=> $ltjElementStock,
            'monthly_trend'    => $monthlyTrend,
            'filters'          => $request->only(['search', 'validated']),
            'user_role'        => Auth::user()->getRoleNames()->first(),
            'current_time'     => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // CREATE — Form input analisis lab
    // ══════════════════════════════════════════════════════════════════

    /**
     * Form input analisis lab untuk batch tertentu.
     * Hanya untuk batch MON yang berada di project_plant.
     */
    public function create(Batch $batch): Response
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $batch->load(['productCode', 'currentLocation', 'labAnalyses']);

        // Warning: jika sudah ada analisis yang tervalidasi
        $hasValidatedAnalysis = $batch->labAnalyses
            ->where('is_validated', true)
            ->isNotEmpty();

        $locations = Location::where('company_id', Auth::user()->company_id)
            ->whereIn('location_type', ['project_plant', 'lab_kimia'])
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'full_name']);

        return Inertia::render('Company/Lab/Create', [
            'batch'                => [
                'id'           => $batch->id,
                'batch_code'   => $batch->batch_code,
                'process_stage'=> $batch->process_stage,
                'status'       => $batch->status,
                'current_weight'=> $batch->current_weight,
                'product'      => $batch->productCode ? [
                    'code' => $batch->productCode->code,
                    'name' => $batch->productCode->name,
                ] : null,
                'location'     => $batch->currentLocation?->name,
            ],
            'existing_analyses'    => $batch->labAnalyses->count(),
            'has_validated'        => $hasValidatedAnalysis,
            'locations'            => $locations,
            'methods'              => ['XRF', 'ICP-MS', 'AAS', 'Gravimetri'],
            'current_time'         => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // STORE — Simpan hasil analisis
    // ══════════════════════════════════════════════════════════════════

    /**
     * Simpan hasil analisis kimia unsur LTJ.
     *
     * Auto-kalkulasi:
     *   - total_reo_pct = La + Ce + Pr + Nd + Y
     *   - *_kg = current_weight × (*_pct / 100)
     */
    public function store(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_lab_ltj']), 403);

        $validated = $request->validate([
            'location_id'     => 'nullable|integer|exists:locations,id',
            'la_pct'          => 'nullable|numeric|min:0|max:100',
            'ce_pct'          => 'nullable|numeric|min:0|max:100',
            'pr_pct'          => 'nullable|numeric|min:0|max:100',
            'nd_pct'          => 'nullable|numeric|min:0|max:100',
            'y_pct'           => 'nullable|numeric|min:0|max:100',
            'tho2_pct'        => 'nullable|numeric|min:0|max:100',
            'u3o8_pct'        => 'nullable|numeric|min:0|max:100',
            'lab_method'      => 'nullable|string|max:50',
            'equipment_used'  => 'nullable|string|max:100',
            'sample_weight_g' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:1000',
        ], [
            'la_pct.numeric'  => 'Nilai La harus berupa angka.',
            'ce_pct.numeric'  => 'Nilai Ce harus berupa angka.',
            'pr_pct.numeric'  => 'Nilai Pr harus berupa angka.',
            'nd_pct.numeric'  => 'Nilai Nd harus berupa angka.',
            'y_pct.numeric'   => 'Nilai Y harus berupa angka.',
            'la_pct.max'      => 'Nilai La tidak boleh melebihi 100%.',
            'ce_pct.max'      => 'Nilai Ce tidak boleh melebihi 100%.',
        ]);

        return DB::transaction(function () use ($validated, $batch) {
            $tz = self::TZ;
            $w  = (float) $batch->current_weight;

            // ─── Auto-kalkulasi total REO ──────────────────────────────────
            $totalReo = round(
                ($validated['la_pct'] ?? 0) +
                ($validated['ce_pct'] ?? 0) +
                ($validated['pr_pct'] ?? 0) +
                ($validated['nd_pct'] ?? 0) +
                ($validated['y_pct']  ?? 0),
                4
            );

            // ─── Auto-kalkulasi berat per elemen (kg) ─────────────────────
            $elementWeights = [
                'la_kg' => round($w * ($validated['la_pct'] ?? 0) / 100, 4),
                'ce_kg' => round($w * ($validated['ce_pct'] ?? 0) / 100, 4),
                'pr_kg' => round($w * ($validated['pr_pct'] ?? 0) / 100, 4),
                'nd_kg' => round($w * ($validated['nd_pct'] ?? 0) / 100, 4),
                'y_kg'  => round($w * ($validated['y_pct']  ?? 0) / 100, 4),
            ];

            $totalReoKg = array_sum($elementWeights);

            // ─── Simpan analisis ───────────────────────────────────────────
            LabAnalysis::create(array_merge($validated, $elementWeights, [
                'batch_id'      => $batch->id,
                'company_id'    => $batch->company_id,
                'total_reo_pct' => $totalReo,
                'analyzed_by'   => Auth::id(),
                'analyzed_at'   => now(),
            ]));

            // ─── Update batch metadata dengan hasil LTJ ────────────────────
            $currentMetadata = $batch->metadata ?? [];
            $batch->update([
                'metadata' => array_merge($currentMetadata, [
                    'ltj_analysis' => [
                        'total_reo_pct'  => $totalReo,
                        'total_reo_kg'   => round($totalReoKg, 4),
                        'la_pct'         => $validated['la_pct'] ?? 0,
                        'ce_pct'         => $validated['ce_pct'] ?? 0,
                        'pr_pct'         => $validated['pr_pct'] ?? 0,
                        'nd_pct'         => $validated['nd_pct'] ?? 0,
                        'y_pct'          => $validated['y_pct']  ?? 0,
                        'analyzed_at'    => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    ],
                ]),
            ]);

            return redirect()
                ->route('company.lab.index')
                ->with('success',
                    "✅ Analisis lab batch {$batch->batch_code} berhasil disimpan. " .
                    "Total REO: {$totalReo}% (" . round($totalReoKg, 3) . " kg). " .
                    "Silakan validasi analisis kemudian kirim ke Warehouse.");
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // VALIDATE — Tandai analisis sebagai tervalidasi
    // ══════════════════════════════════════════════════════════════════

    /**
     * Validasi hasil analisis lab.
     * Setelah divalidasi, operator dapat mengirim batch ke warehouse.
     */
    public function validate(LabAnalysis $analysis): RedirectResponse
    {
        abort_unless($analysis->company_id === Auth::user()->company_id, 403);
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_lab_ltj']), 403);

        if ($analysis->is_validated) {
            return back()->with('info', 'Analisis ini sudah tervalidasi sebelumnya.');
        }

        $analysis->update([
            'is_validated' => true,
            'validated_at' => now(),
            'validated_by' => Auth::id(),
        ]);

        return back()->with('success',
            "✅ Analisis lab batch {$analysis->batch?->batch_code} berhasil divalidasi. " .
            "Total REO: {$analysis->total_reo_pct}%. " .
            "Gunakan tombol 'Kirim ke Warehouse' untuk mengembalikan batch ke gudang.");
    }

    // ══════════════════════════════════════════════════════════════════
    // SEND TO WAREHOUSE — Kembalikan batch MON ke warehouse setelah analisis
    // ══════════════════════════════════════════════════════════════════

    /**
     * Kirim batch MON yang sudah dianalisis kembali ke Warehouse.
     *
     * PRASYARAT:
     *   - Batch harus berada di project_plant
     *   - Minimal ada 1 analisis yang sudah divalidasi
     *
     * Setelah eksekusi:
     *   - Batch kembali ke stage 'warehouse', status 'in_warehouse'
     *   - Checkpoint 'warehouse_inbound' dicatat
     *   - Metadata batch diupdate dengan ringkasan LTJ
     *
     * Route: POST /company/batches/{batch}/send-to-warehouse
     */
    public function sendToWarehouse(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_lab_ltj']), 403);

        // Guard: batch harus di project_plant
        if ($batch->process_stage !== 'project_plant') {
            return back()->with('error',
                "Batch {$batch->batch_code} tidak berada di Project Plant. " .
                "Stage saat ini: {$batch->process_stage}.");
        }

        // Guard: harus ada analisis yang tervalidasi
        $validatedAnalysis = $batch->labAnalyses()
            ->where('is_validated', true)
            ->latest('validated_at')
            ->first();

        if (!$validatedAnalysis) {
            return back()->with('error',
                "Batch {$batch->batch_code} belum memiliki analisis lab yang tervalidasi. " .
                "Validasi analisis terlebih dahulu sebelum mengirim ke Warehouse.");
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($batch, $validated, $validatedAnalysis) {
            $tz = self::TZ;

            // ─── Tentukan lokasi warehouse ─────────────────────────────────
            $warehouseLoc = Location::where('company_id', Auth::user()->company_id)
                ->where('location_type', 'warehouse')
                ->where('is_active', true)
                ->first();

            // ─── Update batch: kembali ke warehouse ────────────────────────
            $previousMetadata = $batch->metadata ?? [];
            $batch->update([
                'process_stage'       => 'warehouse',
                'status'              => 'in_warehouse',
                'current_location_id' => $warehouseLoc?->id ?? $batch->current_location_id,
                'updated_by'          => Auth::id(),
                'metadata'            => array_merge($previousMetadata, [
                    'ltj_analysis_summary' => [
                        'analysis_id'    => $validatedAnalysis->id,
                        'total_reo_pct'  => $validatedAnalysis->total_reo_pct,
                        'la_pct'         => $validatedAnalysis->la_pct,
                        'ce_pct'         => $validatedAnalysis->ce_pct,
                        'pr_pct'         => $validatedAnalysis->pr_pct,
                        'nd_pct'         => $validatedAnalysis->nd_pct,
                        'y_pct'          => $validatedAnalysis->y_pct,
                        'la_kg'          => $validatedAnalysis->la_kg,
                        'ce_kg'          => $validatedAnalysis->ce_kg,
                        'pr_kg'          => $validatedAnalysis->pr_kg,
                        'nd_kg'          => $validatedAnalysis->nd_kg,
                        'y_kg'           => $validatedAnalysis->y_kg,
                        'validated_at'   => $validatedAnalysis->validated_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
                        'sent_to_warehouse_at' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                        'sent_by'        => Auth::user()->name,
                    ],
                ]),
            ]);

            // ─── Catat checkpoint warehouse_inbound ────────────────────────
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $warehouseLoc?->id ?? $batch->current_location_id,
                'checkpoint_stage' => 'warehouse_inbound',
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => $validated['notes']
                    ?? "Batch MON kembali dari Lab Analisis LTJ ke Warehouse. " .
                       "REO: {$validatedAnalysis->total_reo_pct}% — " .
                       Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                'checkpoint_data'  => [
                    'action'         => 'return_from_ltj_lab',
                    'from_stage'     => 'project_plant',
                    'to_stage'       => 'warehouse',
                    'analysis_id'    => $validatedAnalysis->id,
                    'total_reo_pct'  => $validatedAnalysis->total_reo_pct,
                    'la_kg'          => $validatedAnalysis->la_kg,
                    'ce_kg'          => $validatedAnalysis->ce_kg,
                    'pr_kg'          => $validatedAnalysis->pr_kg,
                    'nd_kg'          => $validatedAnalysis->nd_kg,
                    'y_kg'           => $validatedAnalysis->y_kg,
                    'timestamp_wib'  => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'recorded_by'  => Auth::id(),
                'recorded_at'  => now(),
            ]);

            $reoSummary = "La: {$validatedAnalysis->la_kg} kg | Ce: {$validatedAnalysis->ce_kg} kg | " .
                          "Pr: {$validatedAnalysis->pr_kg} kg | Nd: {$validatedAnalysis->nd_kg} kg | " .
                          "Y: {$validatedAnalysis->y_kg} kg";

            return redirect()
                ->route('company.batches.show', $batch)
                ->with('success',
                    "✅ Batch {$batch->batch_code} (REO {$validatedAnalysis->total_reo_pct}%) " .
                    "berhasil dikirim ke Warehouse. Kandungan LTJ: {$reoSummary}.");
        });
    }
}