<?php

namespace App\Jobs;

use App\Models\Checkpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CaptureCctvSnapshotJob — Ambil snapshot CCTV via ffmpeg saat RFID scan.
 *
 * Triggered setelah checkpoint dibuat di ApiRfidController.
 * Snapshot disimpan ke storage/app/public/cctv-snapshots/{batch_id}/
 * dan path-nya ditambahkan ke photos[] pada Checkpoint terkait.
 */
class CaptureCctvSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 30;

    public function __construct(
        private int    $batchId,
        private int    $checkpointId,
        private string $triggerAction = 'rfid_scan'
    ) {}

    public function handle(): void
    {
        $rtspUrl = config('cctv.rtsp_url');

        if (!$rtspUrl) {
            Log::debug('CaptureCctvSnapshotJob: CCTV_RTSP_URL tidak dikonfigurasi, skip.');
            return;
        }

        $checkpoint = Checkpoint::find($this->checkpointId);
        if (!$checkpoint) {
            Log::warning("CaptureCctvSnapshotJob: Checkpoint #{$this->checkpointId} tidak ditemukan.");
            return;
        }

        $filename  = sprintf(
            'cctv-snapshots/%d/snap_%s_%s.jpg',
            $this->batchId,
            $this->checkpointId,
            now()->format('Ymd_His')
        );
        $storagePath = Storage::disk('public')->path($filename);

        // Buat direktori jika belum ada
        $dir = dirname($storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timeout = (int) config('cctv.ffmpeg_timeout', 30);
        $quality = (int) config('cctv.ffmpeg_quality', 2);
        $scale   = config('cctv.ffmpeg_scale', '1280:720');

        // ffmpeg: ambil 1 frame dari RTSP stream dengan timeout yang dikonfigurasi
        $cmd = sprintf(
            'timeout %d ffmpeg -rtsp_transport tcp -i %s -vframes 1 -q:v %d -vf scale=%s -y %s 2>&1',
            $timeout,
            escapeshellarg($rtspUrl),
            $quality,
            escapeshellarg($scale),
            escapeshellarg($storagePath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($storagePath)) {
            Log::error("CaptureCctvSnapshotJob: ffmpeg gagal (exit {$exitCode})", [
                'checkpoint_id' => $this->checkpointId,
                'output'        => implode("\n", array_slice($output, -5)),
            ]);
            return;
        }

        // Tambahkan path snapshot ke photos[] pada checkpoint
        $photos   = $checkpoint->photos ?? [];
        $photos[] = 'cctv-snapshots/' . $this->batchId . '/' . basename($filename);
        $checkpoint->update(['photos' => $photos]);

        Log::info("CaptureCctvSnapshotJob: Snapshot tersimpan checkpoint #{$this->checkpointId}", [
            'path'   => $filename,
            'action' => $this->triggerAction,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("CaptureCctvSnapshotJob: Job gagal", [
            'checkpoint_id' => $this->checkpointId,
            'batch_id'      => $this->batchId,
            'error'         => $e->getMessage(),
        ]);
    }
}
