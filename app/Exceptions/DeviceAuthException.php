<?php

namespace App\Exceptions;

use RuntimeException;

/** Perangkat gagal diautentikasi (kunci salah/nonaktif). Dipetakan ke JSON gaya perangkat oleh controller. */
class DeviceAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 401)
    {
        parent::__construct($message);
    }
}
