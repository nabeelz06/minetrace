<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HardwareEventService — real-time event dispatcher untuk hardware events.
 *
 * Events yang ditangani:
 * - RFID scan (dari RFID reader di setiap checkpoint)
 * - Weight sensor (dari timbangan digital)
 * - CCTV bag count (dari computer vision — future Sprint)
 *
 * Menggunakan Cache sebagai simple event bus untuk saat ini.
 * Upgrade ke Laravel Broadcasting + WebSocket saat production.
 */
class HardwareEventService
{
    private const EVENT_TTL = 60; // Simpan event 60 detik

    /**
     * Dispatch hardware event ke cache (untuk polling dari frontend).
     */
    public function dispatch(string $type, array $data, int $companyId): void
    {
        $eventId  = uniqid('hw_', true);
        $cacheKey = "hw_events:{$companyId}";

        // Ambil queue events yang ada
        $events = Cache::get($cacheKey, []);

        // Tambah event baru
        $events[] = [
            'id'         => $eventId,
            'type'       => $type,
            'data'       => $data,
            'created_at' => now()->toIso8601String(),
        ];

        // Batasi max 50 events di queue
        if (count($events) > 50) {
            $events = array_slice($events, -50);
        }

        Cache::put($cacheKey, $events, self::EVENT_TTL);

        Log::info("HardwareEvent dispatched", [
            'type'       => $type,
            'company_id' => $companyId,
            'event_id'   => $eventId,
        ]);
    }

    /**
     * Poll events untuk company tertentu (untuk frontend polling).
     * Frontend call ini setiap 3 detik untuk live updates.
     */
    public function pollEvents(int $companyId, ?string $sinceId = null): array
    {
        $cacheKey = "hw_events:{$companyId}";
        $events   = Cache::get($cacheKey, []);

        if ($sinceId) {
            $found  = false;
            $events = array_filter($events, function ($e) use ($sinceId, &$found) {
                if ($e['id'] === $sinceId) { $found = true; return false; }
                return $found;
            });
        }

        return array_values($events);
    }

    /**
     * Clear events queue untuk company.
     */
    public function clearEvents(int $companyId): void
    {
        Cache::forget("hw_events:{$companyId}");
    }

    /**
     * Dispatch RFID scan event.
     */
    public function dispatchRfidScan(array $scanResult, int $companyId): void
    {
        $this->dispatch('rfid_scan', $scanResult, $companyId);
    }

    /**
     * Dispatch weight measurement event.
     */
    public function dispatchWeightMeasurement(
        int    $batchId,
        float  $weightKg,
        string $locationCode,
        int    $companyId
    ): void {
        $this->dispatch('weight_measurement', [
            'batch_id'      => $batchId,
            'weight_kg'     => $weightKg,
            'location_code' => $locationCode,
            'measured_at'   => now()->toIso8601String(),
        ], $companyId);
    }
}