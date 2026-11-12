<?php

namespace App\Http\Controllers\Api\V1\Rfid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rfid\ReasonRequest;
use App\Http\Requests\Rfid\TagUidRequest;
use App\Models\Batch;
use App\Services\Hardware\RfidTagService;
use App\Support\RfidApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Siklus hidup tag: lepas/ganti/hilang/rusak (beralasan, tercatat) + riwayat per batch, dan "Kelola Tag" berbasis UID:
 * cek status tag, tag terbaru, lepas tag tanpa tahu batch-nya, dan reset uji (khusus administrator, bisa dimatikan).
 */
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

    /** GET tags/lookup?uid= — status satu tag + pratinjau reset (rfid.view). */
    public function lookup(TagUidRequest $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        return RfidApi::ok('ok', $this->tags->lookup($request->user()->company_id, $request->string('uid')->toString()));
    }

    /** GET tags/recent — tag yang terakhir dibaca reader perusahaan, satu baris per UID (rfid.view). */
    public function recent(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        return RfidApi::ok('ok', $this->tags->recent($request->user()->company_id, min(max($request->integer('limit', 12), 1), 30)));
    }

    /** POST tags/release — lepas tag lewat UID, alasan wajib (rfid.operate). */
    public function releaseByUid(TagUidRequest $request): JsonResponse
    {
        Gate::authorize('rfid.operate');

        try {
            $r = $this->tags->releaseByUid(
                $request->user()->company_id, $request->string('uid')->toString(), $request->user(),
                $request->string('reason')->toString(), $request->input('note'),
            );
        } catch (\DomainException) {
            return RfidApi::fail('tag_free');
        }

        return RfidApi::ok('tag_released', $r + ['released_uid' => $r['uid']]);
    }

    /** POST tags/reset — reset uji (rfid.manage + config rfid.test_reset_enabled). */
    public function reset(TagUidRequest $request): JsonResponse
    {
        Gate::authorize('rfid.manage');

        if (! config('rfid.test_reset_enabled')) {
            return RfidApi::fail('reset_disabled', 403);
        }

        try {
            $r = $this->tags->resetByUid($request->user()->company_id, $request->string('uid')->toString(), $request->user(), $request->input('note'));
        } catch (\DomainException $e) {
            return RfidApi::fail($e->getMessage());
        }

        return RfidApi::ok('tag_reset', $r);
    }

    public function history(Request $request, int $batchId): JsonResponse
    {
        Gate::authorize('rfid.view');

        $batch = Batch::where('company_id', $request->user()->company_id)->findOrFail($batchId);

        return RfidApi::ok('ok', $this->tags->history($batch));
    }
}
