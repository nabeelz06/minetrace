<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\CctvCamera;
use App\Models\Checkpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class CctvController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    // WEB — Inertia Pages
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Monitor halaman utama CCTV (Inertia).
     * GET /company/hardware/cctv
     */
    public function monitor(): Response
    {
        $companyId = Auth::user()->company_id ?? 1;
        $useReal   = (bool) env('CCTV_USE_REAL', false);

        $cameras = CctvCamera::where('company_id', $companyId)
            ->active()
            ->orderBy('id')
            ->get()
            ->map(fn ($cam) => [
                'id'           => $cam->id,
                'name'         => $cam->name,
                'location_key' => $cam->location_key,
                'hls_url'      => $cam->effective_hls_url,
                'rtsp_url'     => $cam->effective_rtsp_url,
            ]);

        // Fallback ke default dari env jika belum ada kamera di DB
        if ($cameras->isEmpty()) {
            $hlsBase = rtrim(config('cctv.hls_base_url', ''), '/');
            if ($hlsBase) {
                $cameras = collect([[
                    'id'           => null,
                    'name'         => 'CCTV Utama',
                    'location_key' => null,
                    'hls_url'      => $hlsBase . '/cam_gate1/index.m3u8',
                    'rtsp_url'     => config('cctv.rtsp_url', ''),
                ]]);
            }
        }

        return Inertia::render('Company/Hardware/CctvMonitor', [
            'cameras'      => $cameras,
            'hls_base_url' => config('cctv.hls_base_url', ''),
            'has_cctv'     => $cameras->isNotEmpty(),
            'simulation'   => !$useReal,
        ]);
    }

    /**
     * Settings halaman manajemen kamera (Inertia).
     * GET /company/hardware/cctv/settings
     */
    public function index(): Response
    {
        $companyId = Auth::user()->company_id ?? 1;

        $cameras = CctvCamera::where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        return Inertia::render('Company/Hardware/CctvMonitor', [
            'cameras'      => $cameras->map(fn ($cam) => [
                'id'           => $cam->id,
                'name'         => $cam->name,
                'location_key' => $cam->location_key,
                'hls_url'      => $cam->hls_url,
                'rtsp_url'     => $cam->rtsp_url,
                'is_active'    => $cam->is_active,
                'hls_url_effective' => $cam->effective_hls_url,
            ]),
            'hls_base_url' => config('cctv.hls_base_url', ''),
            'has_cctv'     => $cameras->isNotEmpty(),
            'simulation'   => !(bool) env('CCTV_USE_REAL', false),
        ]);
    }

    /**
     * Simpan kamera baru.
     * POST /company/hardware/cctv
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'location_key' => 'nullable|string|max:50',
            'rtsp_url'     => 'nullable|url|max:500',
            'hls_url'      => 'nullable|url|max:500',
            'is_active'    => 'boolean',
        ]);

        CctvCamera::create(array_merge($validated, [
            'company_id' => Auth::user()->company_id,
        ]));

        return back()->with('success', 'Kamera CCTV berhasil ditambahkan.');
    }

    /**
     * Update kamera.
     * PUT /company/hardware/cctv/{camera}
     */
    public function update(Request $request, CctvCamera $camera): \Illuminate\Http\RedirectResponse
    {
        abort_if($camera->company_id !== Auth::user()->company_id, 403);

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'location_key' => 'nullable|string|max:50',
            'rtsp_url'     => 'nullable|url|max:500',
            'hls_url'      => 'nullable|url|max:500',
            'is_active'    => 'boolean',
        ]);

        $camera->update($validated);

        return back()->with('success', 'Kamera CCTV berhasil diperbarui.');
    }

    /**
     * Hapus kamera.
     * DELETE /company/hardware/cctv/{camera}
     */
    public function destroy(CctvCamera $camera): \Illuminate\Http\RedirectResponse
    {
        abort_if($camera->company_id !== Auth::user()->company_id, 403);
        $camera->delete();
        return back()->with('success', 'Kamera CCTV berhasil dihapus.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // API — JSON Endpoints (dipanggil dari CctvMonitor.vue via polling)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Ambil status stream dari MediaMTX API.
     * GET /api/v1/company/cctv/status
     */
    public function streamStatus(): JsonResponse
    {
        $apiUrl = rtrim(config('cctv.mediamtx_api', 'http://localhost:9997'), '/');

        try {
            $response = Http::timeout(3)->get($apiUrl . '/v3/paths/list');
            if ($response->successful()) {
                return response()->json([
                    'success'    => true,
                    'mediamtx'   => 'online',
                    'simulation' => !(bool) env('CCTV_USE_REAL', false),
                    'paths'      => $response->json('items', []),
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('CctvController::streamStatus — MediaMTX tidak terjangkau.', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success'    => false,
            'mediamtx'   => 'offline',
            'simulation' => !(bool) env('CCTV_USE_REAL', false),
            'paths'      => [],
        ]);
    }

    /**
     * Ambil 5 snapshot CCTV terbaru dari RFID scan.
     * GET /api/v1/company/cctv/recent-snapshots
     *
     * Response: [{ id, batch_code, stage, camera_name, photo_url, captured_at, rfid_uid, scan_type }]
     */
    public function recentSnapshots(): JsonResponse
    {
        $companyId = Auth::user()->company_id ?? 1;

        try {
            // Gunakan whereRaw untuk perbandingan kolom json di PostgreSQL.
            // `where('photos', '!=', '[]')` menghasilkan `"photos" != []` yang
            // merupakan SQL tidak valid — PostgreSQL json type tidak punya operator !=
            // dengan string literal ('unknown'). Cast ke ::text agar kompatibel.
            $checkpoints = Checkpoint::with('batch')
                ->whereHas('batch', fn ($q) => $q->where('company_id', $companyId))
                ->whereNotNull('photos')
                ->whereRaw("(photos)::text != '[]'")
                ->whereNotNull('rfid_scan_code')
                ->latest('recorded_at')
                ->limit(30)
                ->get();

            $snapshots = $checkpoints
                ->flatMap(function ($cp) {
                    // photos di-cast sebagai array oleh Checkpoint model ($casts)
                    $photos = is_array($cp->photos) ? $cp->photos : [];

                    return collect($photos)
                        ->filter(fn ($p) => is_array($p) && isset($p['type']))
                        ->filter(fn ($p) => in_array($p['type'], [
                            'rfid_snapshot',
                            'departure_snapshot',
                            'manual_trigger',
                        ]))
                        ->map(fn ($photo) => [
                            'id'              => $cp->id,
                            'batch_code'      => $cp->batch?->batch_code ?? '—',
                            'stage'           => $photo['stage']
                                             ?? $cp->checkpoint_data['scan_stage']
                                             ?? $cp->checkpoint_stage
                                             ?? null,
                            'camera_name'     => $photo['camera_name'] ?? 'CCTV',
                            'photo_url'       => $photo['url'] ?? null,
                            'captured_at'     => $photo['captured_at'] ?? null,
                            'rfid_uid'        => $photo['rfid_uid'] ?? $cp->rfid_scan_code,
                            'scan_type'       => $photo['scan_type'] ?? 'scan_in',
                            'filesize_kb'     => $photo['filesize_kb'] ?? null,
                            'sha256'          => $photo['sha256'] ?? null,
                            'snapshot_status' => $cp->snapshot_status,
                        ]);
                })
                ->take(5)
                ->values();

            return response()->json([
                'success' => true,
                'data'    => $snapshots,
            ]);
        } catch (\Throwable $e) {
            Log::error('CctvController::recentSnapshots — query gagal.', [
                'userId'    => Auth::id(),
                'companyId' => $companyId,
                'exception' => $e->getMessage(),
            ]);

            // Zero snapshots adalah state valid — jangan lempar 500 ke client.
            return response()->json([
                'success' => true,
                'data'    => [],
            ]);
        }
    }

    /**
     * Trigger snapshot manual untuk checkpoint tertentu.
     * POST /api/v1/company/cctv/cameras/{id}/snapshot
     */
    public function snapshot(Request $request, int $cameraId): JsonResponse
    {
        $companyId = Auth::user()->company_id ?? 1;
        $camera    = CctvCamera::where('company_id', $companyId)->findOrFail($cameraId);

        $validated = $request->validate([
            'checkpoint_id' => 'required|integer|exists:checkpoints,id',
        ]);

        dispatch(new CaptureCctvSnapshotJob(
            checkpointId: (int) $validated['checkpoint_id'],
            companyId:    $companyId,
            stageName:    $camera->location_key,
            scanType:     'manual_trigger',
        ))->onQueue(config('cctv.queue', 'default'));

        return response()->json([
            'success' => true,
            'message' => 'Snapshot CCTV sedang diproses.',
            'camera'  => $camera->name,
        ]);
    }
}
