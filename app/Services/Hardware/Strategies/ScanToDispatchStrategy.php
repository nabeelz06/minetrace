<?php

namespace App\Services\Hardware\Strategies;

use App\Contracts\ScanAuthorizationStrategy;
use App\Models\Batch;
use App\Services\Process\StageTransitionService;
use App\Services\Hardware\RfidService;
use Illuminate\Support\Facades\Log;

/**
 * ScanToDispatchStrategy — Scan OUT triggers forwardBatch() stage transition.
 *
 * Architecture constraint: applies the same Chain-of-Responsibility guards
 * as ProcessController::forwardBatch() before any state change.
 * Weight is preserved from current batch weight (no re-weighing at RFID gate).
 */
class ScanToDispatchStrategy implements ScanAuthorizationStrategy
{
    public function __construct(
        private RfidService $rfid,
        private ?StageTransitionService $transitions = null,
    ) {
        $this->transitions ??= app(StageTransitionService::class);
    }

    public function handleScanIn(Batch $batch, string $stage, array $context): array
    {
        // Mode scan_to_dispatch: Scan IN tetap audit-only.
        return $this->rfid->checkpointIn(
            $batch->rfid_tag,
            $batch->company_id,
            $stage,
            $context['rfid_origin'] ?? 'real_esp32',
            $context['meta'] ?? []
        );
    }

    public function handleScanOut(Batch $batch, string $stage, array $context): array
    {
        // Langkah 1: audit trail — SELALU tercatat.
        $rfidOrigin  = $context['rfid_origin'] ?? 'real_esp32';
        $auditResult = $this->rfid->checkpointOut($batch->rfid_tag, $batch->company_id, $stage, $rfidOrigin, $context['meta'] ?? []);

        if ($auditResult['status'] !== 'success') {
            return $auditResult;
        }

        // Langkah 2: kirim batch lewat layanan transisi yang SAMA dengan web.
        // Batch tetap di tahap ini (status in_transit) sampai tahap tujuan menerimanya.
        // Berat tidak ditimbang ulang di gerbang RFID (berat batch tidak disentuh).
        try {
            $result = $this->transitions->dispatch($batch, $stage, ['source' => 'rfid']);

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => true,
                'transition_message'   => "Batch otomatis dikirim ke stage {$result['to_stage']}.",
            ]);
        } catch (\DomainException $e) {
            Log::warning("ScanToDispatchStrategy: {$e->getMessage()}");

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error("ScanToDispatchStrategy: transisi gagal — {$e->getMessage()}");

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => 'Internal error saat dispatch.',
            ]);
        }
    }

    public function getMode(): string { return 'scan_to_dispatch'; }

    public function getDescription(): string
    {
        return 'Scan OUT mengaktifkan pengiriman batch otomatis ke stage berikutnya (guard identik dengan web).';
    }
}
