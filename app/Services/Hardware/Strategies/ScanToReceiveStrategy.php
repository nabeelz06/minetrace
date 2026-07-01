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
 * ScanToReceiveStrategy — Scan IN triggers receiveBatch() stage transition.
 *
 * Architecture constraint: applies the same Chain-of-Responsibility guards
 * as ProcessController::receiveBatch() before any state change.
 * Role check is replaced by the authorization mode gate (admin set mode = scan_to_receive).
 */
class ScanToReceiveStrategy implements ScanAuthorizationStrategy
{
    // ENUM mapping mirrors ProcessController::receiveBatch()
    private const RECEIVE_CHECKPOINT = [
        'receiving'     => 'receiving_verify',
        'wet_process'   => 'wet_process_input',
        'dry_process'   => 'dry_process_input',
        'project_plant' => 'project_plant_input',
        'warehouse'     => 'warehouse_inbound',
    ];

    private const PREV_STAGE = [
        'receiving'     => 'lab_kimia',
        'wet_process'   => 'receiving',
        'dry_process'   => 'wet_process',
        'project_plant' => 'warehouse',
        'warehouse'     => 'dry_process',
    ];

    public function __construct(private RfidService $rfid) {}

    public function handleScanIn(Batch $batch, string $stage, array $context): array
    {
        // Step 1: Audit trail (same as AuditOnly)
        $rfidOrigin = $context['rfid_origin'] ?? 'real_esp32';
        $auditResult = $this->rfid->checkpointIn($batch->rfid_tag, $batch->company_id, $stage, $rfidOrigin);

        if ($auditResult['status'] !== 'success') {
            return $auditResult;
        }

        // Step 2: Chain-of-Responsibility state validation (mirrors ProcessController guards)
        $tz         = 'Asia/Jakarta';
        $prevStage  = self::PREV_STAGE[$stage] ?? null;
        $batchFresh = $batch->fresh(['productCode']);

        // Guard: project_plant only accepts warehouse+in_transit MON
        if ($stage === 'project_plant') {
            if ($batchFresh->process_stage !== 'warehouse' || $batchFresh->status !== 'in_transit') {
                Log::warning("ScanToReceiveStrategy: batch {$batchFresh->batch_code} belum in_transit dari Warehouse");
                return array_merge($auditResult, [
                    'transition_attempted' => true,
                    'transition_success'   => false,
                    'transition_reason'    => 'Batch belum dalam status in_transit dari Warehouse.',
                ]);
            }
            if ($batchFresh->productCode?->code !== 'MON') {
                return array_merge($auditResult, [
                    'transition_attempted' => true,
                    'transition_success'   => false,
                    'transition_reason'    => 'Hanya batch MON yang dapat masuk Lab Analisis LTJ.',
                ]);
            }
        } elseif ($prevStage && $batchFresh->process_stage !== $prevStage) {
            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => false,
                'transition_reason'    => "Batch berada di {$batchFresh->process_stage}, bukan {$prevStage}.",
            ]);
        }

        // Step 3: Execute receive transition
        try {
            $transitionResult = DB::transaction(function () use ($batchFresh, $stage, $tz) {
                $locationId = Location::where('company_id', $batchFresh->company_id)
                    ->where('location_type', $this->stageLocationType($stage))
                    ->where('is_active', true)
                    ->value('id') ?? $batchFresh->current_location_id;

                $batchFresh->update([
                    'process_stage'       => $stage,
                    'status'              => 'in_process',
                    'current_location_id' => $locationId,
                ]);

                $checkpointStage = self::RECEIVE_CHECKPOINT[$stage] ?? $stage;

                Checkpoint::create([
                    'batch_id'         => $batchFresh->id,
                    'location_id'      => $locationId,
                    'checkpoint_stage' => $checkpointStage,
                    'weight_recorded'  => $batchFresh->current_weight,
                    'weight_previous'  => $batchFresh->current_weight,
                    'weight_loss'      => 0,
                    'weight_loss_pct'  => 0,
                    'has_discrepancy'  => false,
                    'is_verified'      => true,
                    'notes'            => "✅ RFID Auto-Receive: batch diterima otomatis via RFID Scan IN — " . Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    'checkpoint_data'  => [
                        'action'       => 'rfid_auto_receive',
                        'rfid_trigger' => true,
                        'from_stage'   => self::PREV_STAGE[$stage] ?? null,
                        'to_stage'     => $stage,
                        'timestamp'    => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
                    ],
                    'recorded_by' => null,
                    'recorded_at' => now(),
                ]);

                return true;
            });

            return array_merge($auditResult, [
                'transition_attempted' => true,
                'transition_success'   => $transitionResult,
                'transition_message'   => "Batch otomatis diterima di stage {$stage}.",
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
        // scan_to_receive mode: scan OUT is still audit-only
        return $this->rfid->checkpointOut(
            $batch->rfid_tag,
            $batch->company_id,
            $stage,
            $context['rfid_origin'] ?? 'real_esp32'
        );
    }

    public function getMode(): string { return 'scan_to_receive'; }

    public function getDescription(): string
    {
        return 'Scan IN mengaktifkan penerimaan batch otomatis ke stage tujuan (Chain-of-Responsibility tervalidasi).';
    }

    private function stageLocationType(string $stage): string
    {
        return match ($stage) {
            'project_plant' => 'project_plant',
            default         => $stage,
        };
    }
}
