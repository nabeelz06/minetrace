<?php

namespace App\Http\Controllers\Api\V1\Verify;

use App\Http\Controllers\Controller;
use App\Services\Batch\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ApiVerifyController — endpoint publik untuk verifikasi QR Code.
 *
 * Dapat diakses tanpa auth — untuk scan dari device apapun.
 * Rate limited: 10 req/menit per IP.
 */
class ApiVerifyController extends Controller
{
    public function __construct(
        private QrCodeService $qrCodeService
    ) {}

    /**
     * GET /api/v1/verify/batch/{batchId}?code={batch_code}&cs={checksum}
     * Verifikasi QR Code batch — public endpoint.
     */
    public function verifyBatch(int $batchId, Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'cs'   => 'required|string',
        ]);

        $result = $this->qrCodeService->verify(
            $batchId,
            $request->string('code')->toString(),
            $request->string('cs')->toString()
        );

        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
            ], 422);
        }

        $batch = $result['batch'];

        return response()->json([
            'success' => true,
            'data'    => [
                'batch_code'    => $batch->batch_code,
                'product'       => $batch->productCode?->name,
                'product_code'  => $batch->productCode?->code,
                'company'       => $batch->company?->name,
                'process_stage' => $batch->process_stage,
                'status'        => $batch->status,
                'current_weight'=> $batch->current_weight,
                'location'      => $batch->currentLocation?->name,
                'is_hazardous'  => $batch->productCode?->is_hazardous ?? false,
                'verified_at'   => now()->toIso8601String(),
            ],
            'message' => '✅ Batch terverifikasi — data asli dari MINETRA.',
        ]);
    }
}