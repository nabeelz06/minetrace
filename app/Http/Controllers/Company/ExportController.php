<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\ExportLog;
use App\Services\Export\ExportValidationService;   // ← FIX: was App\Services\ExportValidationService
use App\Services\Export\DocumentGeneratorService;  // ← FIX: was App\Services\DocumentGeneratorService
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ExportController — kelola ekspor dan penjualan domestik.
 * Hard block MON dijalankan di ExportValidationService.
 */
class ExportController extends Controller
{
    public function __construct(
        private ExportValidationService  $validationService,
        private DocumentGeneratorService $documentService
    ) {}

    /**
     * Daftar semua export logs.
     */
    public function index(Request $request): Response
    {
        $companyId = Auth::user()->company_id;

        $query = ExportLog::where('company_id', $companyId)
            ->with('createdByUser')
            ->latest();

        if ($request->filled('type'))   { $query->where('export_type', $request->type); }
        if ($request->filled('status')) { $query->where('status', $request->status); }

        $exports = $query->paginate(20)->withQueryString();

        $stats = [
            'total'         => ExportLog::where('company_id', $companyId)->count(),
            'international' => ExportLog::where('company_id', $companyId)->where('export_type', 'international')->count(),
            'domestic'      => ExportLog::where('company_id', $companyId)->where('export_type', 'domestic')->count(),
            'total_weight'  => ExportLog::where('company_id', $companyId)->where('status', 'confirmed')->sum('total_weight_kg'),
            'total_value'   => ExportLog::where('company_id', $companyId)->where('status', 'confirmed')->sum('value_usd'),
        ];

        return Inertia::render('Company/Export/Index', [
            'exports' => $exports,
            'stats'   => $stats,
            'filters' => $request->only(['type', 'status']),
        ]);
    }

    /**
     * Form buat export baru. Hanya company_admin dan operator_warehouse.
     */
    public function create(): Response
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'operator_warehouse']),
            403, 'Anda tidak memiliki izin membuat ekspor.'
        );

        $companyId = Auth::user()->company_id;

        $availableBatches = Batch::with('productCode')
            ->where('company_id', $companyId)
            ->where('status', 'in_warehouse')
            ->whereHas('productCode', fn($q) => $q->where('is_export_blocked', false))
            ->get()
            ->map(fn($b) => [
                'id'          => $b->id,
                'batch_code'  => $b->batch_code,
                'weight_kg'   => $b->current_weight,
                'product'     => [
                    'id'   => $b->productCode->id,
                    'code' => $b->productCode->code,
                    'name' => $b->productCode->name,
                ],
            ]);

        return Inertia::render('Company/Export/Create', [
            'available_batches' => $availableBatches,
        ]);
    }

    /**
     * Validasi batch sebelum konfirmasi ekspor (AJAX endpoint).
     */
    public function validateBatches(Request $request)
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_warehouse']), 403);

        $request->validate([
            'batch_ids'   => 'required|array|min:1',
            'batch_ids.*' => 'integer|exists:batches,id',
        ]);

        $result = $this->validationService->validate(
            $request->batch_ids,
            Auth::user()->company_id
        );

        return response()->json($result);
    }

    /**
     * Simpan dan konfirmasi ekspor.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasAnyRole(['company_admin', 'operator_warehouse']), 403);

        $validated = $request->validate([
            'batch_ids'             => 'required|array|min:1',
            'batch_ids.*'           => 'integer|exists:batches,id',
            'export_type'           => 'required|in:international,domestic',
            'destination_country'   => 'required_if:export_type,international|nullable|string|max:100',
            'destination_company'   => 'required|string|max:255',
            'destination_address'   => 'nullable|string|max:500',
            'manifest_number'       => 'nullable|string|max:100',
            'invoice_number'        => 'nullable|string|max:100',
            'container_number'      => 'nullable|string|max:100',
            'vessel_name'           => 'nullable|string|max:255',
            'port_of_loading'       => 'nullable|string|max:100',
            'port_of_discharge'     => 'nullable|string|max:100',
            'value_usd'             => 'nullable|numeric|min:0',
            'notes'                 => 'nullable|string|max:2000',
            'warnings_acknowledged' => 'nullable|array',
        ]);

        $companyId = Auth::user()->company_id;

        $validationResult = $this->validationService->validate($validated['batch_ids'], $companyId);

        if (!$validationResult['can_proceed']) {
            return back()
                ->withErrors(['batch_ids' => collect($validationResult['hard_blocks'])->pluck('reason')->join(' | ')])
                ->withInput();
        }

        return DB::transaction(function () use ($validated, $companyId, $validationResult) {

            $export = ExportLog::create([
                'company_id'            => $companyId,
                'batch_ids'             => $validated['batch_ids'],
                'total_weight_kg'       => $validationResult['total_weight'],
                'export_type'           => $validated['export_type'],
                'destination_country'   => $validated['destination_country'] ?? null,
                'destination_company'   => $validated['destination_company'],
                'destination_address'   => $validated['destination_address'] ?? null,
                'manifest_number'       => $validated['manifest_number'] ?? null,
                'invoice_number'        => $validated['invoice_number'] ?? null,
                'container_number'      => $validated['container_number'] ?? null,
                'vessel_name'           => $validated['vessel_name'] ?? null,
                'port_of_loading'       => $validated['port_of_loading'] ?? null,
                'port_of_discharge'     => $validated['port_of_discharge'] ?? null,
                'value_usd'             => $validated['value_usd'] ?? null,
                'notes'                 => $validated['notes'] ?? null,
                'status'                => 'confirmed',
                'warnings_acknowledged' => $validated['warnings_acknowledged'] ?? [],
                'created_by'            => Auth::id(),
                'exported_at'           => now(),
            ]);

            Batch::whereIn('id', $validated['batch_ids'])
                ->update([
                    'status'        => 'exported',
                    'export_status' => 'exported',
                    'process_stage' => 'exported',
                    'updated_by'    => Auth::id(),
                ]);

            try {
                $docPaths = $this->documentService->generateExportDocuments($export);
                $export->update(['generated_documents' => $docPaths]);
            } catch (\Exception $e) {
                logger()->error('Document generation failed: ' . $e->getMessage());
            }

            return redirect()
                ->route('company.exports.show', $export)
                ->with('success', "Ekspor #{$export->id} berhasil dikonfirmasi.");
        });
    }

    /**
     * Detail export log.
     */
    public function show(ExportLog $exportLog): Response
    {
        abort_unless($exportLog->company_id === Auth::user()->company_id, 403);
        $exportLog->load('createdByUser');
        $batches = Batch::whereIn('id', $exportLog->batch_ids ?? [])->with('productCode')->get();

        return Inertia::render('Company/Export/Show', [
            'export'  => $exportLog,
            'batches' => $batches,
        ]);
    }

    /**
     * Download dokumen ekspor.
     */
    public function downloadDocument(ExportLog $exportLog, string $type)
    {
        abort_unless($exportLog->company_id === Auth::user()->company_id, 403);

        $docs = $exportLog->generated_documents ?? [];
        $path = $docs[$type] ?? null;

        abort_unless($path && Storage::exists($path), 404, 'Dokumen tidak ditemukan.');

        $filename = match($type) {
            'coa'                 => "CoA-{$exportLog->id}.pdf",
            'packing_list'        => "PackingList-{$exportLog->id}.pdf",
            'export_notification' => "Notification-{$exportLog->id}.pdf",
            default               => "Document-{$exportLog->id}.pdf",
        };

        return Storage::download($path, $filename);
    }
}