<?php

namespace App\Services\Hardware\Uhf;

/** Parameter tautan UR4 (satuan detik kecuali disebut lain). Bawaan = config('rfid.uhf.*'). */
final class Ur4LinkConfig
{
    /**
     * @param  array<int,string>  $antennaMap  nomor antena => 'in'|'out' (kosong = tanpa pemetaan)
     */
    public function __construct(
        public readonly float $connectTimeout = 3.0,
        public readonly float $handshakeTimeout = 2.0,
        public readonly float $handshakeResendEvery = 0.1,
        public readonly float $handshakeResendFor = 1.0,
        public readonly float $quietAfterAck = 0.3,
        public readonly float $configTimeout = 1.0,
        public readonly float $reconnectPause = 2.0,
        public readonly int $reconnectMax = 30,
        public readonly float $idleHeartbeatAfter = 5.0,
        public readonly float $heartbeatInterval = 3.0,
        public readonly int $rearmPerMinute = 3,
        public readonly int $contestedResets = 2,
        public readonly int $contestedWindow = 120,
        public readonly int $contestedBackoff = 60,
        public readonly float $mismatchRetry = 30.0,
        public readonly float $stalledRetry = 30.0,
        public readonly ?float $maxPowerDbm = null,
        public readonly float $warnPowerDbm = 26.0,
        public readonly bool $verifyXor = true,
        public readonly array $antennaMap = [],
        /** false = berhenti di state Ready setelah konfigurasi dibaca (alat diagnostik; tidak memancar). */
        public readonly bool $startInventory = true,
    ) {}

    public static function fromConfig(array $antennaMap = []): self
    {
        $c = (array) config('rfid.uhf', []);

        return new self(
            handshakeTimeout: (float) ($c['handshake_timeout'] ?? 2.0),
            reconnectPause: (float) ($c['reconnect_pause'] ?? 2.0),
            reconnectMax: (int) ($c['reconnect_max'] ?? 30),
            idleHeartbeatAfter: (float) ($c['idle_heartbeat_after'] ?? 5.0),
            heartbeatInterval: (float) ($c['heartbeat_interval'] ?? 3.0),
            rearmPerMinute: (int) ($c['rearm_per_minute'] ?? 3),
            contestedResets: (int) ($c['contested_resets'] ?? 2),
            contestedWindow: (int) ($c['contested_window'] ?? 120),
            contestedBackoff: (int) ($c['contested_backoff'] ?? 60),
            maxPowerDbm: isset($c['max_power_dbm']) ? (float) $c['max_power_dbm'] : null,
            warnPowerDbm: (float) ($c['warn_power_dbm'] ?? 26.0),
            verifyXor: (bool) ($c['verify_xor'] ?? true),
            antennaMap: $antennaMap,
        );
    }
}
