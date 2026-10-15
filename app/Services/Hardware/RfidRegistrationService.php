<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\RfidReader;
use App\Models\RfidTagEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RfidRegistrationService — sesi registrasi tag (ikat UID ke batch).
 *
 * Sesi disimpan di cache (TTL pendek) dan DITUNJUK oleh "pointer" per tenant:
 *   rfid:reg:p:{company}:r{reader}  → sesi yang menunggu reader tertentu (paralel antar stasiun)
 *   rfid:reg:p:{company}:any        → sesi untuk perangkat yang tidak tahu readernya (legacy/USB manual)
 * Tidak ada lagi slot global lintas-perusahaan untuk perangkat ber-identitas. Satu-satunya jalur
 * global tersisa adalah cermin `rfid_pending_batch_global` untuk perangkat ANONIM legacy
 * (config rfid.legacy_global_tag_fallback) — ditulis dan dibaca hanya oleh jalur anonim.
 */
class RfidRegistrationService
{
    private const TZ = 'Asia/Jakarta';

    private const LEGACY_COMPANY_KEY = 'rfid_pending_batch_';
    private const LEGACY_GLOBAL_KEY  = 'rfid_pending_batch_global';

    private function ttl(): int
    {
        return (int) config('rfid.registration_ttl');
    }

    private function sessionKey(string $id): string
    {
        return "rfid:reg:s:{$id}";
    }

    private function pointerKey(int $companyId, ?int $readerId): string
    {
        return "rfid:reg:p:{$companyId}:" . ($readerId ? "r{$readerId}" : 'any');
    }

    private function batchLockKey(int $batchId): string
    {
        return "rfid:reg:b:{$batchId}";
    }

    // ─── Mulai / batal / baca sesi ────────────────────────────────────────

    /**
     * @throws \DomainException bila batch tidak memenuhi syarat (kode di getMessage()).
     */
    public function start(User|string $initiator, Batch $batch, ?RfidReader $reader = null): array
    {
        if ($batch->rfid_tag) {
            throw new \DomainException('batch_already_tagged');
        }
        if (in_array($batch->status, ['exported', 'terminated'], true)) {
            throw new \DomainException('batch_closed');
        }
        if ($reader && ($reader->company_id !== $batch->company_id || ! $reader->is_active)) {
            throw new \DomainException('reader_invalid');
        }

        $existing = Cache::get($this->batchLockKey($batch->id));
        if ($existing && ($s = Cache::get($this->sessionKey($existing)))) {
            $sameReader = ($s['reader_id'] ?? null) === $reader?->id;
            if (! $sameReader) {
                throw new \DomainException('batch_has_session');
            }
            $this->cancel($existing);   // ganti sesi milik reader yang sama
        }

        $id      = (string) Str::uuid();
        $name    = $initiator instanceof User ? $initiator->name : $initiator;
        $session = [
            'id'           => $id,
            'company_id'   => $batch->company_id,
            'batch_id'     => $batch->id,
            'batch_code'   => $batch->batch_code,
            'product_code' => $batch->productCode?->code,
            'reader_id'    => $reader?->id,
            'reader_code'  => $reader?->code,
            'initiated_by' => $name,
            'user_id'      => $initiator instanceof User ? $initiator->id : null,
            'started_at'   => now()->toIso8601String(),
            'expires_at'   => now()->addSeconds($this->ttl())->toIso8601String(),
        ];

        $ttl = $this->ttl();
        Cache::put($this->sessionKey($id), $session, $ttl);
        Cache::put($this->pointerKey($batch->company_id, $reader?->id), $id, $ttl);
        Cache::put($this->batchLockKey($batch->id), $id, $ttl);

        // Cermin format lama — hanya untuk sesi tanpa reader (perangkat legacy yang tak tahu readernya).
        if (! $reader) {
            $legacy = [
                'batch_id'     => $batch->id,
                'batch_code'   => $batch->batch_code,
                'product_code' => $batch->productCode?->code,
                'initiated_by' => $name,
                'company_id'   => $batch->company_id,
                'started_at'   => $session['started_at'],
            ];
            Cache::put(self::LEGACY_COMPANY_KEY . $batch->company_id, $legacy, $ttl);
            if (config('rfid.legacy_global_tag_fallback')) {
                Cache::put(self::LEGACY_GLOBAL_KEY, $legacy, $ttl);
            }
        }

        Log::info("RFID registrasi MULAI: batch={$batch->batch_code} reader=" . ($reader?->code ?? '-') . " oleh={$name}");

        return $session;
    }

