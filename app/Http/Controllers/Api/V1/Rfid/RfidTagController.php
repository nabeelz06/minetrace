<?php

namespace App\Http\Controllers\Api\V1\Rfid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rfid\ReasonRequest;
use App\Models\Batch;
use App\Services\Hardware\RfidTagService;
use App\Support\RfidApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Siklus hidup tag: lepas/ganti/hilang/rusak (beralasan, tercatat) + riwayat per batch. */
class RfidTagController extends Controller
{
    public function __construct(private readonly RfidTagService $tags) {}

    public function release(ReasonRequest $request, int $batchId): JsonResponse
    {
        Gate::authorize('rfid.operate');

        $batch = Batch::where('company_id', $request->user()->company_id)->findOrFail($batchId);

        try {
            $uid = $this->tags->release($batch, $request->user(), $request->string('reason')->toString(), $request->input('note'));
        } catch (\DomainException) {
            return RfidApi::fail('tag_none');
        }

        return RfidApi::ok('tag_released', ['batch_id' => $batch->id, 'batch_code' => $batch->batch_code, 'released_uid' => $uid]);
    }

    public function history(Request $request, int $batchId): JsonResponse
    {
        Gate::authorize('rfid.view');

        $batch = Batch::where('company_id', $request->user()->company_id)->findOrFail($batchId);

        return RfidApi::ok('ok', $this->tags->history($batch));
    }
}
