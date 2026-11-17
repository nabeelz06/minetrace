<?php

namespace Tests\Unit\Services;

use App\Models\RfidReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RfidReader::supportedDirections — arah scan yang BENAR-BENAR bisa dihasilkan sebuah reader.
 *
 * Dasar panel "Kesiapan alur" di UI: tahap yang tidak punya reader untuk satu arah (mis. semua reader "Scan Masuk saja")
 * tidak akan pernah menghasilkan scan pada arah itu dari perangkat. Aturannya harus identik dengan jalur scan sebenarnya
 * (directionForAntenna + penolakan arah tetap di RfidScanService::scanOnce).
 */
class RfidReaderDirectionsTest extends TestCase
{
    /** @return array<string, array{0:string,1:string,2:array|null,3:list<string>}> */
    public static function cases(): array
    {
        $twoWay = ['1' => 'in', '2' => 'out'];

        return [
            'ESP32 satu arah masuk' => ['esp32_rc522', 'in', null, ['in']],
            'ESP32 satu arah keluar' => ['esp32_rc522', 'out', null, ['out']],
            'ESP32 dua arah (arah dikirim perangkat)' => ['esp32_rc522', 'any', null, ['in', 'out']],
            'UHF desktop dua arah (dipilih operator)' => ['uhf_desktop_r2000', 'any', null, ['in', 'out']],
            'UR4 "Scan Masuk saja" tanpa pemetaan' => ['uhf_chainway_ur4', 'in', null, ['in']],
            'UR4 "Scan Keluar saja" tanpa pemetaan' => ['uhf_chainway_ur4', 'out', null, ['out']],
            'UR4 "Dua arah" TANPA pemetaan antena = hanya Masuk' => ['uhf_chainway_ur4', 'any', null, ['in']],
            'UR4 "Dua arah" + antena 1=Masuk, 2=Keluar' => ['uhf_chainway_ur4', 'any', $twoWay, ['in', 'out']],
            'UR4 "Dua arah" hanya antena 1=Keluar (antena lain tidak dipakai)' => ['uhf_chainway_ur4', 'any', ['1' => 'out'], ['out']],
            'UR4 "Scan Masuk saja" dengan pemetaan bertentangan (data lama): Keluar ditolak server' => ['uhf_chainway_ur4', 'in', $twoWay, ['in']],
            'UR4 "Dua arah" dengan pemetaan tak valid diabaikan' => ['uhf_chainway_ur4', 'any', ['1' => 'x', '2' => ''], ['in']],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('cases')]
    public function arah_yang_dapat_dihasilkan_mengikuti_aturan_server(string $type, string $direction, ?array $antennas, array $expected): void
    {
        $reader = (new RfidReader)->forceFill([
            'device_type' => $type,
            'direction' => $direction,
            'connection' => $antennas === null ? ['host' => '192.168.99.202', 'port' => 8888] : ['host' => '192.168.99.202', 'port' => 8888, 'antennas' => $antennas],
        ]);

        $this->assertSame($expected, $reader->supportedDirections());
    }

    #[Test]
    public function reader_tanpa_atribut_koneksi_tidak_menimbulkan_galat(): void
    {
        $reader = (new RfidReader)->forceFill(['device_type' => 'uhf_chainway_ur4', 'direction' => 'any', 'connection' => null]);

        $this->assertSame(['in'], $reader->supportedDirections());
    }
}
