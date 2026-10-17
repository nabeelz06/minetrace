<?php

namespace App\Http\Controllers\Api\V1\Rfid;

use App\Http\Controllers\Controller;
use App\Models\RfidReadLog;
use App\Services\Hardware\RfidReadLogService;
use App\Support\RfidApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Log setiap pembacaan RFID (rfid_read_logs) + status/tautan foto CCTV per log.
 * Kursor sama dengan umpan scan: since_id (polling langsung) / before_id (halaman lama).
 */
class RfidReadLogController extends Controller
{
    private const MAX = 100;

    public function __construct(private readonly RfidReadLogService $logs) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        $limit = min(max($request->integer('limit', 30), 1), self::MAX);

        $query = RfidReadLog::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['reader:id,code,name', 'batch:id,batch_code', 'snapshot:id,file_path'])
            ->when(in_array($request->query('result'), RfidReadLog::RESULTS, true), fn ($q) => $q->where('result', $request->query('result')))
            ->when(in_array($request->query('action'), RfidReadLog::ACTIONS, true), fn ($q) => $q->where('action', $request->query('action')))
            ->when($request->filled('reader_id'), fn ($q) => $q->where('reader_id', $request->integer('reader_id')))
            ->when($request->filled('stage'), fn ($q) => $q->where('stage', $request->string('stage')))
            ->when($request->filled('epc'), fn ($q) => $q->where('epc', 'like', '%' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('epc'))) . '%'));

        if ($request->filled('since_id')) {
            $rows = $query->where('id', '>', $request->integer('since_id'))->orderByDesc('id')->limit($limit)->get();
        } else {
            $rows = $query->when($request->filled('before_id'), fn ($q) => $q->where('id', '<', $request->integer('before_id')))
                ->orderByDesc('id')->limit($limit)->get();
        }

        return RfidApi::ok('ok', $rows->map(fn (RfidReadLog $l) => $this->logs->present($l))->all(), 200, [
            'next_before_id' => $rows->count() === $limit ? $rows->last()?->id : null,
        ]);
    }
}
