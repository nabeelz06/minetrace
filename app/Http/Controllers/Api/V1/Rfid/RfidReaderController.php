<?php

namespace App\Http\Controllers\Api\V1\Rfid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rfid\StoreReaderRequest;
use App\Http\Requests\Rfid\UpdateReaderRequest;
use App\Models\RfidReader;
use App\Services\Hardware\RfidDeviceService;
use App\Support\RfidApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Registri perangkat RFID. Dikelola company_admin; peran lain hanya melihat (tanpa kunci).
 * Kunci perangkat ditampilkan SEKALI (saat dibuat/dirotasi) dan hanya hash-nya yang disimpan.
 */
class RfidReaderController extends Controller
{
    public function __construct(private readonly RfidDeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('rfid.view');

        $readers = RfidReader::where('company_id', $request->user()->company_id)
            ->orderBy('code')->get()
            ->map(fn (RfidReader $r) => $this->devices->present($r));

        return RfidApi::ok('ok', $readers->all(), 200, [
            'offline_after_seconds' => config('rfid.offline_after_seconds'),
        ]);
    }

    public function store(StoreReaderRequest $request): JsonResponse
    {
        Gate::authorize('rfid.manage');

        [$reader, $key] = $this->devices->create($request->user()->company_id, $request->validated(), $request->user()->id);

        return RfidApi::ok('reader_created', [
            'reader'     => $this->devices->present($reader),
            'device_key' => $key,   // SEKALI
        ], 201);
    }

    public function update(UpdateReaderRequest $request, int $id): JsonResponse
    {
        Gate::authorize('rfid.manage');

        $reader = $this->find($request, $id);
        $reader->update($request->validated());

        return RfidApi::ok('reader_updated', ['reader' => $this->devices->present($reader->fresh())]);
    }

    public function rotateKey(Request $request, int $id): JsonResponse
    {
        Gate::authorize('rfid.manage');

        $reader = $this->find($request, $id);
        $key    = $this->devices->rotateKey($reader);

        return RfidApi::ok('reader_key_rotated', [
            'reader'     => $this->devices->present($reader->fresh()),
            'device_key' => $key,
        ]);
    }

    private function find(Request $request, int $id): RfidReader
    {
        return RfidReader::where('company_id', $request->user()->company_id)->findOrFail($id);
    }
}
