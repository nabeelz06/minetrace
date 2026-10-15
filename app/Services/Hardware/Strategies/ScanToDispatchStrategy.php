<?php

namespace App\Services\Hardware\Strategies;

use App\Contracts\ScanAuthorizationStrategy;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Location;
use App\Services\Hardware\RfidService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
    // ENUM mapping mirrors ProcessController::forwardBatch()
    private const DISPATCH_CHECKPOINT = [
        'receiving'     => 'in_transit',
        'wet_process'   => 'wet_process_output',
        'dry_process'   => 'dry_process_output',
        'project_plant' => 'project_plant_output',
        'warehouse'     => 'warehouse_outbound',
    ];

    private const NEXT_STAGE = [
        'receiving'     => 'wet_process',
        'wet_process'   => 'dry_process',
        'dry_process'   => 'warehouse',
        'project_plant' => 'warehouse',
        'warehouse'     => null, // warehouse → handled separately via warehouseSendToLtj
    ];

    public function __construct(private RfidService $rfid) {}

    public function handleScanIn(Batch $batch, string $stage, array $context): array
    {
        // scan_to_dispatch mode: scan IN is still audit-only
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
        // Step 1: Audit trail (same as AuditOnly)
        $rfidOrigin = $context['rfid_origin'] ?? 'real_esp32';
        $auditResult = $this->rfid->checkpointOut($batch->rfid_tag, $batch->company_id, $stage, $rfidOrigin, $context['meta'] ?? []);

        if ($auditResult['status'] !== 'success') {
            return $auditResult;
        }

        // Step 2: State validation (mirrors ProcessController::forwardBatch() guards)
        $batchFresh = $batch->fresh();
        $nextStage  = self::NEXT_STAGE[$stage] ?? null;

        // Guard: must be in this stage; warehouse dispatch is not supported via RFID
        if ($batchFresh->process_stage !== $stage) {
            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => "Batch tidak berada di stage {$stage}.",
            ]);
        }

        if (!$nextStage) {
            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => "Stage {$stage} tidak memiliki next stage via RFID dispatch.",
            ]);
        }

        // Guard: dry_process cannot dispatch via RFID (requires dryTransform() with product split)
        if ($stage === 'dry_process') {
            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => 'Dry Process memerlukan transformasi batch. Gunakan web panel.',
            ]);
        }

        // Step 3: Execute dispatch transition
        try {
            $transitionResult = DB::transaction(function () use ($batchFresh, $stage, $nextStage) {
                $tz = 'Asia/Jakarta';

                $dispatchStage = self::DISPATCH_CHECKPOINT[$stage] ?? 'in_transit';

                Checkpoint::create([
                    'batch_id'         => $batchFresh->id,
                    'location_id'      => $batchFresh->current_location_id,
                    'checkpoint_stage' => $dispatchStage,
                    'weight_recorded'  => $batchFresh->current_weight,
                    'weight_previous'  => $batchFresh->current_weight,
                    'weight_loss'      => 0,
                    'weight_loss_pct'  => 0,
                    'has_discrepancy'  => false,
                    'is_verified'      => true,
                    'notes'            => "✅ RFID Auto-Dispatch: batch dikirim otomatis via RFID Scan OUT — " . Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    'checkpoint_data'  => [
                        'action'       => 'rfid_auto_dispatch',
                        'rfid_trigger' => true,
                        'from_stage'   => $stage,
                        'to_stage'     => $nextStage,
                        'timestamp'    => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    ],
                    'recorded_by' => null,
                    'recorded_at' => now(),
                ]);

                $batchFresh->update([
                    'process_stage' => $nextStage,
                    'status'        => 'in_transit',
                ]);

                return true;
            });

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => $transitionResult,
                'transition_message'   => "Batch otomatis dikirim ke stage {$nextStage}.",
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
        return 'Scan OUT mengaktifkan pengiriman batch otomatis ke stage berikutnya (Chain-of-Responsibility tervalidasi).';
    }
}
