<?php

namespace App\Console\Commands;

use App\Models\CctvCamera;
use App\Models\CctvSnapshot;
use App\Models\Checkpoint;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MigrateSnapshotsToTable — backfill checkpoints.photos (JSON) → cctv_snapshots.
 *
 * One-time data migration: dijalankan sekali saat cctv_snapshots pertama kali
 * diperkenalkan, agar histori capture lama yang tersimpan sebagai JSON blob
 * tetap terlihat lewat entitas first-class yang baru. Idempotent — melewati
 * entri yang kombinasi checkpoint_id + captured_at + capture_status-nya
 * sudah pernah dibackfill (data lama tidak punya id unik sendiri per foto).
 */
class MigrateSnapshotsToTable extends Command
{
    protected $signature = 'cctv:migrate-snapshots {--dry-run : Tampilkan hasil tanpa menulis ke database}';

    protected $description = 'Backfill checkpoints.photos (JSON) ke tabel cctv_snapshots';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $totalPhotos      = 0;
        $totalMigrated    = 0;
        $totalSkipped     = 0;
        $totalUnparseable = 0;

        DB::transaction(function () use ($dryRun, &$totalPhotos, &$totalMigrated, &$totalSkipped, &$totalUnparseable) {
            Checkpoint::whereNotNull('photos')
                ->whereRaw("photos::text != '[]'")
                ->with('batch:id,company_id')
                ->chunkById(200, function ($checkpoints) use (
                    &$totalPhotos, &$totalMigrated, &$totalSkipped, &$totalUnparseable, $dryRun
                ) {
                    foreach ($checkpoints as $checkpoint) {
                        $photos = $checkpoint->photos ?? [];
                        if (empty($photos) || !$checkpoint->batch) {
                            continue;
                        }

                        $companyId = $checkpoint->batch->company_id;

                        foreach ($photos as $photo) {
                            $totalPhotos++;

                            $capturedAt = $this->parseCapturedAt($photo);
                            if (!$capturedAt) {
                                $totalUnparseable++;
                                Log::warning('MigrateSnapshotsToTable: gagal parse timestamp, dilewati.', [
                                    'checkpoint_id' => $checkpoint->id,
                                    'photo'         => $photo,
                                ]);
                                continue;
                            }

                            $isFailure = ($photo['type'] ?? null) === 'snapshot_failed';

                            // Idempotency guard: lewati jika record dengan kombinasi
                            // checkpoint_id + captured_at + capture_status ini sudah ada.
                            $exists = CctvSnapshot::where('checkpoint_id', $checkpoint->id)
                                ->where('captured_at', $capturedAt)
                                ->where('capture_status', $isFailure ? 'failed' : 'success')
                                ->exists();

                            if ($exists) {
                                $totalSkipped++;
                                continue;
                            }

                            $cameraId = $this->resolveCameraId($companyId, $photo['camera_name'] ?? null);

                            $attributes = [
                                'company_id'     => $companyId,
                                'batch_id'       => $checkpoint->batch_id,
                                'checkpoint_id'  => $checkpoint->id,
                                'camera_id'      => $cameraId,
                                'file_path'      => $photo['path'] ?? null,
                                'capture_mode'   => $photo['mode'] ?? 'simulation',
                                'capture_status' => $isFailure ? 'failed' : 'success',
                                'failure_reason' => $isFailure ? ($photo['reason'] ?? null) : null,
                                'scan_type'      => $photo['scan_type'] ?? null,
                                'rfid_uid'       => $photo['rfid_uid'] ?? null,
                                'stage'          => $photo['stage'] ?? null,
                                'filesize_kb'    => isset($photo['filesize_kb']) ? (int) round($photo['filesize_kb']) : null,
                                'captured_at'    => $capturedAt,
                            ];

                            if (!$dryRun) {
                                CctvSnapshot::create($attributes);
                            }

                            $totalMigrated++;
                        }
                    }
                });
        });

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Migrasi snapshot selesai.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total foto ditemukan di checkpoints.photos', $totalPhotos],
                ['Berhasil dimigrasi', $totalMigrated],
                ['Dilewati (sudah ada)', $totalSkipped],
                ['Gagal diparse (timestamp tidak valid)', $totalUnparseable],
                ['Total baris cctv_snapshots sekarang', $dryRun ? '(dry-run, tidak berubah)' : CctvSnapshot::count()],
            ]
        );

        if ($totalUnparseable > 0) {
            $this->warn("{$totalUnparseable} entri tidak dapat diparse — lihat storage/logs/laravel.log untuk detail.");
        }

        return self::SUCCESS;
    }

    /**
     * Parse timestamp dari format backend "d/m/Y H:i:s WIB" (bukan
     * ISO/US date) — lihat catatan formatDate() di Batches/Show.vue untuk
     * bug serupa yang pernah terjadi akibat parsing tanggal yang ambigu.
     */
    private function parseCapturedAt(array $photo): ?Carbon
    {
        $raw = $photo['captured_at'] ?? $photo['failed_at'] ?? null;
        if (!$raw) {
            return null;
        }

        $clean = trim(str_replace('WIB', '', $raw));

        try {
            return Carbon::createFromFormat('d/m/Y H:i:s', $clean, 'Asia/Jakarta')->utc();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Cocokkan camera_name (string bebas di data lama) ke CctvCamera.id
     * jika ada kecocokan persis pada company yang sama. Data lama tidak
     * punya referensi FK asli, jadi ini best-effort — null jika tidak
     * ketemu (kolom camera_id nullable).
     */
    private function resolveCameraId(int $companyId, ?string $cameraName): ?int
    {
        if (!$cameraName) {
            return null;
        }

        return CctvCamera::where('company_id', $companyId)
            ->where('name', $cameraName)
            ->value('id');
    }
}
