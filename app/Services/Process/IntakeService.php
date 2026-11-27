<?php

namespace App\Services\Process;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\MassBalanceThreshold;
use App\Support\ProcessFlow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receiving & Wet digabung (docs/superpowers/specs/2026-11-24-receiving-wet-merge-design.md):
 * drum besi yang tiba di Receiving ditimbang dan dicek kadar Sn, lalu isinya langsung dituang ke Wet —
 * batch diterima di wet_process dan masuk himpunan Wet (tag dilepas, PoolService::join).
 *
 * Susut melewati ambang tidak menahan drum: batch tetap masuk Wet dan ditandai has_discrepancy.
 */
class IntakeService
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(private readonly StageTransitionService $transitions) {}

    /**
     * @param  array{note?:?string, partner_signatory?:?string}  $extra  catatan operator + penanda tangan mitra (validator), opsional
     *
     * @throws \DomainException
     */
    public function record(Batch $batch, float $weight, float $snPct, ?int $userId, string $source = 'web', array $extra = []): Batch
    {
        if ($weight <= 0) {
            throw new \DomainException('Berat drum harus lebih dari 0.');
        }
        if ($snPct < 0 || $snPct > 100) {
            throw new \DomainException('Kadar Sn harus di antara 0 dan 100%.');
        }

        $note      = trim((string) ($extra['note'] ?? ''));
        $signatory = trim((string) ($extra['partner_signatory'] ?? ''));

        return DB::transaction(function () use ($batch, $weight, $snPct, $userId, $source, $note, $signatory) {
            $fresh = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $allowed = [ProcessFlow::STATUS_AWAITING_INTAKE, ProcessFlow::STATUS_AFTER_RECEIVE];
            if ($fresh->process_stage !== 'receiving' || ! in_array($fresh->status, $allowed, true)) {
                throw new \DomainException("Batch {$fresh->batch_code} tidak sedang menunggu timbang di Receiving.");
            }

            $previous = (float) $fresh->current_weight;
            $loss     = $previous - $weight;
            $lossPct  = $previous > 0 ? round($loss / $previous * 100, 2) : 0.0;

            $threshold = MassBalanceThreshold::where('company_id', $fresh->company_id)->where('process_stage', 'receiving')->first();
            $alert     = $threshold ? $lossPct >= (float) $threshold->warning_threshold_pct : false;
            $ts        = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

            Checkpoint::create([
                'batch_id'         => $fresh->id,
                'location_id'      => $fresh->current_location_id,
                'checkpoint_stage' => 'receiving_verify',
                'weight_recorded'  => $weight,
                'weight_previous'  => $previous,
                'weight_loss'      => $loss,
                'weight_loss_pct'  => $lossPct,
                'has_discrepancy'  => $alert,
                'is_verified'      => true,
                'notes'            => "Drum besi ditimbang {$weight} kg, kadar Sn {$snPct}%" . ($note !== '' ? " — {$note}" : '') . " — {$ts}",
                'checkpoint_data'  => array_filter([
                    'action' => 'intake', 'sn_grade_pct' => $snPct, 'source' => $source, 'timestamp' => $ts,
                    'declared_weight' => (float) $fresh->initial_weight, 'previous_weight' => $previous,
                    'user_note' => $note !== '' ? $note : null,
                    'partner_signatory' => $signatory !== '' ? $signatory : null,
                ], fn ($v) => $v !== null),
                'recorded_by'      => $userId,
                'recorded_at'      => now(),
            ]);

            $fresh->update(array_filter([
                'current_weight'  => $weight,
                'sn_grade_pct'    => $snPct,
                'container_type'  => $fresh->container_type ?? 'drum_besi',
                'mass_loss_pct'   => $fresh->initial_weight > 0 ? round(($fresh->initial_weight - $weight) / $fresh->initial_weight * 100, 2) : 0,
                'has_discrepancy' => $alert || $fresh->has_discrepancy,
                'status'          => ProcessFlow::STATUS_AFTER_RECEIVE,
                // Validator mitra yang menandatangani saat penerimaan (bukti ada di dokumen fisik).
                'metadata'        => $signatory !== '' ? array_merge($fresh->metadata ?? [], ['partner_signatory' => $signatory]) : null,
                'updated_by'      => $userId,
            ], fn ($v) => $v !== null));

            // Isi drum langsung dituang ke Wet: terima di wet_process → masuk himpunan Wet, tag dilepas.
            $this->transitions->receive($fresh, 'wet_process', [
                'user_id' => $userId,
                'source'  => $source,
                'notes'   => "Dituang ke Wet Process setelah timbang & kadar Sn — {$ts}",
            ]);

            return $fresh->fresh();
        });
    }
}