    public function get(string $sessionId, ?int $companyId = null): ?array
    {
        $s = Cache::get($this->sessionKey($sessionId));
        if (! $s || ($companyId !== null && $s['company_id'] !== $companyId)) {
            return null;
        }

        return $s;
    }

    /** Sesi aktif perusahaan (untuk UI): 'any' + semua reader. */
    public function activeForCompany(int $companyId): array
    {
        $sessions = [];
        $pointers = array_merge(
            [$this->pointerKey($companyId, null)],
            RfidReader::where('company_id', $companyId)->pluck('id')
                ->map(fn ($id) => $this->pointerKey($companyId, $id))->all()
        );
        foreach ($pointers as $key) {
            if (($id = Cache::get($key)) && ($s = Cache::get($this->sessionKey($id)))) {
                $sessions[] = $s;
            }
        }

        return $sessions;
    }

    public function cancel(string $sessionId): void
    {
        if (! ($s = Cache::get($this->sessionKey($sessionId)))) {
            return;
        }
        $this->forget($s);
        Log::info("RFID registrasi BATAL: batch={$s['batch_code']}");
    }

    private function forget(array $s): void
    {
        Cache::forget($this->sessionKey($s['id']));
        Cache::forget($this->batchLockKey($s['batch_id']));
        $pointer = $this->pointerKey($s['company_id'], $s['reader_id']);
        if (Cache::get($pointer) === $s['id']) {
            Cache::forget($pointer);
        }
        if (! $s['reader_id']) {
            Cache::forget(self::LEGACY_COMPANY_KEY . $s['company_id']);
            $g = Cache::get(self::LEGACY_GLOBAL_KEY);
            if ($g && ($g['batch_id'] ?? null) === $s['batch_id']) {
                Cache::forget(self::LEGACY_GLOBAL_KEY);
            }
        }
    }

    /** Sesi untuk perangkat: pointer reader → 'any' perusahaan → (anonim legacy saja) slot global. */
    public function findForDevice(DeviceContext $ctx): ?array
    {
        if ($ctx->reader && ($id = Cache::get($this->pointerKey($ctx->companyId, $ctx->reader->id)))) {
            if ($s = Cache::get($this->sessionKey($id))) {
                return $s;
            }
        }
        if (($id = Cache::get($this->pointerKey($ctx->companyId, null))) && ($s = Cache::get($this->sessionKey($id)))) {
            return $s;
        }

        // Perangkat anonim legacy: perilaku lama — slot global (hasil "company_id mismatch" ESP32).
        if ($ctx->identity === 'anonymous' && config('rfid.legacy_global_tag_fallback')) {
            if ($g = Cache::get(self::LEGACY_GLOBAL_KEY)) {
                $sid = $this->sessionIdForLegacy($g);
                $s   = $sid ? Cache::get($this->sessionKey($sid)) : null;
                if ($s) {
                    Log::info("RFID Register: perangkat anonim memakai slot global company_id={$s['company_id']}");
                    return $s;
                }
            }
        }

        return null;
    }

    private function sessionIdForLegacy(array $legacy): ?string
    {
        return Cache::get($this->batchLockKey($legacy['batch_id']));
    }

    // ─── Commit (ikat UID) ────────────────────────────────────────────────

