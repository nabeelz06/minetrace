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

    /**
     * GET batch-search?q=&tag=untagged|tagged|all&limit=
     * Pemilih batch untuk registrasi/riwayat — pencarian di server, selalu dibatasi perusahaan & jumlah.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        $limit = min(max($request->integer('limit', 20), 1), 50);
        $q     = trim((string) $request->query('q', ''));
        $tag   = $request->query('tag', 'untagged');

        $rows = Batch::with('productCode:id,code,name')
            ->where('company_id', $request->user()->company_id)
            ->whereNotIn('status', ['exported', 'terminated', 'transformed', 'split'])
            ->when($request->filled('id'), fn ($b) => $b->whereKey($request->integer('id')))
            ->when($tag === 'untagged', fn ($b) => $b->whereNull('rfid_tag'))
            ->when($tag === 'tagged',   fn ($b) => $b->whereNotNull('rfid_tag'))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('batch_code', 'ILIKE', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%')
                ->orWhere('rfid_tag', 'ILIKE', strtoupper(str_replace([' ', ':', '-'], '', $q)) . '%')))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'batch_code', 'product_code_id', 'process_stage', 'status', 'rfid_tag', 'current_weight']);

        return RfidApi::ok('ok', $rows->map(fn (Batch $b) => [
            'id'           => $b->id,
            'batch_code'   => $b->batch_code,
            'product_code' => $b->productCode?->code,
            'product_name' => $b->productCode?->name,
            'stage'        => $b->process_stage,
            'status'       => $b->status,
            'rfid_tag'     => $b->rfid_tag,
            'weight_kg'    => (float) $b->current_weight,
        ])->all());
    }

    public function history(Request $request, int $batchId): JsonResponse
    {
        Gate::authorize('rfid.view');

        $batch = Batch::where('company_id', $request->user()->company_id)->findOrFail($batchId);

        return RfidApi::ok('ok', $this->tags->history($batch));
    }
}
