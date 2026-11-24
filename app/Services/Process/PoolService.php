<?php

namespace App\Services\Process;

use App\Models\Batch;
use App\Models\BatchLineage;
use App\Models\BatchPool;
use App\Models\BatchPoolInput;
use App\Models\BatchTransformation;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Models\ProductCode;
use App\Models\RfidTagEvent;
use App\Models\User;
use App\Services\Batch\BatchCodeService;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\RfidService;
use App\Support\ProcessFlow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Penghimpunan batch di Wet/Dry (docs/superpowers/specs/2026-11-24-pooling-retag-design.md).
 *
 *  join()             : batch yang diterima di Wet/Dry dilepas tag-nya dan masuk himpunan `collecting`.
 *  createOutputDrum() : UID baru di keluaran Wet/Dry → drum output (status awaiting_weight).
 *                       Wet: seluruh himpunan langsung digabung & ditutup. Dry: himpunan → outputting (maks 3 drum).
 *  recordDrumWeight() : berat (dan produk, Dry) drum. Wet: drum siap dikirim ke Dry.
 *  closeDryPool()     : "Selesai himpunan" Dry: silsilah & neraca massa, drum siap dikirim ke Gudang.
 *  cancelDrum()       : batalkan drum yang belum ditimbang; himpunan dibuka kembali.
 *
 * Kegagalan aturan dilempar sebagai \DomainException berisi pesan operator (Indonesia), kecuali
 * createOutputDrum() yang mengembalikan hasil berkode agar gate RFID bisa mencatatnya.
 */