    /**
     * Ikat UID ke batch pada sesi. Dipakai ESP32 (/api/rfid/register) dan input manual/USB dari UI.
     * Bentuk hasil = bentuk respons LCD legacy (status,name,message,detail,batch_id,batch_code).
     */
    public function commit(array $session, string $uid, string $origin = 'real_esp32', ?RfidReader $reader = null, ?int $userId = null): array
    {
        try {
            return DB::transaction(function () use ($session, $uid, $origin, $reader, $userId) {
                $batch = Batch::where('company_id', $session['company_id'])
                    ->with('productCode')
                    ->lockForUpdate()
                    ->find($session['batch_id']);

                if (! $batch) {
                    $this->forget($session);
                    return ['status' => 'failed', 'name' => 'Error', 'message' => 'Batch tidak ada', '_code' => 'batch_missing'];
                }
                if ($batch->rfid_tag) {
                    $this->forget($session);
                    return [
                        'status'  => 'failed',
                        'name'    => $this->truncate($batch->batch_code, 16),
                        '_code'   => 'batch_already_tagged',
                        'message' => 'Batch sudah bertag',
                        'detail'  => "Batch {$batch->batch_code} sudah memiliki tag. Lepas tag lama dulu.",
                    ];
                }
                if ($taken = $this->findTagOwner($uid)) {
                    return $this->tagTakenResult($taken, $session['company_id']);
                }

                $tz = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';
                $batch->update([
                    'rfid_tag' => $uid,
                    'metadata' => array_merge($batch->metadata ?? [], [
                        'rfid_registered_at' => $tz,
                        'rfid_registered_by' => $session['initiated_by'] ?? 'System',
                    ]),
                ]);

                Checkpoint::create([
                    'batch_id'         => $batch->id,
                    'location_id'      => $batch->current_location_id,
                    'checkpoint_stage' => 'lab_kimia_create',
                    'weight_recorded'  => $batch->current_weight,
                    'weight_previous'  => $batch->current_weight,
                    'weight_loss'      => 0,
                    'weight_loss_pct'  => 0,
                    'has_discrepancy'  => false,
                    'is_verified'      => true,
                    'notes'            => "RFID Tag terdaftar: UID {$uid} oleh {$session['initiated_by']}",
                    'checkpoint_data'  => [
                        'action'        => 'rfid_register',
                        'rfid_uid'      => $uid,
                        'initiated_by'  => $session['initiated_by'] ?? 'System',
                        'timestamp_wib' => $tz,
                    ],
                    'rfid_scan_code' => $uid,
                    'rfid_origin'    => $origin,
                    'reader_id'      => $reader?->id ?? ($session['reader_id'] ?? null),
                    'recorded_by'    => $userId ?? ($session['user_id'] ?? null),
                    'recorded_at'    => now(),
                ]);

                RfidTagEvent::create([
                    'company_id'   => $batch->company_id,
                    'batch_id'     => $batch->id,
                    'uid'          => $uid,
                    'event'        => 'registered',
                    'reader_id'    => $reader?->id ?? ($session['reader_id'] ?? null),
                    'performed_by' => $userId ?? ($session['user_id'] ?? null),
                ]);

                $this->forget($session);
                Log::info("RFID Register: UID={$uid} → Batch={$batch->batch_code}");

                return [
                    'status'     => 'success',
                    'name'       => $this->truncate($batch->batch_code, 16),
                    'message'    => 'Tag terdaftar!',
                    'detail'     => "Batch {$batch->batch_code} ({$batch->productCode?->code}) berhasil ditag UID {$uid}.",
                    'batch_id'   => $batch->id,
                    'batch_code' => $batch->batch_code,
                    '_code'      => 'tag_registered',
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Balapan dua registrasi dengan UID yang sama: UNIQUE di DB menang.
            return $this->tagTakenResult($this->findTagOwner($uid), $session['company_id']);
        }
    }

    /** Pemilik tag termasuk batch yang sudah di-soft-delete (UNIQUE DB mencakup baris itu). */
    public function findTagOwner(string $uid): ?Batch
    {
        return Batch::withTrashed()->where('rfid_tag', $uid)->first();
    }

    /** Pesan 'Tag sudah dipakai' — kode batch hanya diungkap bila milik perusahaan yang sama. */
    public function tagTakenResult(?Batch $owner, int $companyId): array
    {
        $sameCompany = $owner && $owner->company_id === $companyId;
        Log::warning('RFID Register: UID sudah dipakai' . ($owner ? " batch_id={$owner->id}" : ''));

        return [
            'status'  => 'failed',
            '_code'   => 'tag_in_use',
            'name'    => $sameCompany ? $this->truncate($owner->batch_code, 16) : 'Tag Dipakai',
            'message' => 'Tag sudah dipakai',
            'detail'  => $sameCompany
                ? "UID ini sudah digunakan oleh batch {$owner->batch_code}" . ($owner->trashed() ? ' (diarsipkan).' : '.')
                : 'UID ini sudah digunakan.',
        ];
    }

    /** Jalur ESP32: tidak ada sesi → pesan LCD tetap. */
    public function noSessionResult(): array
    {
        return [
            'status'  => 'failed',
            '_code'   => 'no_session',
            'name'    => 'Tidak Ada Sesi',
            'message' => 'Tidak ada sesi',
            'detail'  => 'Mulai sesi registrasi dari panel web MINETRACE terlebih dahulu.',
        ];
    }

    private function truncate(string $text, int $max = 16): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max);
    }
}
