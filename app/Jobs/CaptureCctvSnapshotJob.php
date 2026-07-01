<?php

namespace App\Jobs;

use App\Models\CctvCamera;
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
 * ═══════════════════════════════════════════════════════════════════════════
 * MINING INDUSTRY STANDARD — ISO 9001 Audit Trail
 *
 * RFID Scan IN/OUT → CCTV snapshot diambil (bukti fisik keberadaan batch)
 * Foto disimpan ke storage → path+metadata ditambahkan ke Checkpoint.photos[]
 * Foto = bukti legal bahwa batch benar ada secara fisik di titik scan
 *
 * DESIGN PATTERNS:
 * - Strategy Pattern  : CameraResolver memilih kamera berdasarkan stage
 * - Builder Pattern   : Filename generator dengan format deterministik
 * - Observer Pattern  : Update Checkpoint.photos[] setelah capture
 * - Resilience Pattern: Multi-frame capture, ambil frame terbaik
 * ═══════════════════════════════════════════════════════════════════════════
 */
class CaptureCctvSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public int   $timeout = 45;
    public array $backoff  = [5, 15, 30];

    private const TZ         = 'Asia/Jakarta';
    private const SNAPSHOT_DIR = 'cctv-snapshots';

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
            Log::warning('CaptureCctvSnapshotJob: Checkpoint tidak ditemukan.', [
                'checkpoint_id' => $this->checkpointId,
            ]);
            return;
        }

        // ── [Strategy Pattern] Resolve kamera yang akan digunakan ───────────
        $camera   = $this->resolveCamera();
        $rtspUrl  = $camera?->effective_rtsp_url ?? config('cctv.rtsp_url');

        if (!$rtspUrl) {
            Log::debug('CaptureCctvSnapshotJob: Tidak ada RTSP URL tersedia, skip.', [
                'company_id' => $this->companyId,
                'stage'      => $this->stageName,
            ]);
            $checkpoint->update(['snapshot_status' => 'failed']);
            return;
        }

        $cameraName = $camera?->name ?? 'CCTV Utama';

        // ── [Builder Pattern] Buat direktori dan ambil multi-frame ──────────
        $storageDir   = self::SNAPSHOT_DIR . '/' . $this->companyId;
        $absoluteDir  = Storage::disk('public')->path($storageDir);

        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $startMs   = (int) (microtime(true) * 1000);
        $bestPath  = $this->captureMultipleFrames($rtspUrl, $absoluteDir);

        if (!$bestPath || !file_exists($bestPath)) {
            Log::warning('CaptureCctvSnapshotJob: Semua percobaan ffmpeg gagal.', [
                'checkpoint_id' => $this->checkpointId,
                'rtsp_url'      => $rtspUrl,
            ]);
            return;
        }

        $durationMs  = (int) (microtime(true) * 1000) - $startMs;
        $filesizeKb  = round(filesize($bestPath) / 1024, 1);
        $relativePath = $storageDir . '/' . basename($bestPath);
        $publicUrl    = '/storage/' . $relativePath;
        $capturedAt   = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

        // ── [Observer Pattern] Update Checkpoint.photos[] dengan metadata ───
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
        ];

        $photos   = $checkpoint->photos ?? [];
        $photos[] = $photoEntry;

        $checkpointData = $checkpoint->checkpoint_data ?? [];
        $checkpointData['snapshot_captured'] = true;
        $checkpointData['snapshot_at']       = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

        $checkpoint->update([
            'photos'          => $photos,
            'checkpoint_data' => $checkpointData,
            'snapshot_status' => 'captured',
        ]);

        Log::info('CaptureCctvSnapshotJob: Snapshot berhasil tersimpan.', [
            'checkpoint_id' => $this->checkpointId,
            'path'          => $relativePath,
            'filesize_kb'   => $filesizeKb,
            'duration_ms'   => $durationMs,
            'camera'        => $cameraName,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CaptureCctvSnapshotJob: Job gagal setelah semua retry.', [
            'checkpoint_id' => $this->checkpointId,
            'company_id'    => $this->companyId,
            'scan_type'     => $this->scanType,
            'error'         => $e->getMessage(),
        ]);
        Checkpoint::where('id', $this->checkpointId)
            ->update(['snapshot_status' => 'failed']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // STRATEGY PATTERN — Camera Resolution
    // Priority: stage-specific → first active camera → env fallback
    // ═════════════════════════════════════════════════════════════════════════

    private function resolveCamera(): ?CctvCamera
    {
        // 1. Stage-specific camera
        if ($this->stageName) {
            $camera = CctvCamera::getDefaultForStage($this->companyId, $this->stageName);
            if ($camera) return $camera;
        }

        // 2. First active camera for company
        return CctvCamera::where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        // 3. Env fallback handled by caller (rtsp_url dari config)
    }

    // ═════════════════════════════════════════════════════════════════════════
    // RESILIENCE PATTERN — Multi-frame capture
    // Ambil n frame dengan interval 0.5 detik, simpan yang terbaik
    // (ukuran file terbesar = frame paling informatif = paling banyak detail)
    // ═════════════════════════════════════════════════════════════════════════

    private function captureMultipleFrames(string $rtspUrl, string $dir, int $n = 3): ?string
    {
        $captured = [];

        for ($i = 0; $i < $n; $i++) {
            if ($i > 0) {
                usleep(500_000); // 0.5 detik antar frame
            }

            $filePath = $dir . '/' . $this->buildFilename();

            if ($this->captureFrame($rtspUrl, $filePath)) {
                $captured[] = [
                    'path' => $filePath,
                    'size' => filesize($filePath),
                ];
            }
        }

        if (empty($captured)) {
            return null;
        }

        // Pilih frame dengan filesize terbesar (paling banyak informasi visual)
        usort($captured, fn ($a, $b) => $b['size'] <=> $a['size']);

        // Hapus frame yang tidak dipilih
        foreach (array_slice($captured, 1) as $discarded) {
            @unlink($discarded['path']);
        }

        return $captured[0]['path'];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Capture satu frame via ffmpeg
    // ═════════════════════════════════════════════════════════════════════════

    private function captureFrame(string $rtspUrl, string $outputPath): bool
    {
        $timeout = (int) config('cctv.ffmpeg_timeout', 30);
        $quality = (int) config('cctv.ffmpeg_quality', 2);
        $scale   = config('cctv.ffmpeg_scale', '1280:720');

        $cmd = sprintf(
            'ffmpeg -loglevel error -rtsp_transport tcp -timeout 10000000 -i %s -frames:v 1 -q:v %d -vf scale=%s -y %s 2>&1',
            escapeshellarg($rtspUrl),
            $quality,
            escapeshellarg($scale),
            escapeshellarg($outputPath)
        );

        $fullCmd = sprintf('timeout %d %s', $timeout, $cmd);

        exec($fullCmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath) || filesize($outputPath) < 1024) {
            Log::debug('CaptureCctvSnapshotJob: ffmpeg frame gagal.', [
                'exit_code' => $exitCode,
                'output'    => implode("\n", array_slice($output, -3)),
                'path'      => $outputPath,
            ]);
            @unlink($outputPath);
            return false;
        }

        return true;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // BUILDER PATTERN — Filename dengan format deterministik
    // Format: snap_{companyId}_{checkpointId}_{YYYYMMDD_HHmmss}_{random4}.jpg
    // ═════════════════════════════════════════════════════════════════════════

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
