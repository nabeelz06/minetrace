<?php

namespace App\Services\Hardware;

use App\Contracts\ScanAuthorizationStrategy;
use App\Models\Batch;
use App\Models\RfidAuthorizationMode;
use App\Services\Hardware\Strategies\AuditOnlyStrategy;
use App\Services\Hardware\Strategies\ScanToDispatchStrategy;
use App\Services\Hardware\Strategies\ScanToReceiveStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RfidAuthorizationService — Progressive Authorization Model facade.
 *
 * Reads authorization mode from DB (cached) → returns correct Strategy instance.
 * Cache key: rfid_auth_mode_{companyId}_{stage}  TTL: 1 hour
 *
 * Design: Facade + Strategy + Factory
 */
class RfidAuthorizationService
{
    private const CACHE_TTL    = 3600;
    private const CACHE_PREFIX = 'rfid_auth_mode_';

    private const VALID_MODES = ['audit_only', 'scan_to_receive', 'scan_to_dispatch'];

    public function __construct(private RfidService $rfid) {}

    // ─── Strategy factory ────────────────────────────────────────────────────

    public function getStrategy(int $companyId, string $stage): ScanAuthorizationStrategy
    {
        $mode = $this->getMode($companyId, $stage);

        return match ($mode) {
            'scan_to_receive'  => new ScanToReceiveStrategy($this->rfid),
            'scan_to_dispatch' => new ScanToDispatchStrategy($this->rfid),
            default            => new AuditOnlyStrategy($this->rfid),
        };
    }

    // ─── Mode management ────────────────────────────────────────────────────

    public function getMode(int $companyId, string $stage): string
    {
        $cacheKey = self::CACHE_PREFIX . $companyId . '_' . $stage;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($companyId, $stage) {
            return RfidAuthorizationMode::where('company_id', $companyId)
                ->where('stage', $stage)
                ->value('mode') ?? 'audit_only';
        });
    }

    public function setMode(int $companyId, string $stage, string $mode, int $updatedBy): void
    {
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException("Invalid authorization mode: {$mode}");
        }

        RfidAuthorizationMode::updateOrCreate(
            ['company_id' => $companyId, 'stage' => $stage],
            ['mode' => $mode, 'updated_by' => $updatedBy]
        );

        Cache::forget(self::CACHE_PREFIX . $companyId . '_' . $stage);

        Log::info("RFID Authorization Mode SET: mode={$mode} stage={$stage} company_id={$companyId} by={$updatedBy}");
    }

    public function getAllModes(int $companyId): array
    {
        $dbModes = RfidAuthorizationMode::where('company_id', $companyId)
            ->get()
            ->keyBy('stage')
            ->map(fn ($r) => $r->mode)
            ->toArray();

        $stages = \App\Support\ProcessFlow::rfidStages();
        $result = [];

        foreach ($stages as $stage) {
            $mode = $dbModes[$stage] ?? 'audit_only';
            $result[$stage] = [
                'mode'        => $mode,
                'description' => $this->getModeDescription($mode),
                'label'       => $this->getModeLabel($mode),
            ];
        }

        return $result;
    }

    public function resetMode(int $companyId, string $stage): void
    {
        RfidAuthorizationMode::where('company_id', $companyId)
            ->where('stage', $stage)
            ->delete();

        Cache::forget(self::CACHE_PREFIX . $companyId . '_' . $stage);

        Log::info("RFID Authorization Mode RESET: stage={$stage} company_id={$companyId}");
    }

    public function getValidModes(): array
    {
        return array_map(fn ($m) => [
            'mode'        => $m,
            'label'       => $this->getModeLabel($m),
            'description' => $this->getModeDescription($m),
        ], self::VALID_MODES);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getModeLabel(string $mode): string
    {
        return match ($mode) {
            'scan_to_receive'  => 'Scan-to-Confirm-Receive',
            'scan_to_dispatch' => 'Scan-to-Confirm-Dispatch',
            default            => 'Audit Only (Manual)',
        };
    }

    private function getModeDescription(string $mode): string
    {
        return match ($mode) {
            'scan_to_receive'  => 'Scan IN mengaktifkan penerimaan batch otomatis ke stage tujuan.',
            'scan_to_dispatch' => 'Scan OUT mengaktifkan pengiriman batch otomatis ke stage berikutnya.',
            default            => 'Scan RFID hanya mencatat audit trail. Perpindahan stage dilakukan manual via web panel.',
        };
    }
}
