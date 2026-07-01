<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\CctvCamera;
use App\Models\Checkpoint;
use App\Services\Hardware\MediaMtxPathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CctvController — Live monitoring + manajemen kamera CCTV.
 *
 * ════════════════════════════════════════════════════════════════════════
 * ARSITEKTUR PROVISIONING KAMERA (UI-driven, bukan backend/seeder-only)
 *
 * Company admin menambah/mengedit kamera lewat halaman Settings
 * (Company/Hardware/CctvSettings.vue) — TIDAK perlu akses server, TIDAK
 * perlu edit mediamtx.yml, TIDAK perlu restart container. Alasan memilih
 * UI dibanding backend-only:
 *
 *   1. Realistis secara operasional — instalasi kamera fisik baru adalah
 *      kejadian yang terjadi di lapangan (mis. teknisi baru pasang kamera
 *      di Wet Process), bukan saat deployment kode. Admin butuh bisa
 *      langsung mendaftarkannya sendiri di jam kerja, tanpa menunggu
 *      developer men-deploy ulang seeder.
 *   2. Konsisten dengan pola industri — sistem NVR/CCTV komersial
 *      (Hikvision, Dahua, dll) selalu menyediakan panel admin untuk
 *      tambah/hapus kamera, bukan file konfigurasi statis.
 *   3. mediamtx sudah punya REST config API bawaan yang mendukung
 *      tambah/hapus path secara runtime (lihat MediaMtxPathService) —
 *      infrastrukturnya sudah tersedia, tinggal dipakai.
 *
 * store()/update() men-deteksi apakah rtsp_url_real yang di-submit SUDAH
 * dipakai kamera lain di company yang sama (kasus umum saat ini: 1 kamera
 * fisik dipakai bersama untuk beberapa checkpoint/stage sekaligus, lihat
 * PtTimahCctvCameraSeeder) — jika ya, hls_url path yang SUDAH ADA dipakai
 * ulang (tidak membuka koneksi RTSP duplikat ke perangkat fisik yang sama).
 * Jika benar-benar kamera baru, path mediamtx baru di-provisioning otomatis
 * via MediaMtxPathService::ensurePath().
 * ════════════════════════════════════════════════════════════════════════
 */
class CctvController extends Controller
{
    /**
     * Stage/checkpoint yang valid untuk location_key — dipakai untuk
     * validasi input DAN dropdown pilihan di form Settings. Harus selalu
     * sinkron dengan ProcessController::STAGES dan RfidService::STAGE_FLOW.
     */
    public const VALID_STAGES = [
        'receiving'     => 'Receiving Area',
        'wet_process'   => 'Wet Process',
        'dry_process'   => 'Dry Process',
        'warehouse'     => 'LTJ Warehouse',
        'project_plant' => 'Lab Analisis LTJ',
    ];

    public function __construct(
        private MediaMtxPathService $mediaMtx,
    ) {}

