<?php

namespace App\Services\Hardware\Uhf;

/** Satu bingkai UR4 yang sudah lolos pemeriksaan panjang, ekor, dan XOR. */
final class Ur4Frame
{
    public function __construct(
        public readonly string $header,
        public readonly int $cmd,
        public readonly string $payload,
    ) {}

    public function isTagReport(): bool
    {
        return $this->cmd === ChainwayUr4Protocol::CMD_TAG_REPORT;
    }
}
