<?php

namespace App\Services\Hardware;

use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\Checkpoint;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RfidScanService — orkestrasi satu pembacaan tag (Scan IN/OUT) dari perangkat mana pun
 * (ESP32 legacy, reader terdaftar, input manual/USB dari UI).
 *
 * Jaminan:
 *  - Isolasi tenant: perangkat ber-identitas hanya melihat tag perusahaannya.
 *  - Idempotensi: event_id yang sama → hasil yang sama, tanpa checkpoint baru.
 *  - Dedupe: pembacaan ulang tag yang sama (arah+tahap sama) dalam jendela pendek → respons
 *    IDENTIK dengan yang pertama (LCD tidak berubah), tanpa checkpoint/job CCTV baru.
 *  - Serialisasi per UID (lock atomik): dua pembacaan serentak tidak menggandakan transisi tahap.
 *  - Pencatatan RFID tidak pernah menunggu CCTV (job snapshot di-dispatch async).
 */
class RfidScanService
{
    public function __construct(
        private readonly RfidService $rfid,
        private readonly RfidAuthorizationService $authorization,
        private readonly RfidDeviceService $devices,
    ) {}

    /**
     * @param  string $direction 'in' | 'out'
     * @param  array  $opts {origin?:string, event_id?:string, ip?:string, user_id?:int}
     * @return array{0:array,1:bool} [hasil gaya-LCD, duplikat?]
     */
    public function scan(DeviceContext $ctx, string $direction, string $rawUid, ?string $stage = null, array $opts = []): array
    {
        $uid     = $this->rfid->sanitizeUid($rawUid);
        $reader  = $ctx->reader;
        $eventId = $opts['event_id'] ?? null;

        // Arah reader tetap (read point): reader "in" tidak boleh dipakai untuk Scan OUT dan sebaliknya.
        if ($reader && $reader->direction !== 'any' && $reader->direction !== $direction) {
            $label = $reader->direction === 'in' ? 'SCAN IN' : 'SCAN OUT';
            return [[
                'status'  => 'failed',
                'name'    => 'Arah Salah',
                'message' => "Reader {$label} saja",
                'detail'  => "Reader {$reader->code} dikonfigurasi hanya untuk {$label}.",
            ], false];
        }

        $stage = $stage ?: $reader?->stage;

        if ($eventId && ($replay = Cache::get($this->eventKey($ctx, $eventId)))) {
            return [$replay, true];
        }

        $lock = Cache::lock("rfid:scan:{$uid}", 10);
        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            return [[
                'status'  => 'failed',
                'name'    => 'Sistem Sibuk',
                'message' => 'Coba lagi',
                'detail'  => "UID {$uid} sedang diproses. Tempelkan ulang tag.",
            ], false];
        }

