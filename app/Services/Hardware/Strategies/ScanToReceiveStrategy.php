<?php

namespace App\Services\Hardware\Strategies;

use App\Contracts\ScanAuthorizationStrategy;
use App\Models\Batch;
use App\Services\Process\StageTransitionService;
use App\Services\Hardware\RfidService;
use Illuminate\Support\Facades\Log;

/**
 * ScanToReceiveStrategy — Scan IN triggers receiveBatch() stage transition.
 *
 * Architecture constraint: applies the same Chain-of-Responsibility guards
 * as ProcessController::receiveBatch() before any state change.
 * Role check is replaced by the authorization mode gate (admin set mode = scan_to_receive).
 */
class ScanToReceiveStrategy implements ScanAuthorizationStrategy
{
    public function __construct(
        private RfidService $rfid,
        private ?StageTransitionService $transitions = null,
    ) {
        $this->transitions ??= app(StageTransitionService::class);
    }

    public function handleScanIn(Batch $batch, string $stage, array $context): array
    {
        // Langkah 1: audit trail (sama dengan AuditOnly) — SELALU tercatat.
        $rfidOrigin  = $context['rfid_origin'] ?? 'real_esp32';
        $auditResult = $this->rfid->checkpointIn($batch->rfid_tag, $batch->company_id, $stage, $rfidOrigin, $context['meta'] ?? []);

        if ($auditResult['status'] !== 'success') {
            return $auditResult;
        }

        // Langkah 2: terima batch lewat layanan transisi yang SAMA dengan web (guard identik, atomik).
        try {
            $this->transitions->receive($batch, $stage, ['source' => 'rfid']);

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => true,
                'transition_message'   => "Batch otomatis diterima di stage {$stage}.",
            ]);
        } catch (\DomainException $e) {
            Log::warning("ScanToReceiveStrategy: {$e->getMessage()}");

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error("ScanToReceiveStrategy: transisi gagal — {$e->getMessage()}");

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => 'Internal error saat transisi.',
            ]);
        }
    }

    public function handleScanOut(Batch $batch, string $stage, array $context): array
    {
        // Mode scan_to_receive: Scan OUT tetap audit-only.
        return $this->rfid->checkpointOut(
            $batch->rfid_tag,
            $batch->company_id,
            $stage,
            $context['rfid_origin'] ?? 'real_esp32',
            $context['meta'] ?? []
        );
    }

    public function getMode(): string { return 'scan_to_receive'; }

    public function getDescription(): string
    {
        return 'Scan IN mengaktifkan penerimaan batch otomatis ke stage tujuan (guard identik dengan web).';
    }
}
