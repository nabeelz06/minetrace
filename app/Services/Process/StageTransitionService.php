<?php

namespace App\Services\Process;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Support\ProcessFlow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * StageTransitionService — SATU-satunya tempat serah-terima batch antar tahap
 * (dipakai web ProcessController dan strategi RFID), sehingga aturan tidak bisa menyimpang.
 *
 * Model (skenario b):
 *   dispatch(): batch TETAP di tahap asal, status → in_transit, checkpoint keluar dicatat.
 *   receive() : batch PINDAH ke tahap penerima (status in_process; di warehouse in_warehouse).
 * Guard divalidasi ULANG di dalam transaksi dengan kunci baris (lockForUpdate) sehingga dua klik/scan
 * serentak tidak menggandakan transisi.
 *
 * Kegagalan guard dilempar sebagai \DomainException berisi pesan operator (Indonesia).
 */
class StageTransitionService
{
    private const TZ = 'Asia/Jakarta';

    // ─── Guard ────────────────────────────────────────────────────────────

    /** @return string|null pesan galat bila batch TIDAK boleh diterima di $stage */
    public function receiveError(Batch $batch, string $stage): ?string
    {
        $cfg = ProcessFlow::get($stage);
        if (! $cfg) {
            return 'Tahap tidak dikenal.';
        }
        if (empty($cfg['receive_from'])) {
            return "Batch tidak dapat diterima di {$cfg['label']} (tahap awal; batch dibuat di sini).";
        }
        if (in_array($batch->status, ProcessFlow::CLOSED_STATUSES, true)) {
            return "Batch {$batch->batch_code} berstatus {$batch->status} dan tidak ikut alur tahap.";
        }

        $rules = $cfg['receive_from'];
        foreach ($rules as $rule) {
            if ($batch->process_stage !== $rule['stage']) {
                continue;
            }
            if (isset($rule['require_status']) && $batch->status !== $rule['require_status']) {
                $from = ProcessFlow::get($rule['stage'])['label'];
                return "Batch {$batch->batch_code} belum dalam status pengiriman dari {$from}. " .
                       "Stage: {$batch->process_stage}, Status: {$batch->status}.";
            }
            if (isset($rule['require_product'])) {
                $batch->loadMissing('productCode');
                if ($batch->productCode?->code !== $rule['require_product']) {
                    return $rule['require_product'] === 'MON'
                        ? "Hanya batch Monasit (MON) yang dapat diterima di {$cfg['label']}."
                        : "Hanya produk {$rule['require_product']} yang dapat diterima di {$cfg['label']}.";
                }
            }

            return null;
        }

        $expected = implode(' / ', array_map(fn ($r) => $r['stage'], $rules));

        return "Batch {$batch->batch_code} bukan dari stage {$expected}. Stage saat ini: {$batch->process_stage}.";
    }

    /** @return string|null pesan galat bila batch TIDAK boleh dikirim dari $stage */
    public function dispatchError(Batch $batch, string $stage, string $source = 'web'): ?string
    {
        $cfg = ProcessFlow::get($stage);
        if (! $cfg) {
            return 'Tahap tidak dikenal.';
        }
        if ($batch->process_stage !== $stage) {
            return "Batch {$batch->batch_code} tidak berada di stage {$stage}. Stage: {$batch->process_stage}.";
        }
        if (in_array($batch->status, ProcessFlow::CLOSED_STATUSES, true)) {
            return "Batch {$batch->batch_code} berstatus {$batch->status} dan tidak dapat dikirim.";
        }
        if ($batch->status === ProcessFlow::STATUS_AFTER_SEND) {
            return "Batch {$batch->batch_code} sudah dalam pengiriman. Menunggu penerimaan di tahap berikutnya.";
        }
        if (! $cfg['next_stage']) {
            return "Stage {$stage} tidak memiliki next stage (gunakan alur pengiriman khusus).";
        }
        if ($source === 'rfid' && $stage === 'dry_process') {
            return 'Dry Process memerlukan transformasi batch. Gunakan web panel.';
        }

        return null;
    }

    // ─── Aksi ─────────────────────────────────────────────────────────────