    // ═══════════════════════════════════════════════════════════════════════
    // WEB — Inertia Pages
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Monitor halaman utama CCTV (Inertia) — live view only.
     * GET /company/hardware/cctv
     */
    public function monitor(): Response
    {
        $companyId = Auth::user()->company_id ?? 1;

        $cameras = CctvCamera::where('company_id', $companyId)
            ->active()
            ->orderBy('id')
            ->get();

        // Self-healing: pastikan setiap kamera real punya path mediamtx yang
        // hidup. Path dinamis hasil provisioning API hanya ada di memori
        // runtime mediamtx (tidak ditulis ke YAML) — jika container
        // mediamtx pernah restart, path akan hilang. ensurePath() bersifat
        // idempotent (cek dulu sebelum membuat), jadi aman dipanggil di
        // setiap page load tanpa biaya berarti.
        foreach ($cameras as $cam) {
            if (!$cam->isSimulationCamera() && $cam->rtsp_url_real && $cam->hls_url) {
                $pathName = $this->extractPathNameFromHlsUrl($cam->hls_url);
                if ($pathName) {
                    $this->mediaMtx->ensurePath($pathName, $cam->rtsp_url_real);
                }
            }
        }

        $camerasPayload = $cameras->map(fn ($cam) => [
            'id'            => $cam->id,
            'name'          => $cam->name,
            'location_key'  => $cam->location_key,
            'hls_url'       => $cam->effective_hls_url,
            'rtsp_url'      => $cam->effective_rtsp_url,
            // Status per-kamera — armada bisa CAMPURAN (mis. Gate 1 kamera
            // fisik asli, Wet/Warehouse masih simulasi karena hardware
            // belum terpasang). Lihat CctvCamera::isSimulationCamera().
            'is_simulation' => $cam->isSimulationCamera(),
        ]);

        // Fallback ke default dari env jika belum ada kamera di DB
        if ($camerasPayload->isEmpty()) {
            $hlsBase = rtrim(config('cctv.hls_base_url', ''), '/');
            if ($hlsBase) {
                $camerasPayload = collect([[
                    'id'            => null,
                    'name'          => 'CCTV Utama',
                    'location_key'  => null,
                    'hls_url'       => $hlsBase . '/cam_gate1/index.m3u8',
                    'rtsp_url'      => config('cctv.rtsp_url', ''),
                    'is_simulation' => true,
                ]]);
            }
        }

        return Inertia::render('Company/Hardware/CctvMonitor', [
            'cameras'            => $camerasPayload,
            'hls_base_url'       => config('cctv.hls_base_url', ''),
            'has_cctv'           => $camerasPayload->isNotEmpty(),
            // Badge "SIMULATION" global hanya tampil jika SELURUH armada
            // masih simulasi — begitu ada satu kamera fisik asli aktif,
            // badge ini disembunyikan agar tidak menyesatkan operator.
            'simulation'         => $camerasPayload->isNotEmpty() && $camerasPayload->every(fn ($c) => $c['is_simulation']),
            'can_manage_cameras' => Auth::user()->hasRole('company_admin'),
        ]);
    }

    /**
     * Settings halaman manajemen kamera (Inertia) — CRUD penuh.
     * GET /company/hardware/cctv/settings
     */
    public function settings(): Response
    {
        $companyId = Auth::user()->company_id ?? 1;

        $cameras = CctvCamera::where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        return Inertia::render('Company/Hardware/CctvSettings', [
            'cameras' => $cameras->map(fn ($cam) => [
                'id'                   => $cam->id,
                'name'                 => $cam->name,
                'location_key'         => $cam->location_key,
                'is_active'            => $cam->is_active,
                'is_simulation'        => $cam->isSimulationCamera(),
                'rtsp_url_real'        => $cam->rtsp_url_real,
                'rtsp_url_simulation'  => $cam->rtsp_url_simulation,
                'hls_url'              => $cam->hls_url,
                'hls_url_effective'    => $cam->effective_hls_url,
                'rtsp_url_effective'   => $cam->effective_rtsp_url,
            ]),
            'valid_stages' => collect(self::VALID_STAGES)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'hls_base_url' => config('cctv.hls_base_url', ''),
        ]);
    }

    /**
     * Simpan kamera baru.
     * POST /company/hardware/cctv
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCameraRequest($request);
        $companyId = Auth::user()->company_id;

        $camera = CctvCamera::create(array_merge(
            $this->buildBaseAttributes($validated),
            ['company_id' => $companyId]
        ));

        $this->provisionOrReuseLiveView($camera, $companyId);

        return back()->with('success', "Kamera CCTV '{$camera->name}' berhasil ditambahkan.");
    }

    /**
     * Update kamera.
     * PUT /company/hardware/cctv/{camera}
     */
    public function update(Request $request, CctvCamera $camera): RedirectResponse
    {
        abort_if($camera->company_id !== Auth::user()->company_id, 403);

        $validated  = $this->validateCameraRequest($request);
        $companyId  = $camera->company_id;
        $wasReal    = !$camera->isSimulationCamera();
        $oldHlsUrl  = $camera->hls_url;

        $camera->update($this->buildBaseAttributes($validated));
        $camera->refresh();

        if ($camera->isSimulationCamera()) {
            // Beralih ke simulasi (atau tetap simulasi) — bersihkan hls_url
            // real sebelumnya jika ada, dan lepas path mediamtx lama bila
            // sudah tidak dipakai kamera lain.
            if ($wasReal && $oldHlsUrl) {
                $this->cleanupLiveViewIfUnused($oldHlsUrl, $camera->id, $companyId);
            }
            $camera->update(['hls_url' => null]);
        } else {
            $this->provisionOrReuseLiveView($camera, $companyId, $oldHlsUrl);
        }

        return back()->with('success', "Kamera CCTV '{$camera->name}' berhasil diperbarui.");
    }

