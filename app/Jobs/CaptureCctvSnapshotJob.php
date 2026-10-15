<?php

namespace App\Jobs;

use App\Models\CctvCamera;
use App\Models\CctvSnapshot;
use App\Models\Checkpoint;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CaptureCctvSnapshotJob — Ambil CCTV snapshot via ffmpeg saat RFID scan.
 *
 * IMPROVEMENTS over Phase 2:
 *   A3 — MediaMTX readiness probe (5 retries × 500ms) before first ffmpeg call
 *   A4 — Structured failure entry written to photos[] on exhaustion
 *   A5 — "mode": "simulation"|"real" persisted in every photo entry
 */
class CaptureCctvSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public int   $timeout = 60;
    public array $backoff  = [5, 15, 30];

    private const TZ           = 'Asia/Jakarta';
    private const SNAPSHOT_DIR = 'cctv-snapshots';

    // A3: readiness probe constants
    private const PROBE_RETRIES      = 5;
    private const PROBE_INTERVAL_US  = 500_000; // 0.5 s

    public function __construct(
        private int     $checkpointId,
        private int     $companyId,
        private ?string $stageName  = null,
        private string  $scanType   = 'scan_in',
        private string  $rfidUid    = '',
    ) {}

    public function handle(): void
    {
        $checkpoint = Checkpoint::find($this->checkpointId);
        if (!$checkpoint) {
            Log::warning('CaptureCctvSnapshotJob: checkpoint tidak ditemukan.', [
                'checkpoint_id' => $this->checkpointId,
            ]);
            return;
        }

        $camera  = $this->resolveCamera();
        $rtspUrl = $camera?->effective_rtsp_url ?? config('cctv.rtsp_url');

        if (!$rtspUrl) {
            Log::debug('CaptureCctvSnapshotJob: tidak ada RTSP URL, skip.', [
                'company_id' => $this->companyId,
                'stage'      => $this->stageName,
            ]);
            $this->writeFailureEntry($checkpoint, 'no_rtsp_url', $camera);
            $checkpoint->update([
                'snapshot_status'   => 'failed',
                'capture_attempted' => true,
                'capture_succeeded' => false,
            ]);
            return;
        }

        $cameraName  = $camera?->name ?? 'CCTV Utama';
        $captureMode = $camera?->getCaptureMode() ?? (env('CCTV_USE_REAL', false) ? 'real' : 'simulation');

        // A3 — Probe mediamtx readiness before committing ffmpeg resources
        if (!$this->probeRtspReady($rtspUrl, $captureMode)) {
            Log::warning('CaptureCctvSnapshotJob: RTSP tidak siap setelah probe.', [
                'rtsp_url'      => $rtspUrl,
                'checkpoint_id' => $this->checkpointId,
            ]);
            $this->writeFailureEntry($checkpoint, 'rtsp_not_ready', $camera, $captureMode);
            $checkpoint->update([
                'snapshot_status'   => 'failed',
                'capture_attempted' => true,
                'capture_succeeded' => false,
            ]);
            return;
        }

        $storageDir  = self::SNAPSHOT_DIR . '/' . $this->companyId;
        $absoluteDir = Storage::disk('public')->path($storageDir);

        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $startMs  = (int) (microtime(true) * 1000);
        $bestPath = $this->captureMultipleFrames($rtspUrl, $absoluteDir);

        if (!$bestPath || !file_exists($bestPath)) {
            Log::warning('CaptureCctvSnapshotJob: semua percobaan ffmpeg gagal.', [
                'checkpoint_id' => $this->checkpointId,
                'rtsp_url'      => $rtspUrl,
            ]);
            $this->writeFailureEntry($checkpoint, 'ffmpeg_failed', $camera, $captureMode);
            $checkpoint->update([
                'snapshot_status'   => 'failed',
                'capture_attempted' => true,
                'capture_succeeded' => false,
            ]);
            return;
        }

        $durationMs   = (int) (microtime(true) * 1000) - $startMs;
        $filesizeKb   = round(filesize($bestPath) / 1024, 1);
        $relativePath = $storageDir . '/' . basename($bestPath);
        $publicUrl    = '/storage/' . $relativePath;
        $capturedAt   = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

        // A5 — persist mode in every photo entry
        $photoEntry = [
            'url'          => $publicUrl,
            'path'         => $relativePath,
            'type'         => $this->scanType === 'scan_out' ? 'departure_snapshot' : 'rfid_snapshot',
            'stage'        => $this->stageName,
            'scan_type'    => $this->scanType,
            'rfid_uid'     => $this->rfidUid,
            'camera_name'  => $cameraName,
            'filesize_kb'  => $filesizeKb,
            'captured_at'  => $capturedAt,
            'duration_ms'  => $durationMs,
            'sha256'       => hash_file('sha256', $bestPath),
            'mode'         => $captureMode,  // A5
        ];

        $photos   = $checkpoint->photos ?? [];
        $photos[] = $photoEntry;

        $checkpointData = $checkpoint->checkpoint_data ?? [];
        $checkpointData['snapshot_captured'] = true;
        $checkpointData['snapshot_at']       = $capturedAt;

        $checkpoint->update([
            'photos'            => $photos,
            'checkpoint_data'   => $checkpointData,
            'snapshot_status'   => 'captured',
            'capture_attempted' => true,
            'capture_succeeded' => true,
        ]);

        // CctvSnapshot adalah sumber kebenaran kanonik sejak migrasi dari
        // checkpoints.photos — entri di atas tetap ditulis sebagai mirror
        // ringan untuk kompatibilitas mundur kode yang belum di-migrasi.
        CctvSnapshot::create([
            'company_id'     => $this->companyId,
            'batch_id'       => $checkpoint->batch_id,
            'checkpoint_id'  => $checkpoint->id,
            'camera_id'      => $camera?->id,
            'file_path'      => $relativePath,
            'capture_mode'   => $captureMode,
            'capture_status' => 'success',
            'scan_type'      => $this->scanType,
            'rfid_uid'       => $this->rfidUid ?: null,
            'stage'          => $this->stageName,
            'filesize_kb'    => (int) round($filesizeKb),
            'captured_at'    => now(),
        ]);

        Log::info('CaptureCctvSnapshotJob: snapshot berhasil.', [
            'checkpoint_id' => $this->checkpointId,
            'path'          => $relativePath,
            'filesize_kb'   => $filesizeKb,
            'duration_ms'   => $durationMs,
            'camera'        => $cameraName,
            'mode'          => $captureMode,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CaptureCctvSnapshotJob: job gagal setelah semua retry.', [
            'checkpoint_id' => $this->checkpointId,
            'error'         => $e->getMessage(),
        ]);

        $checkpoint = Checkpoint::find($this->checkpointId);
        if ($checkpoint) {
            // A4 — write structured failure entry to photos[] on total exhaustion
            $this->writeFailureEntry($checkpoint, 'job_exhausted');
            $checkpoint->update([
                'snapshot_status'   => 'failed',
                'capture_attempted' => true,
                'capture_succeeded' => false,
            ]);
        }
    }

    // ─── A3: MediaMTX readiness probe ────────────────────────────────────────

    private function probeRtspReady(string $rtspUrl, string $captureMode): bool
    {
        // Kamera REAL (fisik) tidak diprobe: kamera fisik seperti TP-Link
        // Tapo punya kuota sesi RTSP concurrent yang sangat terbatas — probe
        // tambahan berisiko merebut slot dari koneksi live-view yang sudah
        // ada dan menyebabkan koneksi itu putus (insiden ECONNRESET yang
        // pernah terjadi saat pengujian multi-kamera). Simulasi (mediamtx)
        // aman diprobe karena hanya proses ffmpeg lokal tanpa batas sesi.
        if ($captureMode === 'real') {
            return true;
        }

        $timeout = 3;
        $quality = 31; // worst quality — fast probe

        for ($i = 0; $i < self::PROBE_RETRIES; $i++) {
            if ($i > 0) {
                usleep(self::PROBE_INTERVAL_US);
            }

            // CATATAN PENTING — JANGAN gunakan flag ffmpeg "-timeout" di sini.
            // Pada ffmpeg 4.4.x, opsi "-timeout" milik RTSP demuxer sudah
            // deprecated dan semantiknya berubah menjadi "maximum timeout
            // untuk MENUNGGU KONEKSI MASUK" (listen_timeout) — artinya
            // ffmpeg mencoba menjadi RTSP SERVER (listen mode), BUKAN
            // client yang connect keluar. Ini menyebabkan error
            // "Unable to open RTSP for listening" / "Address already in
            // use" karena ffmpeg mencoba bind ke port 8554 yang sudah
            // dipakai mediamtx sendiri — root cause insiden rtsp_not_ready
            // yang menyebabkan snapshot CCTV gagal total (lihat changelog
            // Bagian 12). Timeout wall-clock cukup diserahkan ke wrapper
            // shell "timeout %d" di depan perintah ini.
            //
            // "-k 5" pada wrapper timeout DAN "-nostdin" pada ffmpeg —
            // ditambahkan setelah insiden kamera fisik yang RTSP-nya
            // unreachable (mis. kredensial/alamat salah, jaringan belum
            // di-bridge ke kamera) menyebabkan proses ffmpeg menggantung
            // pada syscall jaringan blocking dan mengabaikan SIGTERM awal
            // dari "timeout". Tanpa "-k", proses zombie menumpuk di setiap
            // percobaan probe (hingga 5x per scan) dan setiap capture frame
            // (hingga 3x per scan) — lama-lama menghabiskan memori container
            // hingga di-OOM-kill oleh kernel ("Killed" di log tanpa exception
            // PHP yang jelas). "-k 5" memaksa SIGKILL 5 detik setelah SIGTERM
            // jika proses belum juga keluar. "-nostdin" mencegah ffmpeg
            // menunggu input keyboard interaktif saat dijalankan tanpa tty
            // (selalu kasusnya di sini, dipanggil lewat PHP exec()).
            $probePath = sys_get_temp_dir() . '/minetrace_probe_' . $this->checkpointId . '.jpg';
            $cmd       = sprintf(
                'timeout -k 5 %d ffmpeg -nostdin -loglevel error -rtsp_transport tcp '
                . '-analyzeduration 2000000 -probesize 2000000 '
                . '-i %s -frames:v 1 -q:v %d -y %s 2>&1',
                $timeout,
                escapeshellarg($rtspUrl),
                $quality,
                escapeshellarg($probePath)
            );

            exec($cmd, $out, $code);

            if ($code === 0 && file_exists($probePath) && filesize($probePath) > 512) {
                @unlink($probePath);
                Log::debug('CaptureCctvSnapshotJob: RTSP probe berhasil.', [
                    'attempt' => $i + 1,
                    'url'     => $rtspUrl,
                ]);
                return true;
            }

            @unlink($probePath);
            Log::debug('CaptureCctvSnapshotJob: RTSP probe attempt ' . ($i + 1) . ' gagal.');
        }

        return false;
    }

    // ─── A4: Structured failure entry ────────────────────────────────────────

    private function writeFailureEntry(
        Checkpoint $checkpoint,
        string $reason,
        ?CctvCamera $camera = null,
        string $captureMode = 'simulation'
    ): void {
        $failedAt   = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';
        $photos     = $checkpoint->photos ?? [];
        $photos[]   = [
            'type'        => 'snapshot_failed',
            'scan_type'   => $this->scanType,
            'stage'       => $this->stageName,
            'rfid_uid'    => $this->rfidUid,
            'camera_name' => $camera?->name ?? 'Unknown',
            'mode'        => $captureMode,
            'failed_at'   => $failedAt,
            'reason'      => $reason,
        ];
        $checkpoint->update(['photos' => $photos]);

        CctvSnapshot::create([
            'company_id'     => $this->companyId,
            'batch_id'       => $checkpoint->batch_id,
            'checkpoint_id'  => $checkpoint->id,
            'camera_id'      => $camera?->id,
            'file_path'      => null,
            'capture_mode'   => $captureMode,
            'capture_status' => 'failed',
            'failure_reason' => $reason,
            'scan_type'      => $this->scanType,
            'rfid_uid'       => $this->rfidUid ?: null,
            'stage'          => $this->stageName,
            'captured_at'    => now(),
        ]);
    }

    // ─── Strategy Pattern: Camera Resolution ─────────────────────────────────

    private function resolveCamera(): ?CctvCamera
    {
        if ($this->stageName) {
            $camera = CctvCamera::getDefaultForStage($this->companyId, $this->stageName);
            if ($camera) return $camera;
        }

        return CctvCamera::where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    // ─── Resilience Pattern: Multi-frame capture ──────────────────────────────

    private function captureMultipleFrames(string $rtspUrl, string $dir, int $n = 3): ?string
    {
        $captured = [];

        for ($i = 0; $i < $n; $i++) {
            if ($i > 0) {
                usleep(500_000);
            }

            $filePath = $dir . '/' . $this->buildFilename();

            if ($this->captureFrame($rtspUrl, $filePath)) {
                $captured[] = ['path' => $filePath, 'size' => filesize($filePath)];
            }
        }

        if (empty($captured)) {
            return null;
        }

        usort($captured, fn ($a, $b) => $b['size'] <=> $a['size']);

        foreach (array_slice($captured, 1) as $discarded) {
            @unlink($discarded['path']);
        }

        return $captured[0]['path'];
    }

    private function captureFrame(string $rtspUrl, string $outputPath): bool
    {
        $timeout = (int) config('cctv.ffmpeg_timeout', 30);
        $quality = (int) config('cctv.ffmpeg_quality', 2);
        $scale   = config('cctv.ffmpeg_scale', '1280:720');

        // Lihat catatan di probeRtspReady(): flag "-timeout" TIDAK dipakai
        // karena semantiknya di-deprecate menjadi RTSP listen-mode pada
        // ffmpeg 4.4.x, yang justru menyebabkan bind-conflict dengan
        // mediamtx di port yang sama. Wall-clock timeout diserahkan
        // sepenuhnya ke wrapper shell "timeout %d".
        //
        // "-k 5" + "-nostdin" — lihat catatan lengkap di probeRtspReady():
        // menjamin proses ffmpeg benar-benar mati (SIGKILL setelah 5 detik
        // jika SIGTERM diabaikan) alih-alih menggantung dan menumpuk
        // sebagai proses zombie yang menghabiskan memori container.
        $cmd = sprintf(
            'timeout -k 5 %d ffmpeg -nostdin -loglevel error -rtsp_transport tcp '
            . '-analyzeduration 3000000 -probesize 3000000 '
            . '-i %s -frames:v 1 -q:v %d -vf scale=%s -y %s 2>&1',
            $timeout,
            escapeshellarg($rtspUrl),
            $quality,
            escapeshellarg($scale),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath) || filesize($outputPath) < 1024) {
            Log::debug('CaptureCctvSnapshotJob: ffmpeg frame gagal.', [
                'exit_code' => $exitCode,
                'output'    => implode("\n", array_slice($output, -3)),
            ]);
            @unlink($outputPath);
            return false;
        }

        return true;
    }

    // ─── Builder Pattern: Filename ────────────────────────────────────────────

    private function buildFilename(): string
    {
        $random = strtolower(substr(bin2hex(random_bytes(2)), 0, 4));
        return sprintf(
            'snap_%d_%d_%s_%s.jpg',
            $this->companyId,
            $this->checkpointId,
            now()->format('Ymd_His'),
            $random
        );
    }
}
