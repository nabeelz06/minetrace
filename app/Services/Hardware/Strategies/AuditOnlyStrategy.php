<?php

namespace App\Services\Hardware\Strategies;

use App\Contracts\ScanAuthorizationStrategy;
use App\Models\Batch;
use App\Services\Hardware\RfidService;

/**
 * AuditOnlyStrategy — default behavior.
 * Scan = checkpoint record only. No stage transition.
 * This is the industry-standard safe mode.
 */
class AuditOnlyStrategy implements ScanAuthorizationStrategy
{
    public function __construct(private RfidService $rfid) {}

    public function handleScanIn(Batch $batch, string $stage, array $context): array
    {
        return $this->rfid->checkpointIn(
            uid:        $batch->rfid_tag,
            companyId:  $batch->company_id,
            scanStage:  $stage,
            rfidOrigin: $context['rfid_origin'] ?? 'real_esp32',
            meta:       $context['meta'] ?? [],
        );
    }

    public function handleScanOut(Batch $batch, string $stage, array $context): array
    {
        return $this->rfid->checkpointOut(
            uid:        $batch->rfid_tag,
            companyId:  $batch->company_id,
            scanStage:  $stage,
            rfidOrigin: $context['rfid_origin'] ?? 'real_esp32',
            meta:       $context['meta'] ?? [],
        );
    }

    public function getMode(): string { return 'audit_only'; }

    public function getDescription(): string
    {
        return 'Scan RFID hanya mencatat audit trail. Perpindahan stage dilakukan manual via web panel.';
    }
}