    /**
     * Hapus kamera.
     * DELETE /company/hardware/cctv/{camera}
     */
    public function destroy(CctvCamera $camera): RedirectResponse
    {
        abort_if($camera->company_id !== Auth::user()->company_id, 403);

        $name       = $camera->name;
        $hlsUrl     = $camera->hls_url;
        $wasReal    = !$camera->isSimulationCamera();
        $companyId  = $camera->company_id;
        $cameraId   = $camera->id;

        $camera->delete();

        if ($wasReal && $hlsUrl) {
            $this->cleanupLiveViewIfUnused($hlsUrl, $cameraId, $companyId);
        }

        return back()->with('success', "Kamera CCTV '{$name}' berhasil dihapus.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE — Validasi & Provisioning
    // ═══════════════════════════════════════════════════════════════════════

    private function validateCameraRequest(Request $request): array
    {
        return $request->validate([
            'name'                 => 'required|string|max:100',
            'location_key'         => 'nullable|string|in:' . implode(',', array_keys(self::VALID_STAGES)),
            'is_simulation'        => 'required|boolean',
            // rtsp_url_real WAJIB diisi jika kamera ini REAL (is_simulation=false)
            'rtsp_url_real'        => 'required_if:is_simulation,false|nullable|string|max:500|regex:/^rtsps?:\/\//i',
            // rtsp_url_simulation opsional — jika kosong, accessor otomatis
            // menurunkan dari simulationStreamName() map bawaan.
            'rtsp_url_simulation'  => 'nullable|string|max:500|regex:/^rtsps?:\/\//i',
            'is_active'            => 'boolean',
        ], [
            'rtsp_url_real.required_if' => 'RTSP URL kamera fisik wajib diisi untuk kamera real.',
            'rtsp_url_real.regex'       => 'RTSP URL harus diawali rtsp:// atau rtsps://.',
            'rtsp_url_simulation.regex' => 'RTSP URL harus diawali rtsp:// atau rtsps://.',
        ]);
    }

    private function buildBaseAttributes(array $validated): array
    {
        return [
            'name'                => $validated['name'],
            'location_key'        => $validated['location_key'] ?? null,
            'is_simulation'       => (bool) $validated['is_simulation'],
            'rtsp_url_real'       => $validated['rtsp_url_real'] ?? null,
            'rtsp_url_simulation' => $validated['rtsp_url_simulation'] ?? null,
            'is_active'           => $validated['is_active'] ?? true,
            // rtsp_url legacy — selaras dengan mode yang aktif, dipakai
            // hanya sebagai fallback terakhir oleh accessor.
            'rtsp_url' => ((bool) $validated['is_simulation'])
                ? ($validated['rtsp_url_simulation'] ?? null)
                : ($validated['rtsp_url_real'] ?? null),
        ];
    }

    /**
     * Provisioning path live-view mediamtx untuk kamera REAL — dengan
     * deteksi reuse: jika kamera lain di company yang sama SUDAH punya
     * rtsp_url_real yang identik, pakai ulang hls_url-nya (satu kamera
     * fisik boleh melayani banyak checkpoint tanpa koneksi RTSP duplikat).
     */
    private function provisionOrReuseLiveView(CctvCamera $camera, int $companyId, ?string $previousHlsUrl = null): void
    {
        $existing = CctvCamera::where('company_id', $companyId)
            ->where('id', '!=', $camera->id)
            ->where('is_simulation', false)
            ->where('rtsp_url_real', $camera->rtsp_url_real)
            ->whereNotNull('hls_url')
            ->first();

        if ($existing) {
            Log::info('CctvController — reuse hls_url dari kamera fisik yang sama.', [
                'camera_id'          => $camera->id,
                'reused_from_camera' => $existing->id,
                'hls_url'            => $existing->hls_url,
            ]);
            $camera->update(['hls_url' => $existing->hls_url]);

            // Jika kamera ini sebelumnya punya path mediamtx SENDIRI (mis.
            // baru saja diedit rtsp_url_real-nya menjadi sama dengan kamera
            // lain) dan tidak ada yang memakainya lagi, bersihkan.
            if ($previousHlsUrl && $previousHlsUrl !== $existing->hls_url) {
                $this->cleanupLiveViewIfUnused($previousHlsUrl, $camera->id, $companyId);
            }
            return;
        }

        // Benar-benar kamera baru — provisioning path mediamtx baru.
        $pathName = $this->mediaMtx->deriveLivePathName($camera->id);
        $ok       = $this->mediaMtx->ensurePath($pathName, $camera->rtsp_url_real);

        if ($ok) {
            $camera->update(['hls_url' => $this->mediaMtx->buildHlsUrl($pathName)]);
        } else {
            Log::warning('CctvController — provisioning path mediamtx gagal, live-view tidak tersedia sampai mediamtx dapat dihubungi.', [
                'camera_id' => $camera->id,
                'path'      => $pathName,
            ]);
        }

        if ($previousHlsUrl && $previousHlsUrl !== $camera->hls_url) {
            $this->cleanupLiveViewIfUnused($previousHlsUrl, $camera->id, $companyId);
        }
    }

    /**
     * Hapus path mediamtx HANYA jika tidak ada kamera lain (di company yang
     * sama, kecuali kamera yang sedang diproses) yang masih memakai hls_url
     * yang sama — mencegah live-view kamera lain ikut mati karena satu
     * baris CctvCamera yang berbagi kamera fisik dihapus/diedit.
     */
    private function cleanupLiveViewIfUnused(string $hlsUrl, int $excludeCameraId, int $companyId): void
    {
        $stillUsed = CctvCamera::where('company_id', $companyId)
            ->where('id', '!=', $excludeCameraId)
            ->where('hls_url', $hlsUrl)
            ->exists();

        if ($stillUsed) {
            return;
        }

        $pathName = $this->extractPathNameFromHlsUrl($hlsUrl);
        if ($pathName) {
            $this->mediaMtx->removePath($pathName);
        }
    }

    /**
     * Ekstrak nama path mediamtx dari HLS URL, mis.
     * "http://localhost:8888/live_cam_5/index.m3u8" → "live_cam_5".
     */
    private function extractPathNameFromHlsUrl(string $hlsUrl): ?string
    {
        $path = parse_url($hlsUrl, PHP_URL_PATH);
        if (!$path) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path)));
        return $segments[0] ?? null;
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

        // Sama seperti monitor()/settings() — badge "simulation" merepresentasikan
        // SELURUH armada company ini, bukan flag global env('CCTV_USE_REAL').
        // Lihat CctvCamera::isSimulationCamera().
        $companyId    = Auth::user()->company_id ?? 1;
        $allSimulated = CctvCamera::where('company_id', $companyId)
            ->active()
            ->get()
            ->every(fn ($c) => $c->isSimulationCamera());

        try {
            $response = Http::timeout(3)->get($apiUrl . '/v3/paths/list');
            if ($response->successful()) {
                return response()->json([
                    'success'    => true,
                    'mediamtx'   => 'online',
                    'simulation' => $allSimulated,
                    'paths'      => $response->json('items', []),
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('CctvController::streamStatus — MediaMTX tidak terjangkau.', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success'    => false,
            'mediamtx'   => 'offline',
            'simulation' => $allSimulated,
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
