<?php

namespace App\Services\Process;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\MassBalanceThreshold;
use App\Support\LossPct;
use App\Support\ProcessFlow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi berat ("timbang ulang") — mencocokkan berat batch di sistem dengan berat sebenarnya di timbangan.
 *
 * Batch sudah dibuat dan dicatat (berat deklarasi mitra/Lab Kimia) sebelum tiba di Receiving Muntok. Setelah tag
 * terdaftar, operator menimbang cepat lalu memasukkan berat nyata lewat satu popup. Prinsipnya:
 *  - `initial_weight` (berat deklarasi) TIDAK diubah → selisih deklarasi vs timbangan nyata tetap terlihat;
 *  - `current_weight` diganti berat nyata → seluruh hitungan berikutnya (kadar Sn, himpunan Wet, neraca massa) memakai berat nyata;
 *  - setiap verifikasi menulis satu checkpoint `receiving_verify` (berat sebelum/sesudah, selisih, catatan, penanda tangan mitra);
 *  - selisih melewati ambang peringatan (dua arah) menandai batch `has_discrepancy` untuk ditinjau — batch TIDAK ditahan.
 *
 * Hanya untuk batch yang belum dihimpun: Lab Kimia (aktif/dalam perjalanan) dan Receiving (menunggu timbang/diproses).
 */
class WeightVerificationService
{
    private const TZ = 'Asia/Jakarta';

    private const MAX_WEIGHT = 999999.99;

    /** Peran yang boleh memverifikasi berat (sama dengan pembuat batch + operator Receiving & Wet). */
    public const ROLES = ['company_admin', 'operator_lab_station', 'operator_wet'];

    /** @var array<string, list<string>> tahap => status yang boleh diverifikasi */
    private const ALLOWED = [
        'lab_kimia' => ['active', ProcessFlow::STATUS_AFTER_SEND],
        'receiving' => [ProcessFlow::STATUS_AWAITING_INTAKE, ProcessFlow::STATUS_AFTER_RECEIVE],
    ];

    /** Apakah batch ini boleh diverifikasi beratnya (dipakai UI untuk menampilkan tombol). */
    public static function canVerify(Batch $batch): bool
    {
        return in_array($batch->status, self::ALLOWED[$batch->process_stage] ?? [], true);
    }

    /**
     * @param  array{note?:?string, partner_signatory?:?string, source?:string}  $opts
     * @return array{batch:Batch, previous:float, weight:float, diff:float, diff_pct:float, alert:bool, changed:bool}
     *
     * @throws \DomainException
     */
    public function verify(Batch $batch, float $weight, ?int $userId, array $opts = []): array
    {
        if ($weight <= 0 || $weight > self::MAX_WEIGHT) {
            throw new \DomainException('Berat harus lebih dari 0 dan maksimal 999.999,99 kg.');
        }
        $weight    = round($weight, 3);
        $note      = isset($opts['note']) ? trim((string) $opts['note']) : '';
        $signatory = isset($opts['partner_signatory']) ? trim((string) $opts['partner_signatory']) : '';
        $source    = $opts['source'] ?? 'web';

        return DB::transaction(function () use ($batch, $weight, $note, $signatory, $source, $userId) {
            $fresh = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! self::canVerify($fresh)) {
                throw new \DomainException("Berat batch {$fresh->batch_code} tidak dapat diverifikasi pada tahap/status ini.");
            }

            // ─── Selisih terhadap berat tercatat saat ini ─────────────────
            $previous = (float) $fresh->current_weight;
            $diff     = round($weight - $previous, 3);                       // + = lebih berat dari catatan
            $diffPct  = $previous > 0 ? round($diff / $previous * 100, 2) : 0.0;

            $threshold = MassBalanceThreshold::where('company_id', $fresh->company_id)
                ->whereIn('process_stage', [$fresh->process_stage, 'receiving'])
                ->orderByRaw('CASE WHEN process_stage = ? THEN 0 ELSE 1 END', [$fresh->process_stage])
                ->first();
            $alert = $threshold && abs($diffPct) >= (float) $threshold->warning_threshold_pct;

            $ts      = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';
            $changed = abs($diff) > 0.0005;
            $summary = $changed
                ? sprintf('Berat diverifikasi ulang: %s kg → %s kg (selisih %s%s kg, %s%s%%)',
                    self::fmt($previous), self::fmt($weight), $diff > 0 ? '+' : '', self::fmt($diff), $diffPct > 0 ? '+' : '', self::fmt($diffPct))
                : sprintf('Berat diverifikasi: %s kg sesuai catatan', self::fmt($weight));

            Checkpoint::create([
                'batch_id'         => $fresh->id,
                'location_id'      => $fresh->current_location_id,
                'checkpoint_stage' => 'receiving_verify',
                'weight_recorded'  => $weight,
                'weight_previous'  => $previous,
                'weight_loss'      => round($previous - $weight, 3),
                'weight_loss_pct'  => LossPct::clamp(-$diffPct),
                'has_discrepancy'  => $alert,
                'is_verified'      => true,
                'notes'            => $summary . ($note !== '' ? " — {$note}" : '') . " — {$ts}",
                'checkpoint_data'  => array_filter([
                    'action'            => 'weight_verify',
                    'declared_weight'   => (float) $fresh->initial_weight,
                    'previous_weight'   => $previous,
                    'verified_weight'   => $weight,
                    'diff_kg'           => $diff,
                    'diff_pct'          => $diffPct,
                    'user_note'         => $note !== '' ? $note : null,
                    'partner_signatory' => $signatory !== '' ? $signatory : null,
                    'source'            => $source,
                    'timestamp_wib'     => $ts,
                ], fn ($v) => $v !== null),
                'recorded_by'      => $userId,
                'recorded_at'      => now(),
            ]);

            // ─── Perbarui batch: berat nyata menggantikan berat tercatat ─────
            $meta = $fresh->metadata ?? [];
            if ($signatory !== '') {
                $meta['partner_signatory'] = $signatory;   // validator mitra terakhir (bukti tanda tangan di dokumen fisik)
            }
            $meta['weight_verified_at'] = now()->toIso8601String();

            $fresh->update([
                'current_weight'  => $weight,
                'mass_loss_pct'   => $fresh->initial_weight > 0
                    ? LossPct::clamp(($fresh->initial_weight - $weight) / $fresh->initial_weight * 100)
                    : 0,
                'has_discrepancy' => $alert || $fresh->has_discrepancy,
                'metadata'        => $meta,
                'updated_by'      => $userId,
            ]);

            return [
                'batch' => $fresh->fresh(), 'previous' => $previous, 'weight' => $weight,
                'diff' => $diff, 'diff_pct' => $diffPct, 'alert' => $alert, 'changed' => $changed,
            ];
        });
    }

    /** Angka gaya Indonesia tanpa nol berlebih: 1.234,5 */
    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
    }
}
