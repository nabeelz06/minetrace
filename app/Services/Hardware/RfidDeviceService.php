<?php

namespace App\Services\Hardware;

use App\Exceptions\DeviceAuthException;
use App\Models\Company;
use App\Models\RfidReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RfidDeviceService — registri perangkat: penerbitan/rotasi kunci, autentikasi,
 * resolusi identitas request (DeviceContext), dan heartbeat.
 */
class RfidDeviceService
{
    private const KEY_PREFIX = 'mtr_';

    /** Hindari menulis DB di setiap scan: last_seen diperbarui paling cepat tiap N detik per reader. */
    private const TOUCH_INTERVAL = 15;

    // ─── Kunci ────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string} [kunci-asli, prefix, hash] */
    private function newKey(): array
    {
        $plain = self::KEY_PREFIX.Str::random(40);

        return [$plain, substr($plain, 0, 8), hash('sha256', $plain)];
    }

    /** @return array{0:RfidReader,1:string} [reader, kunci-asli (tampil SEKALI)] */
    public function create(int $companyId, array $attrs, ?int $userId): array
    {
        [$plain, $prefix, $hash] = $this->newKey();

        $reader = RfidReader::create([
            'company_id' => $companyId,
            'code' => strtoupper($attrs['code']),
            'name' => $attrs['name'],
            'stage' => $attrs['stage'] ?? null,
            'direction' => $attrs['direction'] ?? 'any',
            'device_type' => $attrs['device_type'] ?? 'esp32_rc522',
            'connection' => $attrs['connection'] ?? null,
            'camera_url' => $attrs['camera_url'] ?? null,
            'key_prefix' => $prefix,
            'key_hash' => $hash,
            'is_active' => true,
            'key_rotated_at' => now(),
            'created_by' => $userId,
        ]);

        Log::info("RFID reader dibuat: {$reader->code} company_id={$companyId} oleh={$userId}");

        return [$reader, $plain];
    }

    /** Rotasi kunci: kunci lama langsung tidak berlaku. */
    public function rotateKey(RfidReader $reader): string
    {
        [$plain, $prefix, $hash] = $this->newKey();
        $reader->update(['key_prefix' => $prefix, 'key_hash' => $hash, 'key_rotated_at' => now()]);

        Log::info("RFID reader kunci dirotasi: {$reader->code} company_id={$reader->company_id}");

        return $plain;
    }

    // ─── Autentikasi & konteks ────────────────────────────────────────────

    public function findByKey(?string $key): ?RfidReader
    {
        $key = trim((string) $key);
        if ($key === '' || ! str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        return RfidReader::where('key_hash', hash('sha256', $key))->first();
    }

    /**
     * Tentukan identitas pemanggil (urutan: kunci perangkat → kode perusahaan → anonim legacy).
     *
     * @throws DeviceAuthException
     */
    public function resolveContext(Request $request): DeviceContext
    {
        $key = $request->header('X-Device-Key');

        if ($key !== null && $key !== '') {
            $reader = $this->findByKey($key);
            if (! $reader) {
                throw new DeviceAuthException('Kunci perangkat tidak valid', 401);
            }
            if (! $reader->is_active) {
                throw new DeviceAuthException('Perangkat dinonaktifkan', 403);
            }

            return new DeviceContext($reader->company_id, $reader, 'device_key');
        }

        if (config('rfid.require_device_key')) {
            throw new DeviceAuthException('Kunci perangkat wajib', 401);
        }

        if ($code = $request->header('X-Company-Code')) {
            $company = Company::where('code', $code)->first();
            if ($company) {
                return new DeviceContext($company->id, null, 'company_code');
            }
            Log::warning("RFID: X-Company-Code={$code} tidak dikenal; diperlakukan sebagai perangkat anonim.");
        }

        if (! config('rfid.legacy_anonymous')) {
            throw new DeviceAuthException('Identitas perangkat wajib (X-Device-Key)', 401);
        }

        return new DeviceContext($this->defaultCompanyId(), null, 'anonymous');
    }

    /**
     * Perusahaan default untuk perangkat anonim: RFID_DEFAULT_COMPANY_CODE (getenv, pola lama agar
     * tidak terkena staleness config:cache), lalu id=1 (BIM — fallback absolut terakhir, perilaku lama).
     */
    public function defaultCompanyId(): int
    {
        $code = trim((string) (getenv('RFID_DEFAULT_COMPANY_CODE') ?: ''));
        if ($code !== '') {
            $company = Company::where('code', $code)->first();
            if ($company) {
                return $company->id;
            }
            Log::warning("RFID_DEFAULT_COMPANY_CODE={$code} tidak ditemukan di tabel companies.");
        }

        return 1;
    }

    // ─── Aktivitas & heartbeat ────────────────────────────────────────────

    /** Tandai reader hidup (dipanggil di tiap scan/register; tulis DB dibatasi). */
    public function touch(RfidReader $reader, ?string $ip = null): void
    {
        if (Cache::add("rfid:seen:{$reader->id}", 1, self::TOUCH_INTERVAL)) {
            $reader->forceFill(['last_seen_at' => now(), 'last_ip' => $ip ?? $reader->last_ip])->save();
        }
    }

    /**
     * @param  array  $payload  {firmware?, uptime_s?, rssi?, free_heap?, queued?, device_time?}
     * @return array respons heartbeat (waktu server untuk sinkronisasi jam perangkat)
     */
    public function heartbeat(RfidReader $reader, array $payload, ?string $ip): array
    {
        $skew = null;
        if (! empty($payload['device_time'])) {
            $skew = now()->timestamp - (int) $payload['device_time'];
        }

        $status = array_filter([
            'uptime_s' => $payload['uptime_s'] ?? null,
            'rssi' => $payload['rssi'] ?? null,
            'free_heap' => $payload['free_heap'] ?? null,
            'queued' => $payload['queued'] ?? null,
            'clock_skew' => $skew,
            'clock_ok' => $skew === null ? null : abs($skew) <= config('rfid.clock_skew_seconds'),
        ], fn ($v) => $v !== null);

        $reader->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $ip,
            'firmware_version' => $payload['firmware'] ?? $reader->firmware_version,
            'last_status' => $status,
        ])->save();
        Cache::put("rfid:seen:{$reader->id}", 1, self::TOUCH_INTERVAL);

        return [
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
            'epoch' => now()->timestamp,
            'reader' => $reader->code,
            'stage' => $reader->stage,
            'direction' => $reader->direction,
            'interval_s' => 30,
        ];
    }

    /** Ringkasan untuk UI (tanpa kunci). */
    public function present(RfidReader $reader): array
    {
        return [
            'id' => $reader->id,
            'code' => $reader->code,
            'name' => $reader->name,
            'stage' => $reader->stage,
            'direction' => $reader->direction,
            'device_type' => $reader->device_type,
            'connection' => $reader->connection,
            'camera_url' => $reader->camera_url,
            'key_prefix' => $reader->key_prefix,
            'is_active' => $reader->is_active,
            'state' => $reader->connectionState(),
            'last_seen_at' => $reader->last_seen_at?->toIso8601String(),
            'last_ip' => $reader->last_ip,
            'firmware_version' => $reader->firmware_version,
            'last_status' => $reader->last_status,
            'key_rotated_at' => $reader->key_rotated_at?->toIso8601String(),
        ];
    }
}
