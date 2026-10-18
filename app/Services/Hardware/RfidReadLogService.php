<?php

namespace App\Services\Hardware;

use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\RfidReader;
use App\Models\RfidReadLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RfidReadLogService — menyimpan SETIAP pembacaan RFID ke rfid_read_logs dan memicu capture
 * CCTV (Tapo) untuk log tersebut.
 *
 * Aturan capture:
 *  - accepted dengan checkpoint : job CCTV checkpoint (sudah di-dispatch RfidScanService) ikut menautkan
 *                                  log ini — satu foto, tidak ada capture ganda.
 *  - accepted registrasi         : capture baru (bila rfid.read_log.capture_on_register).
 *  - rejected                    : capture baru (bila rfid.read_log.capture_on_reject).
 *  - duplicate                   : tanpa capture.
 *
 * Pencatatan log tidak boleh menggagalkan scan: semua galat ditangkap dan ditulis ke log aplikasi.
 */
class RfidReadLogService
{
    /**
     * @param  array  $result  hasil gaya-LCD (status, _code, message, detail, batch_id, …)
     * @param  array  $opts  {origin?, stage?, batch_id?, checkpoint_id?, user_id?, antenna?, rssi?, tid?, meta?}
     */
    public function record(int $companyId, ?RfidReader $reader, string $action, string $epc, array $result, bool $duplicate = false, array $opts = []): ?RfidReadLog
    {
        try {
            $accepted = ($result['status'] ?? null) === 'success';

            return RfidReadLog::create([
                'company_id' => $companyId,
                'reader_id' => $reader?->id,
                'epc' => mb_substr($epc, 0, 100),
                'tid' => isset($opts['tid']) ? mb_substr((string) $opts['tid'], 0, 64) : null,
                'action' => $action,
                'stage' => $opts['stage'] ?? $result['scan_stage'] ?? $reader?->stage,
                'antenna' => $opts['antenna'] ?? null,
                'rssi' => isset($opts['rssi']) ? (int) round($opts['rssi']) : null,
                'origin' => $opts['origin'] ?? null,
                'result' => $duplicate ? 'duplicate' : ($accepted ? 'accepted' : 'rejected'),
                'result_code' => $result['_code'] ?? ($accepted ? 'ok' : 'error'),
                'message' => isset($result['detail']) ? mb_substr((string) $result['detail'], 0, 255) : ($result['message'] ?? null),
                'batch_id' => $opts['batch_id'] ?? $result['batch_id'] ?? null,
                'checkpoint_id' => $duplicate ? null : ($opts['checkpoint_id'] ?? null),
                'user_id' => $opts['user_id'] ?? null,
                'meta' => $opts['meta'] ?? null,
                'read_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('RFID read log gagal disimpan: '.$e->getMessage(), ['epc' => $epc, 'company_id' => $companyId]);

            return null;
        }
    }

    /**
     * Catat percobaan registrasi tag (berhasil atau gagal) lalu capture CCTV.
     *
     * @param  array  $opts  lihat record(); stage default 'receiving' (registrasi dilakukan di Receiving Area)
     */
    public function registration(int $companyId, ?RfidReader $reader, string $epc, array $result, array $opts = []): ?RfidReadLog
    {
        $log = $this->record($companyId, $reader, 'register', $epc, $result, false, $opts + [
            'stage' => $reader?->stage ?? 'receiving',
            'checkpoint_id' => $result['_checkpoint_id'] ?? null,
        ]);
        $this->captureIfNeeded($log);

        return $log;
    }

    /**
     * Capture CCTV khusus log ini (tanpa checkpoint) — untuk pembacaan ditolak & registrasi
     * yang tidak lewat jalur checkpoint scan.
     */
    public function capture(RfidReadLog $log, ?int $checkpointId = null): void
    {
        $log->update(['snapshot_status' => 'pending']);

        dispatch(new CaptureCctvSnapshotJob(
            checkpointId: $checkpointId,
            companyId: $log->company_id,
            stageName: $log->stage,
            scanType: $log->action === 'out' ? 'scan_out' : 'scan_in',
            rfidUid: $log->epc,
            readLogId: $log->id,
        ))->onQueue(config('cctv.queue', 'default'))
            ->delay(now()->addSeconds(config('cctv.snapshot_delay', 2)));
    }

    /** Terapkan aturan capture untuk log yang baru dicatat (lihat docblock kelas). */
    public function captureIfNeeded(?RfidReadLog $log): void
    {
        if (! $log || $log->result === 'duplicate') {
            return;
        }

        $wanted = match (true) {
            $log->result === 'rejected' => (bool) config('rfid.read_log.capture_on_reject', true),
            $log->action === 'register' => (bool) config('rfid.read_log.capture_on_register', true),
            default => false,   // scan accepted: ditangani job checkpoint
        };

        // Tag asing yang terus terbaca di gate tidak boleh membanjiri antrean capture: satu capture per
        // reader+EPC per cooldown, dan batas total per menit per reader.
        if ($wanted && $log->result === 'rejected' && ! $this->rejectCaptureAllowed($log)) {
            return;
        }

        if ($wanted) {
            $this->capture($log, $log->action === 'register' ? $log->checkpoint_id : null);
        }
    }

    private function rejectCaptureAllowed(RfidReadLog $log): bool
    {
        $cool = (int) config('rfid.read_log.reject_capture_cooldown', 30);
        $perMin = (int) config('rfid.read_log.reject_capture_per_minute', 10);
        $rid = $log->reader_id ?? 0;

        if ($cool > 0 && ! Cache::add("rfid:rejcap:{$rid}:{$log->epc}", 1, $cool)) {
            return false;
        }
        if ($perMin > 0) {
            $key = "rfid:rejcap:min:{$rid}:".now()->format('YmdHi');
            Cache::add($key, 0, 90);
            if (Cache::increment($key) > $perMin) {
                return false;
            }
        }

        return true;
    }

    public function present(RfidReadLog $l): array
    {
        return [
            'id' => $l->id,
            'epc' => $l->epc,
            'tid' => $l->tid,
            'action' => $l->action,
            'stage' => $l->stage,
            'antenna' => $l->antenna,
            'rssi' => $l->rssi,
            'origin' => $l->origin,
            'result' => $l->result,
            'result_code' => $l->result_code,
            'message' => $l->message,
            'batch_id' => $l->batch_id,
            'batch_code' => $l->batch?->batch_code,
            'checkpoint_id' => $l->checkpoint_id,
            'reader' => $l->reader ? ['id' => $l->reader->id, 'code' => $l->reader->code, 'name' => $l->reader->name] : null,
            'snapshot_status' => $l->snapshot_status,
            'snapshot_url' => $l->snapshot?->url,
            'read_at' => $l->read_at?->toIso8601String(),
        ];
    }
}