class PoolService
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(
        private readonly BatchCodeService $codes,
        private readonly RfidService $rfid,
    ) {}

    public static function isPoolStage(string $stage): bool
    {
        return in_array($stage, BatchPool::STAGES, true);
    }

    // ─── Masuk himpunan ───────────────────────────────────────────────────

    /** Himpunan `collecting` tahap ini (dibuat bila belum ada). Wajib dipanggil di dalam transaksi. */
    public function collecting(int $companyId, string $stage): BatchPool
    {
        // Kunci per (company, stage) agar dua penerimaan serentak tidak membuat dua himpunan.
        DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [$companyId, crc32("pool:{$stage}") & 0x7fffffff]);

        return BatchPool::where('company_id', $companyId)->where('stage', $stage)
            ->where('status', BatchPool::STATUS_COLLECTING)->lockForUpdate()->first()
            ?? BatchPool::create([
                'company_id' => $companyId, 'stage' => $stage,
                'status' => BatchPool::STATUS_COLLECTING, 'opened_at' => now(),
            ]);
    }

    /** Masukkan batch (sudah berada di tahap Wet/Dry) ke himpunan; tag-nya dilepas. Wajib di dalam transaksi. */
    public function join(Batch $batch, ?int $userId): BatchPool
    {
        $pool = $this->collecting($batch->company_id, $batch->process_stage);
        $uid  = $batch->rfid_tag;

        BatchPoolInput::create([
            'pool_id'           => $pool->id,
            'batch_id'          => $batch->id,
            'weight_at_join'    => $batch->current_weight,
            'released_rfid_tag' => $uid,
            'joined_at'         => now(),
        ]);

        if ($uid) {
            $batch->update(['rfid_tag' => null]);
            RfidTagEvent::create([
                'company_id'   => $batch->company_id,
                'batch_id'     => $batch->id,
                'uid'          => $uid,
                'event'        => 'released',
                'reason'       => RfidTagEvent::REASON_POOLED,
                'note'         => "Masuk himpunan {$batch->process_stage} #{$pool->id}",
                'performed_by' => $userId,
            ]);
            DB::afterCommit(fn () => app(RfidScanService::class)->forgetUid($batch->company_id, $uid));
        }

        return $pool;
    }

    /** Himpunan terbuka yang memuat batch ini sebagai input, bila ada. */
    public function openPoolOf(Batch $batch): ?BatchPool
    {
        $input = BatchPoolInput::where('batch_id', $batch->id)->first();

        return $input && $input->pool && $input->pool->isOpen() ? $input->pool : null;
    }

    // ─── Drum keluar ──────────────────────────────────────────────────────

    /**
     * UID pernah dipakai di luar drum output (stiker karung/drum lama, registrasi biasa, scan lama).
     * Tag seperti itu hanya boleh dipakai ulang untuk batch baru di awal alur.
     */
    public function uidHasHistory(int $companyId, string $uid): bool
    {
        // Hanya drum yang DIBATALKAN yang dikecualikan (UID-nya belum pernah benar-benar dipakai di alur).
        $drumIds = fn ($q) => $q->select('batch_id')->from('rfid_tag_events')->where('company_id', $companyId)
            ->where('uid', $uid)->where('reason', RfidTagEvent::REASON_DRUM_CANCELLED)->whereNotNull('batch_id');

        return BatchPoolInput::where('released_rfid_tag', $uid)
                ->whereHas('pool', fn ($q) => $q->where('company_id', $companyId))->exists()
            || RfidTagEvent::where('company_id', $companyId)->where('uid', $uid)
                ->where(fn ($q) => $q->whereNull('batch_id')->orWhereNotIn('batch_id', $drumIds))->exists()
            || Checkpoint::where('company_id', $companyId)->where('rfid_scan_code', $uid)
                ->where(fn ($q) => $q->whereNull('batch_id')->orWhereNotIn('batch_id', $drumIds))->exists();
    }

    /**
     * @return array{ok:bool, code:string, message:string, batch?:Batch, pool?:BatchPool}
     *   code: pool_output_created | pool_empty | pool_full | tag_recycled | tag_in_use | invalid_stage | invalid_uid
     */
    public function createOutputDrum(int $companyId, string $stage, string $rawUid, ?int $userId, string $source = 'web'): array
    {
        if (! self::isPoolStage($stage)) {
            return $this->fail('invalid_stage', 'Drum keluar hanya untuk Wet Process dan Dry Process.');
        }
        $uid = $this->rfid->sanitizeUid($rawUid);
        if ($uid === '') {
            return $this->fail('invalid_uid', 'UID tag kosong atau tidak valid.');
        }

        return DB::transaction(function () use ($companyId, $stage, $uid, $userId, $source) {
            DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [$companyId, crc32("pool:{$stage}") & 0x7fffffff]);

            if ($owner = Batch::withTrashed()->where('rfid_tag', $uid)->first()) {
                // Pesan generik: pemilik bisa perusahaan lain (jangan bocorkan kode batch lintas tenant).
                return $this->fail('tag_in_use', $owner->company_id === $companyId
                    ? "Tag {$uid} masih terpasang pada batch {$owner->batch_code}."
                    : "Tag {$uid} sudah dipakai. Gunakan tag lain.");
            }
            if ($this->uidHasHistory($companyId, $uid)) {
                return $this->fail('tag_recycled', 'Tag bekas — pakai untuk batch baru di Receiving.');
            }

            $pool = $stage === 'dry_process'
                ? $this->dryOutputPool($companyId)
                : BatchPool::where('company_id', $companyId)->where('stage', $stage)
                    ->where('status', BatchPool::STATUS_COLLECTING)->lockForUpdate()->first();

            $inputs = $pool ? $pool->inputs()->with('batch.productCode')->get() : collect();
            if (! $pool || $inputs->isEmpty()) {
                $label = ProcessFlow::get($stage)['label'];

                return $this->fail('pool_empty', "Tidak ada batch yang sedang dihimpun di {$label}.");
            }
            if ($stage === 'dry_process' && $pool->outputs()->count() >= BatchPool::MAX_DRY_DRUMS) {
                return $this->fail('pool_full', 'Himpunan sudah punya 3 drum. Tekan "Selesai himpunan" dulu.');
            }

            $drum = $this->makeDrum($pool, $inputs->first()->batch, $uid, $userId, $source);

            if ($stage === 'wet_process') {
                $this->closePool($pool, $inputs, collect([$drum]), $userId, estimate: false);
            } elseif ($pool->status === BatchPool::STATUS_COLLECTING) {
                $pool->update(['status' => BatchPool::STATUS_OUTPUTTING]);
            }

            Log::info("POOL drum keluar: {$drum->batch_code} UID {$uid} tahap {$stage} himpunan #{$pool->id} ({$inputs->count()} input) via {$source}");

            return [
                'ok' => true, 'code' => 'pool_output_created',
                'message' => "Drum {$drum->batch_code} dibuat dari {$inputs->count()} batch. Timbang drum ini.",
                'batch' => $drum, 'pool' => $pool->fresh(),
            ];
        });
    }

    /** Dry: himpunan `outputting` bila ada; bila tidak, himpunan `collecting` yang mulai mengeluarkan drum. */
    private function dryOutputPool(int $companyId): ?BatchPool
    {
        $base = fn () => BatchPool::where('company_id', $companyId)->where('stage', 'dry_process');

        return $base()->where('status', BatchPool::STATUS_OUTPUTTING)->lockForUpdate()->first()
            ?? $base()->where('status', BatchPool::STATUS_COLLECTING)->lockForUpdate()->first();
    }

    private function makeDrum(BatchPool $pool, Batch $sample, string $uid, ?int $userId, string $source): Batch
    {
        $cfg      = ProcessFlow::get($pool->stage);
        $company  = Company::findOrFail($pool->company_id);
        $location = $this->drumLocation($pool->company_id, $pool->stage, $sample);
        $product  = $sample->productCode;

        $drum = Batch::create([
            'company_id'          => $pool->company_id,
            'batch_code'          => $this->codes->generate($company, $location, $pool->stage, $product),
            'rfid_tag'            => $uid,
            'container_type'      => $pool->stage === 'wet_process' ? 'container' : 'product_drum',
            'product_code_id'     => $product->id,
            'pool_id'             => $pool->id,
            'initial_weight'      => 0,
            'current_weight'      => 0,
            'process_stage'       => $pool->stage,
            'status'              => ProcessFlow::STATUS_AWAITING_WEIGHT,
            'current_location_id' => $location?->id,
            'metadata'            => ['pool_output' => true],
            'created_by'          => $userId ?? $sample->created_by,
        ]);

        RfidTagEvent::create([
            'company_id' => $pool->company_id, 'batch_id' => $drum->id, 'uid' => $uid,
            'event' => 'registered', 'note' => "Drum keluar {$cfg['label']} (himpunan #{$pool->id})",
            'performed_by' => $userId,
        ]);

        $ts = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';
        Checkpoint::create([
            'batch_id'         => $drum->id,
            'location_id'      => $drum->current_location_id,
            'checkpoint_stage' => $cfg['forward_cp'],
            'is_verified'      => true,
            'notes'            => "Drum keluar {$cfg['label']} dari himpunan #{$pool->id} (tag {$uid}) — {$ts}",
            'checkpoint_data'  => ['action' => 'pool_output', 'pool_id' => $pool->id, 'source' => $source, 'uid' => $uid],
            'recorded_by'      => $userId,
            'recorded_at'      => now(),
        ]);

        return $drum;
    }

    // ─── Timbang & tutup ──────────────────────────────────────────────────

    /** @throws \DomainException */
    public function recordDrumWeight(Batch $drum, float $weight, ?int $productCodeId, int $userId): Batch
    {
        return DB::transaction(function () use ($drum, $weight, $productCodeId, $userId) {
            $drum = Batch::whereKey($drum->id)->lockForUpdate()->firstOrFail();
            $pool = $drum->pool;

            if (! $pool || $drum->status !== ProcessFlow::STATUS_AWAITING_WEIGHT) {
                throw new \DomainException("Batch {$drum->batch_code} bukan drum yang menunggu timbang.");
            }
            if ($weight <= 0) {
                throw new \DomainException('Berat drum harus lebih dari 0.');
            }

            $update = ['initial_weight' => $weight, 'current_weight' => $weight, 'updated_by' => $userId];

            if ($pool->stage === 'dry_process') {
                $product = $productCodeId ? ProductCode::find($productCodeId) : null;
                if (! $product || ! in_array($product->code, ['MON', 'ZIR', 'ILM'], true)) {
                    throw new \DomainException('Pilih produk drum: MON, ZIR, atau ILM.');
                }
                $taken = Batch::where('pool_id', $pool->id)->where('id', '!=', $drum->id)
                    ->where('product_code_id', $product->id)->where('initial_weight', '>', 0)->exists();
                if ($taken) {
                    throw new \DomainException("Himpunan ini sudah punya drum {$product->code}.");
                }
                if ($drum->product_code_id !== $product->id) {
                    $company  = Company::findOrFail($drum->company_id);
                    $location = $this->drumLocation($drum->company_id, $pool->stage, $drum);
                    $update  += ['product_code_id' => $product->id, 'batch_code' => $this->codes->generate($company, $location, $pool->stage, $product)];
                }
            }

            $drum->update($update);

            Checkpoint::create([
                'batch_id'         => $drum->id,
                'location_id'      => $drum->current_location_id,
                'checkpoint_stage' => ProcessFlow::get($pool->stage)['forward_cp'],
                'weight_recorded'  => $weight,
                'is_verified'      => true,
                'notes'            => "Drum ditimbang: {$weight} kg",
                'checkpoint_data'  => ['action' => 'pool_drum_weighed', 'pool_id' => $pool->id],
                'recorded_by'      => $userId,
                'recorded_at'      => now(),
            ]);

            if ($pool->stage === 'wet_process') {
                $drum->update(['status' => ProcessFlow::STATUS_AFTER_SEND]);
                $this->applyMassBalance($pool->fresh(), collect([$drum->fresh()]));
            }

            return $drum->fresh();
        });
    }

    /** "Selesai himpunan" Dry. @throws \DomainException */
    public function closeDryPool(BatchPool $pool, int $userId): BatchPool
    {
        return DB::transaction(function () use ($pool, $userId) {
            $pool = BatchPool::whereKey($pool->id)->lockForUpdate()->firstOrFail();

            if ($pool->stage !== 'dry_process' || $pool->status !== BatchPool::STATUS_OUTPUTTING) {
                throw new \DomainException('Himpunan ini tidak sedang mengeluarkan drum.');
            }
            $drums = $pool->outputs()->get();
            if ($drums->isEmpty()) {
                throw new \DomainException('Belum ada drum yang keluar dari himpunan ini.');
            }
            if ($drums->contains(fn ($d) => $d->initial_weight <= 0)) {
                throw new \DomainException('Masih ada drum yang belum ditimbang.');
            }

            $this->closePool($pool, $pool->inputs()->with('batch')->get(), $drums, $userId, estimate: true);
            Batch::whereIn('id', $drums->pluck('id'))->update(['status' => ProcessFlow::STATUS_AFTER_SEND, 'updated_by' => $userId]);
            $this->applyMassBalance($pool->fresh(), $drums->map->fresh());

            return $pool->fresh();
        });
    }

    /** Gabungkan: input → pooled, silsilah input × output, transformasi `pooling`, himpunan ditutup. */
    private function closePool(BatchPool $pool, $inputs, $drums, ?int $userId, bool $estimate): void
    {
        $inputIds    = $inputs->pluck('batch_id')->all();
        $totalIn     = (float) $inputs->sum(fn ($i) => (float) ($i->weight_at_join ?? $i->batch->current_weight));
        $totalOut    = (float) $drums->sum('initial_weight');

        Batch::whereIn('id', $inputIds)->update(['status' => ProcessFlow::STATUS_POOLED, 'updated_by' => $userId]);

        foreach ($inputs as $in) {
            $w = (float) ($in->weight_at_join ?? $in->batch->current_weight);
            foreach ($drums as $drum) {
                BatchLineage::create([
                    'parent_batch_id'    => $in->batch_id,
                    'child_batch_id'     => $drum->id,
                    'pool_id'            => $pool->id,
                    'contributed_weight' => $estimate && $totalOut > 0 ? round($w * $drum->initial_weight / $totalOut, 3) : $w,
                    'weight_is_estimate' => $estimate,
                ]);
            }
        }

        $transformation = BatchTransformation::create([
            'input_batch_id'         => $inputIds[0],
            'output_batch_ids'       => $drums->pluck('id')->values()->all(),
            'transformation_type'    => 'pooling',
            'location_id'            => $drums->first()->current_location_id,
            'transformation_details' => [
                'pool_id' => $pool->id, 'stage' => $pool->stage,
                'input_batch_ids' => $inputIds, 'total_input_weight' => $totalIn,
            ],
            'notes'        => "Penghimpunan {$pool->stage}: " . count($inputIds) . ' batch → ' . $drums->count() . ' drum',
            'performed_by' => $userId ?? $drums->first()->created_by,
            'transformed_at' => now(),
        ]);

        $pool->update([
            'status' => BatchPool::STATUS_CLOSED, 'closed_at' => now(), 'closed_by' => $userId,
            'total_input_weight' => $totalIn, 'transformation_id' => $transformation->id,
        ]);
    }

    private function applyMassBalance(BatchPool $pool, $drums): void
    {
        $in  = (float) $pool->total_input_weight;
        $out = (float) $drums->sum('initial_weight');
        $pct = $in > 0 ? round(($in - $out) / $in * 100, 2) : 0.0;

        $threshold = MassBalanceThreshold::where('company_id', $pool->company_id)->where('process_stage', $pool->stage)->first();
        $alert     = $threshold ? $pct >= $threshold->warning_threshold_pct : false;

        $pool->update(['total_output_weight' => $out, 'loss_pct' => $pct, 'has_discrepancy' => $alert]);
        Batch::whereIn('id', $drums->pluck('id'))->update(['mass_loss_pct' => $pct, 'has_discrepancy' => $alert]);
        $pool->transformation?->update([
            'transformation_details' => array_merge($pool->transformation->transformation_details ?? [], [
                'total_output_weight' => $out, 'loss_pct' => $pct, 'has_discrepancy' => $alert,
            ]),
        ]);
    }

    // ─── Batalkan drum ────────────────────────────────────────────────────

    /** @throws \DomainException */
    public function cancelDrum(Batch $drum, int $userId, string $reason): void
    {
        DB::transaction(function () use ($drum, $userId, $reason) {
            $drum = Batch::whereKey($drum->id)->lockForUpdate()->firstOrFail();
            if ($drum->pool_id) {
                $stage = BatchPool::whereKey($drum->pool_id)->value('stage');
                DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [$drum->company_id, crc32("pool:{$stage}") & 0x7fffffff]);
            }
            $pool = $drum->pool_id ? BatchPool::whereKey($drum->pool_id)->lockForUpdate()->first() : null;

            if (! $pool || $drum->status !== ProcessFlow::STATUS_AWAITING_WEIGHT || $drum->initial_weight > 0) {
                throw new \DomainException('Hanya drum yang belum ditimbang yang bisa dibatalkan.');
            }
            if ($pool->stage === 'dry_process' && $pool->status === BatchPool::STATUS_CLOSED) {
                throw new \DomainException('Himpunan sudah selesai; drum tidak bisa dibatalkan.');
            }

            $note = mb_substr("Drum dibatalkan: {$reason}", 0, 255);

            if ($uid = $drum->rfid_tag) {
                $drum->update(['rfid_tag' => null]);
                RfidTagEvent::create([
                    'company_id' => $drum->company_id, 'batch_id' => $drum->id, 'uid' => $uid,
                    'event' => 'released', 'reason' => RfidTagEvent::REASON_DRUM_CANCELLED,
                    'note' => $note, 'performed_by' => $userId,
                ]);
                DB::afterCommit(fn () => app(RfidScanService::class)->forgetUid($drum->company_id, $uid));
            }

            $void = ['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $note];
            BatchLineage::where('child_batch_id', $drum->id)->whereNull('voided_at')->update($void);

            if ($pool->stage === 'wet_process') {
                $pool->transformation?->update($void);
                $inputIds = $pool->inputs()->pluck('batch_id');
                Batch::whereIn('id', $inputIds)->where('status', ProcessFlow::STATUS_POOLED)
                    ->update(['status' => ProcessFlow::STATUS_AFTER_RECEIVE, 'updated_by' => $userId]);

                // Himpunan dibuka kembali; bila sementara itu sudah ada himpunan collecting baru, gabungkan inputnya ke sini.
                $newer = BatchPool::where('company_id', $pool->company_id)->where('stage', $pool->stage)
                    ->where('status', BatchPool::STATUS_COLLECTING)->where('id', '!=', $pool->id)->first();
                if ($newer) {
                    BatchPoolInput::where('pool_id', $newer->id)->update(['pool_id' => $pool->id]);
                    $newer->delete();
                }
                $pool->update([
                    'status' => BatchPool::STATUS_COLLECTING, 'closed_at' => null, 'closed_by' => null,
                    'total_input_weight' => null, 'total_output_weight' => null, 'loss_pct' => null,
                    'has_discrepancy' => false, 'transformation_id' => null,
                ]);
            } elseif ($pool->outputs()->where('id', '!=', $drum->id)->doesntExist()) {
                $newer = BatchPool::where('company_id', $pool->company_id)->where('stage', $pool->stage)
                    ->where('status', BatchPool::STATUS_COLLECTING)->first();
                if ($newer) {
                    BatchPoolInput::where('pool_id', $newer->id)->update(['pool_id' => $pool->id]);
                    $newer->delete();
                }
                $pool->update(['status' => BatchPool::STATUS_COLLECTING]);
            }

            $drum->update(['updated_by' => $userId]);
            $drum->delete();

            Log::info("POOL drum dibatalkan: {$drum->batch_code} himpunan #{$pool->id} oleh user_id={$userId}: {$reason}");
        });
    }

    // ─── Bantu ────────────────────────────────────────────────────────────

    /** Lokasi untuk kode batch drum: lokasi tahap, lalu lokasi batch, lalu lokasi aktif mana pun milik perusahaan. */
    private function drumLocation(int $companyId, string $stage, Batch $sample): Location
    {
        return $this->stageLocation($companyId, $stage)
            ?? $sample->currentLocation
            ?? Location::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function stageLocation(int $companyId, string $stage): ?Location
    {
        return Location::where('company_id', $companyId)
            ->where('location_type', ProcessFlow::get($stage)['location_type'])
            ->where('is_active', true)->first();
    }

    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
