<?php

namespace App\Http\Requests\Rfid;

use App\Models\RfidReader;
use App\Services\Hardware\Uhf\EndpointGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReaderRequest extends FormRequest
{
    /** Tahap yang punya scan RFID (satu sumber: ProcessFlow). */
    public static function stages(): array
    {
        return \App\Support\ProcessFlow::rfidStages();
    }

    public function authorize(): bool
    {
        return true; // otorisasi di controller (Gate rfid.manage)
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                              Rule::unique('rfid_readers', 'code')->where('company_id', $this->user()->company_id)],
            'name'        => ['required', 'string', 'max:100'],
            'stage'       => ['nullable', Rule::in(self::stages())],
            'direction'   => ['required', Rule::in(RfidReader::DIRECTIONS)],
            'device_type' => ['required', Rule::in(RfidReader::DEVICE_TYPES)],
        ] + self::connectionRules();
    }

    /**
     * Koneksi reader jaringan (Chainway UR4). host wajib untuk uhf_chainway_ur4.
     * antennas: {"1":"in","2":"out"} — arah per antena (untuk gate dua arah, set arah reader = any).
     */
    public static function connectionRules(): array
    {
        return [
            'connection'                  => ['nullable', 'array'],
            'connection.host'             => ['nullable', 'required_if:device_type,uhf_chainway_ur4', 'string', 'max:255',
                                              'regex:/^[A-Za-z0-9.:_-]+$/'],
            'connection.port'             => ['nullable', 'integer', 'between:1,65535'],
            'connection.antennas'         => ['nullable', 'array'],
            'connection.antennas.*'       => [Rule::in(['in', 'out'])],
            'connection.min_rssi'         => ['nullable', 'integer', 'between:-120,0'],
            'connection.presence_seconds' => ['nullable', 'integer', 'between:1,3600'],
        ];
    }

    /**
     * Nomor antena 1–16; arah antena tidak boleh bertentangan dengan arah tetap reader; alamat jaringan
     * lolos EndpointGuard (cegah SSRF) dan tidak dipakai reader aktif lain (UR4 hanya melayani satu klien).
     *
     * @param RfidReader|null $existing reader yang sedang diubah (null saat membuat)
     */
    public static function validateConnection(Validator $validator, ?string $direction, ?RfidReader $existing = null): void
    {
        self::validateEndpoint($validator, $existing);

        $antennas = $validator->getData()['connection']['antennas'] ?? null;
        if (! is_array($antennas)) {
            return;
        }
        foreach ($antennas as $port => $dir) {
            if (! ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 16) {
                $validator->errors()->add('connection.antennas', 'Nomor antena harus 1–16.');
                return;
            }
            if ($direction && $direction !== 'any' && $dir !== $direction) {
                $validator->errors()->add('connection.antennas', 'Arah antena bertentangan dengan arah reader. Pilih arah "Dua arah".');
                return;
            }
        }
    }

    /** Host:port efektif (gabungan input + data lama) untuk reader jaringan. */
    private static function validateEndpoint(Validator $validator, ?RfidReader $existing): void
    {
        $data = $validator->getData();
        $type = $data['device_type'] ?? $existing?->device_type;
        if (! in_array($type, RfidReader::NETWORK_TYPES, true)) {
            return;
        }

        $conn = array_merge($existing?->connectionConfig() ?? [], is_array($data['connection'] ?? null) ? $data['connection'] : []);
        $host = trim((string) ($conn['host'] ?? ''));
        if ($host === '') {
            return;   // required_if sudah menandai bila wajib
        }
        $port = (int) ($conn['port'] ?? config('rfid.uhf.ur4_port', 8888));

        if ($code = EndpointGuard::validate($host, $port)) {
            $validator->errors()->add('connection.host', EndpointGuard::message($code));

            return;
        }

        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true);
        if ($active && self::endpointTaken($host, $port, $existing?->id)) {
            $validator->errors()->add('connection.host', EndpointGuard::message('duplicate'));
        }
    }

    /** UR4 hanya melayani satu klien: dua reader aktif ke host:port yang sama akan saling merebut aliran. */
    private static function endpointTaken(string $host, int $port, ?int $ignoreId): bool
    {
        return RfidReader::whereIn('device_type', RfidReader::NETWORK_TYPES)
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->contains(function (RfidReader $r) use ($host, $port) {
                $c = $r->connectionConfig();

                return ($c['host'] ?? null) === $host
                    && (int) ($c['port'] ?? config('rfid.uhf.ur4_port', 8888)) === $port;
            });
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => self::validateConnection($v, $this->input('direction')));
    }
}
