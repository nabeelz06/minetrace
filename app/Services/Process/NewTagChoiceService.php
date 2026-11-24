<?php

namespace App\Services\Process;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Models\RfidTagEvent;
use App\Services\Batch\BatchCodeService;
use App\Services\Batch\QrCodeService;
use App\Services\Hardware\NewTagQueue;
use App\Services\Hardware\RfidScanService;
use App\Services\Hardware\RfidService;
use App\Support\ProcessFlow;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pilihan operator untuk UID baru di reader Receiving & Wet (NewTagQueue):
 *  - asNewBatch()  : timah basah baru datang (kadar Sn sekitar 60%, diuji saat tiba). Batch dibuat langsung di
 *                    Receiving sebagai drum besi. Bila kadar Sn sudah ada, isi langsung dituang ke Wet (IntakeService);
 *                    bila belum, batch menunggu di "timbang & kadar Sn".
 *  - asWetOutput() : container hasil proses Wet (PoolService::createOutputDrum).
 */
class NewTagChoiceService
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(
        private readonly NewTagQueue $queue,
        private readonly PoolService $pools,
        private readonly IntakeService $intake,
        private readonly RfidService $rfid,
        private readonly BatchCodeService $codes,
    ) {}

    /** @return array{ok:bool, code:string, message:string, batch?:Batch} */
    public function asWetOutput(int $companyId, string $rawUid, ?int $userId): array
    {
        $uid = $this->rfid->sanitizeUid($rawUid);
        $r = $this->pools->createOutputDrum($companyId, 'wet_process', $uid, $userId, 'web');

        if ($r['ok'] || in_array($r['code'], ['tag_in_use', 'tag_recycled', 'invalid_uid'], true)) {
            $this->queue->remove($companyId, $uid);   // pilihan selesai atau tag memang tidak bisa dipakai
        }
        return $r;
    }

    /** @return array{ok:bool, code:string, message:string, batch?:Batch} */
    public function asNewBatch(int $companyId, string $rawUid, int $productCodeId, float $weight, ?float $snPct, ?int $userId): array
    {
        $uid = $this->rfid->sanitizeUid($rawUid);
        if ($uid === '') {
            return $this->fail('invalid_uid', 'UID tag kosong atau tidak valid.');
        }
        if ($weight <= 0) {
            return $this->fail('invalid_weight', 'Berat harus lebih dari 0.');
        }
        if ($snPct !== null && ($snPct < 0 || $snPct > 100)) {
            return $this->fail('invalid_sn', 'Kadar Sn harus di antara 0 dan 100%.');
        }
        if (Batch::withTrashed()->where('rfid_tag', $uid)->exists()) {
            $this->queue->remove($companyId, $uid);

            return $this->fail('tag_in_use', "Tag {$uid} sudah dipakai. Gunakan tag lain.");
        }
        if ($this->pools->uidHasHistory($companyId, $uid)) {
            $this->queue->remove($companyId, $uid);

            return $this->fail('tag_recycled', 'Tag bekas — gunakan tag baru.');
        }

        $product = ProductCode::where('company_id', $companyId)->find($productCodeId);
        $location = Location::where('company_id', $companyId)->where('location_type', 'receiving')->where('is_active', true)->first();
        if (! $product) {
            return $this->fail('invalid_product', 'Kode produk tidak ditemukan.');
        }
        if (! $location) {
            return $this->fail('no_location', 'Lokasi Receiving belum diatur.');
        }

        try {
            $batch = DB::transaction(fn () => $this->createAtReceiving($companyId, $uid, $product, $location, $weight, $userId));
        } catch (UniqueConstraintViolationException) {
            return $this->fail('tag_in_use', "Tag {$uid} sudah dipakai. Gunakan tag lain.");
        }

        $this->queue->remove($companyId, $uid);
        app(RfidScanService::class)->forgetUid($companyId, $uid);
        try {
            app(QrCodeService::class)->generateForBatch($batch);
        } catch (\Throwable $e) {
            Log::warning("QR batch {$batch->batch_code} gagal dibuat: {$e->getMessage()}");
        }

        if ($snPct === null) {
            return [
                'ok' => true, 'code' => 'new_batch_created', 'batch' => $batch,
                'message' => "Batch {$batch->batch_code} dibuat. Isi kadar Sn setelah uji untuk menuang ke Wet.",
            ];
        }

        $batch = $this->intake->record($batch, $weight, $snPct, $userId);

        return [
            'ok' => true, 'code' => 'new_batch_poured', 'batch' => $batch,
            'message' => "Batch {$batch->batch_code} dibuat ({$weight} kg, kadar Sn {$snPct}%) dan masuk himpunan Wet.",
        ];
    }

    private function createAtReceiving(int $companyId, string $uid, ProductCode $product, Location $location, float $weight, ?int $userId): Batch
    {
        $company = Company::findOrFail($companyId);
        $ts = Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB';

        $batch = Batch::create([
            'company_id'          => $companyId,
            'batch_code'          => $this->codes->generate($company, $location, 'receiving', $product),
            'container_type'      => 'drum_besi',
            'product_code_id'     => $product->id,
            'initial_weight'      => $weight,
            'current_weight'      => $weight,
            'process_stage'       => 'receiving',
            'status'              => ProcessFlow::STATUS_AWAITING_INTAKE,
            'current_location_id' => $location->id,
            'export_status'       => $product->is_export_blocked ? 'not_applicable' : 'available',
            'rfid_tag'            => $uid,
            'metadata'            => ['origin' => 'receiving_new_tag', 'rfid_registered_at' => $ts],
            'created_by'          => $userId,
        ]);

        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $location->id,
            'checkpoint_stage' => 'receiving_verify',
            'weight_recorded'  => $weight,
            'weight_previous'  => $weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => true,
            'notes'            => "Timah basah baru diterima di Receiving, tag UID {$uid} — {$ts}",
            'checkpoint_data'  => [
                'action' => 'rfid_register', 'rfid_uid' => $uid, 'origin' => 'receiving_new_tag', 'timestamp_wib' => $ts,
                'batch_snapshot' => [
                    'process_stage' => 'receiving', 'status' => ProcessFlow::STATUS_AWAITING_INTAKE,
                    'current_location_id' => $location->id, 'current_weight' => $weight,
                ],
            ],
            'rfid_scan_code'   => $uid,
            'rfid_origin'      => 'web',
            'recorded_by'      => $userId,
            'recorded_at'      => now(),
        ]);

        RfidTagEvent::create([
            'company_id' => $companyId, 'batch_id' => $batch->id, 'uid' => $uid,
            'event' => 'registered', 'performed_by' => $userId,
        ]);

        Log::info("RECEIVING batch baru dari tag baru: {$batch->batch_code} UID {$uid} {$weight} kg");

        return $batch;
    }

    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