    /**
     * Terima batch di $stage.
     * @param  array $o {user_id?, location_id?, notes?, source?: 'web'|'rfid'}
     * @throws \DomainException
     */
    public function receive(Batch $batch, string $stage, array $o = []): Checkpoint
    {
        return DB::transaction(function () use ($batch, $stage, $o) {
            $fresh = Batch::with('productCode')->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($error = $this->receiveError($fresh, $stage)) {
                throw new \DomainException($error);
            }

            $cfg    = ProcessFlow::get($stage);
            $source = $o['source'] ?? 'web';
            $from   = $fresh->process_stage;

            $locationId = $o['location_id'] ?? Location::where('company_id', $fresh->company_id)
                ->where('location_type', $cfg['location_type'])
                ->where('is_active', true)->value('id') ?? $fresh->current_location_id;

            $fresh->update(array_filter([
                'process_stage'       => $stage,
                'status'              => $stage === 'warehouse' ? ProcessFlow::STATUS_WAREHOUSE : ProcessFlow::STATUS_AFTER_RECEIVE,
                'current_location_id' => $locationId,
                'updated_by'          => $o['user_id'] ?? null,
            ], fn ($v) => $v !== null));

            $ts = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

            return Checkpoint::create([
                'batch_id'         => $fresh->id,
                'location_id'      => $locationId,
                'checkpoint_stage' => $cfg['receive_cp'] ?? $stage,
                'weight_recorded'  => $fresh->current_weight,
                'weight_previous'  => $fresh->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => $o['notes'] ?? ($source === 'rfid'
                    ? "✅ RFID Auto-Receive: batch diterima otomatis via RFID Scan IN — {$ts}"
                    : "Batch diterima di {$cfg['label']} — {$ts}"),
                'checkpoint_data'  => ($source === 'rfid' ? ['action' => 'rfid_auto_receive', 'rfid_trigger' => true] : ['action' => 'receive']) + [
                    'from_stage' => $from,
                    'to_stage'   => $stage,
                    'timestamp'  => $ts,
                ],
                'recorded_by' => $o['user_id'] ?? null,
                'recorded_at' => now(),
            ]);
        });
    }

    /**
     * Kirim batch dari $stage (tahap asal TIDAK berubah; status → in_transit).
     * @param  array $o {user_id?, weight?, notes?, is_verified?, location_id?, source?: 'web'|'rfid'}
     *               weight null = tanpa penimbangan ulang (RFID): berat/susut batch tidak disentuh.
     * @return array{checkpoint:Checkpoint,has_discrepancy:bool,loss_pct:float,to_stage:string}
     * @throws \DomainException
     */
    public function dispatch(Batch $batch, string $stage, array $o = []): array
    {
        return DB::transaction(function () use ($batch, $stage, $o) {
            $fresh  = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $source = $o['source'] ?? 'web';

            if ($error = $this->dispatchError($fresh, $stage, $source)) {
                throw new \DomainException($error);
            }

            $cfg       = ProcessFlow::get($stage);
            $next      = $cfg['next_stage'];
            $nextLabel = ProcessFlow::get($next)['label'];
            $previous  = (float) $fresh->current_weight;
            $weighed   = array_key_exists('weight', $o) && $o['weight'] !== null;
            $newWeight = $weighed ? (float) $o['weight'] : $previous;
            $loss      = $previous - $newWeight;
            $lossPct   = $previous > 0 ? round(($loss / $previous) * 100, 2) : 0.0;

            $discrepancy = false;
            if ($weighed) {
                $threshold   = MassBalanceThreshold::where('company_id', $fresh->company_id)->where('process_stage', $stage)->first();
                $discrepancy = $threshold ? $lossPct >= $threshold->warning_threshold_pct : false;
            }

            $ts = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

            $checkpoint = Checkpoint::create([
                'batch_id'         => $fresh->id,
                'location_id'      => $fresh->current_location_id,
                'checkpoint_stage' => $cfg['forward_cp'],
                'weight_recorded'  => $newWeight,
                'weight_previous'  => $previous,
                'weight_loss'      => $loss,
                'weight_loss_pct'  => $lossPct,
                'has_discrepancy'  => $discrepancy,
                'is_verified'      => $o['is_verified'] ?? ($source === 'rfid'),
                'notes'            => $o['notes'] ?? ($source === 'rfid'
                    ? "✅ RFID Auto-Dispatch: batch dikirim otomatis via RFID Scan OUT — {$ts}"
                    : "Dikirim ke {$nextLabel}"),
                'checkpoint_data'  => ($source === 'rfid' ? ['action' => 'rfid_auto_dispatch', 'rfid_trigger' => true] : ['action' => 'forward']) + [
                    'from_stage' => $stage,
                    'to_stage'   => $next,
                    'timestamp_wib' => $ts,
                ],
                'recorded_by' => $o['user_id'] ?? null,
                'recorded_at' => now(),
            ]);

            $update = ['status' => ProcessFlow::STATUS_AFTER_SEND];
            if ($weighed) {
                $update += [
                    'current_weight'  => $newWeight,
                    'mass_loss_pct'   => $fresh->initial_weight > 0
                        ? round((($fresh->initial_weight - $newWeight) / $fresh->initial_weight) * 100, 2) : 0,
                    'has_discrepancy' => $discrepancy,
                ];
            }
            if (isset($o['user_id'])) {
                $update['updated_by'] = $o['user_id'];
            }
            // process_stage & current_location_id TIDAK berubah sampai batch diterima di tahap tujuan.
            $fresh->update($update);

            return ['checkpoint' => $checkpoint, 'has_discrepancy' => $discrepancy, 'loss_pct' => $lossPct, 'to_stage' => $next];
        });
    }
}
