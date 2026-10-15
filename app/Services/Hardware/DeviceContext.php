<?php

namespace App\Services\Hardware;

use App\Models\RfidReader;

/**
 * DeviceContext — identitas perangkat yang memanggil endpoint scan/register.
 *
 *   identity = device_key   : reader terdaftar (X-Device-Key) → tenant = milik reader, isolasi KETAT
 *   identity = company_code : X-Company-Code valid           → isolasi KETAT per perusahaan
 *   identity = anonymous    : perangkat legacy tanpa identitas → perilaku lama (default company + fallback)
 */
final class DeviceContext
{
    public function __construct(
        public readonly int $companyId,
        public readonly ?RfidReader $reader,
        public readonly string $identity,
    ) {}

    public function isStrict(): bool
    {
        return $this->identity !== 'anonymous';
    }
}
