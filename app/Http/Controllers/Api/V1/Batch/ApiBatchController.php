<?php

namespace App\Http\Controllers\Api\V1\Batch;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Batch\BatchService;
use App\Services\Batch\QrCodeService;
use App\Http\Requests\Batch\CreateBatchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ApiBatchController — REST API untuk batch management.
 *
 * Digunakan oleh mobile app dan hardware integration.
 * Semua response menggunakan format JSON standar MINETRA API.
 */
class ApiBatchController extends Controller
{
    public function __construct(
        private BatchService  $batchService,
        private QrCodeService $qrCodeService
    ) {}

    /**
     * GET /api/v1/company/batches
     * Daftar batch dengan filter dan pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $query = Batch::with(['productCode', 'currentLocation'])
            ->where('company_id', $companyId)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('stage')) {
            $query->where('process_stage', $request->stage);
        }
        if ($request->filled('search')) {
            $query->where('batch_code', 'ILIKE', "%{$request->search}%");
        }
        if ($request->boolean('alerts_only')) {
            $query->where('has_discrepancy', true);
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $batches = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $batches->items() ? array_map(
                fn($b) => $this->formatBatch($b),
                $batches->items()
            ) : [],
            'meta'    => [
                'current_page' => $batches->currentPage(),
                'per_page'     => $batches->perPage(),
                'total'        => $batches->total(),
                'last_page'    => $batches->lastPage(),
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * POST /api/v1/company/batches
     * Buat batch baru.
     */
    public function store(CreateBatchRequest $request): JsonResponse
    {
        $batch = $this->batchService->createBatch([
            'company_id'      => Auth::user()->company_id,
            'product_code_id' => $request->product_code_id,
            'location_id'     => $request->location_id,
            'initial_weight'  => $request->initial_weight,
            'process_stage'   => 'lab_kimia',
            'notes'           => $request->notes,
        ]);

        $this->qrCodeService->generateForBatch($batch);

        return response()->json([
            'success' => true,
            'data'    => $this->formatBatch($batch->fresh(['productCode', 'currentLocation'])),
            'message' => "Batch {$batch->batch_code} berhasil dibuat.",
        ], 201);
    }

    /**
     * GET /api/v1/company/batches/{batch}
     * Detail batch lengkap.
     */
    public function show(Batch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $batch->load([
            'productCode', 'currentLocation', 'parentBatch.productCode',
            'childBatches.productCode', 'checkpoints.location',
            'checkpoints.recordedByUser', 'labAnalyses',
        ]);

        return response()->json([
            'success' => true,
            'data'    => array_merge(
                $this->formatBatch($batch),
                [
                    'parent_batch'  => $batch->parentBatch ? $this->formatBatch($batch->parentBatch) : null,
                    'child_batches' => $batch->childBatches->map(fn($b) => $this->formatBatch($b))->toArray(),
                    'checkpoints'   => $batch->checkpoints->map(fn($cp) => [
                        'id'               => $cp->id,
                        'stage'            => $cp->checkpoint_stage,
                        'weight_recorded'  => $cp->weight_recorded,
                        'weight_loss_pct'  => $cp->weight_loss_pct,
                        'has_discrepancy'  => $cp->has_discrepancy,
                        'is_verified'      => $cp->is_verified,
                        'notes'            => $cp->notes,
                        'location'         => $cp->location?->name,
                        'recorded_by'      => $cp->recordedByUser?->name,
                        'recorded_at'      => $cp->recorded_at?->toIso8601String(),
                    ])->toArray(),
                    'lab_analyses'  => $batch->labAnalyses->map(fn($la) => [
                        'id'           => $la->id,
                        'la_pct'       => $la->la_pct,
                        'ce_pct'       => $la->ce_pct,
                        'pr_pct'       => $la->pr_pct,
                        'nd_pct'       => $la->nd_pct,
                        'y_pct'        => $la->y_pct,
                        'total_reo_pct'=> $la->total_reo_pct,
                        'is_validated' => $la->is_validated,
                        'analyzed_at'  => $la->analyzed_at?->toIso8601String(),
                    ])->toArray(),
                ]
            ),
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/company/batches/{batch}/qr
     * Ambil QR Code sebagai base64 SVG.
     */
    public function qrCode(Batch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);

        $base64 = $this->qrCodeService->getBase64($batch);

        return response()->json([
            'success' => true,
            'data'    => [
                'qr_base64'  => $base64,
                'batch_code' => $batch->batch_code,
                'format'     => 'svg',
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/company/batches/{batch}/timeline
     * Timeline batch untuk mobile display.
     */
    public function timeline(Batch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 403);
        $batch->load(['checkpoints.recordedByUser', 'checkpoints.location']);

        $timeline = $this->batchService->getTimeline($batch);

        return response()->json([
            'success' => true,
            'data'    => $timeline,
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/bim/alerts
     * Daftar batch dengan mass balance alert (BIM only).
     */
    public function alerts(Request $request): JsonResponse
    {
        $alerts = Batch::with(['company', 'productCode', 'currentLocation'])
            ->where('has_discrepancy', true)
            ->whereIn('status', ['active', 'in_process', 'in_transit'])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn($b) => array_merge($this->formatBatch($b), [
                'company_name' => $b->company?->name,
            ]));

        return response()->json([
            'success' => true,
            'data'    => $alerts,
            'message' => 'OK',
        ]);
    }

    /**
     * Helper: format batch untuk response API.
     */
    private function formatBatch(Batch $batch): array
    {
        return [
            'id'             => $batch->id,
            'batch_code'     => $batch->batch_code,
            'lot_number'     => $batch->lot_number,
            'product'        => $batch->productCode ? [
                'id'               => $batch->productCode->id,
                'code'             => $batch->productCode->code,
                'name'             => $batch->productCode->name,
                'is_hazardous'     => $batch->productCode->is_hazardous,
                'is_export_blocked'=> $batch->productCode->is_export_blocked,
            ] : null,
            'process_stage'  => $batch->process_stage,
            'status'         => $batch->status,
            'initial_weight' => $batch->initial_weight,
            'current_weight' => $batch->current_weight,
            'mass_loss_pct'  => $batch->mass_loss_pct,
            'has_discrepancy'=> $batch->has_discrepancy,
            'location'       => $batch->currentLocation ? [
                'id'   => $batch->currentLocation->id,
                'code' => $batch->currentLocation->code,
                'name' => $batch->currentLocation->name,
            ] : null,
            'export_status'  => $batch->export_status,
            'qr_available'   => !empty($batch->qr_code_path),
            'created_at'     => $batch->created_at?->toIso8601String(),
            'updated_at'     => $batch->updated_at?->toIso8601String(),
        ];
    }
}