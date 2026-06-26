<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\CctvCamera;
use App\Models\Checkpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CctvController extends Controller
{
    public function index(): Response
    {
        $companyId = Auth::user()->company_id ?? 1;

        $cameras = CctvCamera::where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->map(fn($cam) => [
                'id'          => $cam->id,
                'name'        => $cam->name,
                'location_key'=> $cam->location_key,
                'hls_url'     => $cam->effective_hls_url,
                'rtsp_url'    => $cam->effective_rtsp_url,
            ]);

        // Jika belum ada kamera di DB, tampilkan satu default dari env
        if ($cameras->isEmpty()) {
            $hlsBase = rtrim(config('cctv.hls_base_url', ''), '/');
            if ($hlsBase) {
                $cameras = collect([[
                    'id'           => null,
                    'name'         => 'CCTV Utama',
                    'location_key' => null,
                    'hls_url'      => $hlsBase . '/stream.m3u8',
                    'rtsp_url'     => config('cctv.rtsp_url', ''),
                ]]);
            }
        }

        return Inertia::render('Company/Hardware/CctvMonitor', [
            'cameras'       => $cameras,
            'hls_base_url'  => config('cctv.hls_base_url', ''),
            'has_cctv'      => $cameras->isNotEmpty(),
        ]);
    }

    public function snapshot(Request $request, int $cameraId): JsonResponse
    {
        $companyId  = Auth::user()->company_id ?? 1;
        $camera     = CctvCamera::where('company_id', $companyId)->findOrFail($cameraId);

        $checkpointId = $request->input('checkpoint_id');
        $batchId      = $request->input('batch_id');

        if (!$checkpointId || !$batchId) {
            return response()->json(['success' => false, 'message' => 'batch_id dan checkpoint_id diperlukan.'], 422);
        }

        $checkpoint = Checkpoint::findOrFail((int) $checkpointId);

        CaptureCctvSnapshotJob::dispatch((int) $batchId, (int) $checkpointId, 'manual_trigger');

        return response()->json([
            'success' => true,
            'message' => 'Snapshot CCTV sedang diproses.',
            'camera'  => $camera->name,
        ]);
    }
}