        try {
            // Cek ulang setelah memegang lock (pembacaan serentak yang kalah balapan).
            if ($eventId && ($replay = Cache::get($this->eventKey($ctx, $eventId)))) {
                return [$replay, true];
            }
            $dedupeKey = "rfid:dd:{$uid}:{$direction}:" . ($stage ?? '-');
            if (config('rfid.dedupe_seconds') > 0 && ($previous = Cache::get($dedupeKey))) {
                return [$previous, true];
            }

            $result = $this->process($ctx, $direction, $uid, $stage, $opts);

            if ($result['status'] === 'success') {
                if (config('rfid.dedupe_seconds') > 0) {
                    Cache::put($dedupeKey, $result, config('rfid.dedupe_seconds'));
                }
                if ($eventId) {
                    Cache::put($this->eventKey($ctx, $eventId), $result, config('rfid.event_ttl'));
                }
            }

            return [$result, false];
        } finally {
            $lock->release();
        }
    }

    private function process(DeviceContext $ctx, string $direction, string $uid, ?string $stage, array $opts): array
    {
        $allowGlobal = $ctx->identity === 'anonymous' && config('rfid.legacy_global_tag_fallback');
        $batch       = $this->rfid->findBatchByUid($uid, $ctx->companyId, $allowGlobal);

        if (! $batch) {
            Log::warning("RFID scan {$direction}: UID {$uid} tidak dikenal (identity={$ctx->identity}, company_id={$ctx->companyId})");

            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => $direction === 'in'
                    ? "UID {$uid} tidak terdaftar. Lakukan registrasi terlebih dahulu."
                    : "UID {$uid} tidak terdaftar.",
            ];
        }

        $origin    = $opts['origin'] ?? 'real_esp32';
        $scanStage = $this->rfid->resolveScanStage($batch, $stage);
        $strategy  = $this->authorization->getStrategy($batch->company_id, $scanStage);
        $context   = [
            'rfid_origin' => $origin,
            'meta'        => ['event_id' => $opts['event_id'] ?? null, 'reader_id' => $ctx->reader?->id],
        ];

        $result = $direction === 'in'
            ? $strategy->handleScanIn($batch, $scanStage, $context)
            : $strategy->handleScanOut($batch, $scanStage, $context);

        Log::info(sprintf(
            'RFID scan %s (mode:%s) uid:%s stage:%s origin:%s identity:%s reader:%s status:%s transition_attempted:%d transition_success:%d ip:%s',
            $direction, $strategy->getMode(), $uid, $scanStage, $origin, $ctx->identity,
            $ctx->reader?->code ?? '-', $result['status'],
            (int) ($result['transition_attempted'] ?? false), (int) ($result['transition_success'] ?? false),
            $opts['ip'] ?? '-'
        ));

        $checkpointId = $result['_checkpoint_id'] ?? null;
        unset($result['_checkpoint_id']);   // kunci internal — bentuk respons perangkat tidak berubah

        if ($ctx->reader) {
            $this->devices->touch($ctx->reader, $opts['ip'] ?? null);
        }

        if ($result['status'] === 'success' && $checkpointId) {
            $this->dispatchSnapshot(
                $checkpointId,
                (int) ($result['company_id'] ?? $batch->company_id),   // tenant milik BATCH
                $result['scan_stage'] ?? null,
                $direction === 'in' ? 'scan_in' : 'scan_out',
                $uid
            );
        }

        return $result;
    }

    private function dispatchSnapshot(int $checkpointId, int $companyId, ?string $stage, string $scanType, string $uid): void
    {
        Checkpoint::whereKey($checkpointId)->update(['snapshot_status' => 'pending']);

        dispatch(new CaptureCctvSnapshotJob(
            checkpointId: $checkpointId,
            companyId:    $companyId,
            stageName:    $stage,
            scanType:     $scanType,
            rfidUid:      $uid,
        ))->onQueue(config('cctv.queue', 'default'))
          ->delay(now()->addSeconds(config('cctv.snapshot_delay', 2)));
    }

    private function eventKey(DeviceContext $ctx, string $eventId): string
    {
        return "rfid:evt:{$ctx->companyId}:{$eventId}";
    }

    // ─── Pembatalan scan (koreksi, bukan hapus) ───────────────────────────

    /**
     * Tandai scan sebagai dibatalkan beralasan. Riwayat tetap ada (L6).
     * Bila scan itu memicu transisi tahap otomatis, transisi TIDAK dibalik otomatis:
     * koreksi tahap dilakukan lewat alur proses resmi (dikembalikan pada pemanggil sebagai peringatan).
     *
     * @throws \DomainException  not_a_scan | already_voided
     */
    public function void(Checkpoint $checkpoint, User $user, string $reason): Checkpoint
    {
        if (! $checkpoint->rfid_scan_code) {
            throw new \DomainException('not_a_scan');
        }
        if ($checkpoint->voided_at) {
            throw new \DomainException('already_voided');
        }

        $checkpoint->update([
            'voided_at'   => now(),
            'voided_by'   => $user->id,
            'void_reason' => mb_substr($reason, 0, 255),
        ]);

        Log::info("RFID scan DIBATALKAN: checkpoint_id={$checkpoint->id} oleh user_id={$user->id}");

        return $checkpoint->fresh();
    }
}
