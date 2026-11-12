<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\RfidReadLog;
use App\Models\RfidTagEvent;
use App\Models\User;
use App\Support\ProcessFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RfidTagService — siklus hidup tag: terpasang → dilepas (diganti/hilang/rusak) → tag baru,
 * serta alat bantu uji: lihat status tag lewat UID dan "reset uji" (ulangi pengujian dengan tag yang sama).
 *
 * Prinsip (setara commissioning/decommissioning EPCIS): jejak audit TIDAK PERNAH dihapus.
 *  - Lepas tag     : mengosongkan batches.rfid_tag (UNIQUE tetap), mencatat rfid_tag_events 'released'.
 *  - Reset uji     : menyembunyikan (void) scan tag itu dari umpan, mengembalikan posisi batch ke keadaan saat
 *                    registrasi bila ada serah-terima otomatis, dan mencatat rfid_tag_events 'reset'. Tag TETAP terpasang.
 *  - Keduanya melupakan jejak sementara (dedupe/kehadiran/penolakan) agar pembacaan berikutnya diproses
 *    seperti pertama kali, tanpa menunggu jendela cache habis.
 */
class RfidTagService
{
    /** Aksi checkpoint yang MENGUBAH posisi batch (serah-terima otomatis RFID). */
    private const MOVING_ACTIONS = ['rfid_auto_receive', 'rfid_auto_dispatch'];

    public function __construct(
        private readonly RfidService $rfid,
        private readonly RfidScanService $scans,
    ) {}

    // ─── Lepas tag ────────────────────────────────────────────────────────

    /**
     * Lepas tag dari batch. Batch yang diarsipkan (soft-delete) ikut dicakup: UNIQUE DB tetap menahan UID-nya,
     * jadi pelepasan harus bisa menjangkaunya.
     *
     * @throws \DomainException no_tag
     */
    public function release(Batch $batch, User $user, string $reason, ?string $note = null): string
    {
        $result = DB::transaction(function () use ($batch, $user, $reason, $note) {
            $locked = Batch::withTrashed()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $uid    = $locked->rfid_tag;

            if (! $uid) {
                throw new \DomainException('no_tag');
            }

            $locked->update(['rfid_tag' => null]);

            RfidTagEvent::create([
                'company_id'   => $locked->company_id,
                'batch_id'     => $locked->id,
                'uid'          => $uid,
                'event'        => 'released',
                'reason'       => $reason,
                'note'         => $note ? mb_substr($note, 0, 255) : null,
                'performed_by' => $user->id,
            ]);

            Log::info("RFID tag DILEPAS: UID {$uid} dari batch {$locked->batch_code} alasan={$reason} oleh user_id={$user->id}");

            return [$uid, $locked->company_id];
        });

        [$uid, $companyId] = $result;
        $this->scans->forgetUid($companyId, $uid);

        return $uid;
    }

    /**
     * Lepas tag berdasarkan UID (alur "Kelola Tag": operator tidak perlu tahu batch-nya lebih dulu).
     * Tag milik perusahaan lain diperlakukan sama seperti tag bebas agar keberadaannya tidak bocor lintas tenant.
     *
     * @return array{uid:string,batch_id:int,batch_code:string}
     *
     * @throws \DomainException tag_free
     */
    public function releaseByUid(int $companyId, string $rawUid, User $user, string $reason, ?string $note = null): array
    {
        $uid   = $this->rfid->sanitizeUid($rawUid);
        $owner = Batch::withTrashed()->where('company_id', $companyId)->where('rfid_tag', $uid)->first();

        if (! $owner) {
            throw new \DomainException('tag_free');
        }

        try {
            $this->release($owner, $user, $reason, $note);
        } catch (\DomainException) {
            throw new \DomainException('tag_free');   // dilepas oleh proses lain tepat sebelum ini
        }

        return ['uid' => $uid, 'batch_id' => $owner->id, 'batch_code' => $owner->batch_code];
    }

    // ─── Lihat status tag ─────────────────────────────────────────────────

    /**
     * Status satu UID bagi perusahaan pemanggil.
     *   free     : tidak terpasang pada batch mana pun
     *   bound    : terpasang pada batch aktif milik perusahaan ini
     *   archived : terpasang pada batch yang diarsipkan milik perusahaan ini (UNIQUE tetap menahan UID)
     *   foreign  : dipakai perusahaan lain — tanpa rincian apa pun
     *
     * @return array<string,mixed>
     */
    public function lookup(int $companyId, string $rawUid): array
    {
        $uid   = $this->rfid->sanitizeUid($rawUid);
        $owner = Batch::withTrashed()->with('productCode:id,code,name')->where('rfid_tag', $uid)->first();

        $out = [
            'uid' => $uid, 'state' => 'free', 'batch' => null,
            'scans' => 0, 'last_scan_at' => null, 'reset' => null, 'history' => [],
        ];

        if ($owner && $owner->company_id !== $companyId) {
            $out['state'] = 'foreign';

            return $out;
        }

        $out['history'] = $this->uidHistory($companyId, $uid, 6);
        if (! $owner) {
            return $out;
        }

        $plan = $this->resetPlan($owner, $uid);

        return [
            'state' => $owner->trashed() ? 'archived' : 'bound',
            'batch' => [
                'id'           => $owner->id,
                'batch_code'   => $owner->batch_code,
                'product_code' => $owner->productCode?->code,
                'product_name' => $owner->productCode?->name,
                'stage'        => $owner->process_stage,
                'status'       => $owner->status,
                'weight_kg'    => (float) $owner->current_weight,
                'archived'     => $owner->trashed(),
            ],
            'scans'        => $plan['scans'],
            'last_scan_at' => $plan['last_scan_at'],
            'reset'        => [
                'enabled' => $plan['enabled'],
                'blocked' => $plan['blocked'],
                'scans'   => $plan['scans'],
                'records' => count($plan['scan_ids']),   // semua catatan yang akan disembunyikan (scan + serah-terima)
                'moves'   => $plan['moves'],
                'rewind'  => $plan['rewind'],
            ],
        ] + $out;
    }

    /**
     * UID yang terakhir dibaca reader perusahaan (umpan "tag terbaru" di Kelola Tag): satu baris per UID,
     * terbaru dulu, beserta status pemasangannya. Sumber = rfid_read_logs (mencakup tag asing yang ditolak).
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $companyId, int $limit = 12): array
    {
        $logs = RfidReadLog::where('company_id', $companyId)
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'epc', 'action', 'result', 'result_code', 'read_at', 'reader_id']);

        $byUid = [];
        foreach ($logs as $log) {
            $byUid[$log->epc] ??= [
                'uid' => $log->epc, 'last_read_at' => $log->read_at?->toIso8601String(),
                'last_action' => $log->action, 'last_result' => $log->result, 'last_code' => $log->result_code,
                'reads' => 0,
            ];
            $byUid[$log->epc]['reads']++;
        }
        $byUid = array_slice($byUid, 0, max(1, $limit), true);

        // Pemilik dicari lintas perusahaan (UNIQUE rfid_tag) hanya untuk menentukan status: tag milik perusahaan lain
        // ditandai 'foreign' tanpa membuka detail batch-nya, sehingga tidak tampil keliru sebagai 'free'.
        $owners = $byUid
            ? Batch::withTrashed()->with('productCode:id,code')
                ->whereIn('rfid_tag', array_keys($byUid))
                ->get()->keyBy('rfid_tag')
            : collect();

        return array_values(array_map(function (array $row) use ($owners, $companyId) {
            $b       = $owners->get($row['uid']);
            $foreign = $b !== null && $b->company_id !== $companyId;

            return $row + [
                'state' => $foreign ? 'foreign' : ($b ? ($b->trashed() ? 'archived' : 'bound') : 'free'),
                'batch' => ($b && ! $foreign) ? [
                    'id' => $b->id, 'batch_code' => $b->batch_code, 'product_code' => $b->productCode?->code,
                    'stage' => $b->process_stage, 'status' => $b->status,
                ] : null,
            ];
        }, $byUid));
    }

    // ─── Reset uji ────────────────────────────────────────────────────────

    /**
     * Rencana reset tanpa menulis apa pun (dipakai lookup() untuk pratinjau dan resetForTest() untuk eksekusi).
     *
     * Satu scan yang memindahkan batch menghasilkan DUA checkpoint: catatan audit scan (membawa rfid_scan_code) dan
     * checkpoint serah-terima otomatis (action rfid_auto_receive/dispatch, tanpa rfid_scan_code). Keduanya milik
     * "jejak tag" dan ikut disembunyikan; baris registrasi dipertahankan karena tag memang masih terpasang.
     *
     * Aturan:
     *  - nonaktif lewat config rfid.test_reset_enabled; batch yang sudah ditutup (ekspor/terminal) tidak boleh direset;
     *  - bila ada serah-terima otomatis yang harus dibalik, posisi batch dikembalikan ke `batch_snapshot` yang
     *    dicatat saat registrasi — HANYA bila setelah registrasi tidak ada pekerjaan manual (checkpoint lain,
     *    analisis lab, turunan/transformasi) yang akan ikut tertimpa.
     *
     * @return array{enabled:bool,blocked:?string,scan_ids:list<int>,scans:int,moves:int,rewind:?array,last_scan_at:?string}
     */
    public function resetPlan(Batch $batch, string $uid): array
    {
        $plan = [
            'enabled' => (bool) config('rfid.test_reset_enabled'),
            'blocked' => null, 'scan_ids' => [], 'scans' => 0, 'moves' => 0, 'rewind' => null, 'last_scan_at' => null,
        ];

        $action = fn (Checkpoint $c) => $c->checkpoint_data['action'] ?? null;

        // Registrasi terakhir tag ini pada batch ini = awal "jejak tag" (satu tag hanya punya sedikit checkpoint).
        $reg = Checkpoint::where('batch_id', $batch->id)->where('rfid_scan_code', $uid)
            ->orderByDesc('id')->limit(200)->get(['id', 'checkpoint_data', 'recorded_at'])
            ->first(fn (Checkpoint $c) => $action($c) === 'rfid_register');

        $after = Checkpoint::where('batch_id', $batch->id)->whereNull('voided_at')
            ->when($reg, fn ($q) => $q->where('id', '>', $reg->id))
            ->orderBy('id')->limit(1000)
            ->get(['id', 'checkpoint_data', 'rfid_scan_code', 'recorded_at']);

        $moves  = $after->filter(fn (Checkpoint $c) => in_array($action($c), self::MOVING_ACTIONS, true));
        $scans  = $after->filter(fn (Checkpoint $c) => $c->rfid_scan_code === $uid && $action($c) !== 'rfid_register');
        $manual = $after->first(fn (Checkpoint $c) => $c->rfid_scan_code === null
            && ! in_array($action($c), self::MOVING_ACTIONS, true));

        $plan['scan_ids']     = $scans->pluck('id')->merge($moves->pluck('id'))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $plan['scans']        = $scans->count();
        $plan['moves']        = $moves->count();
        $plan['last_scan_at'] = $scans->last()?->recorded_at?->toIso8601String();

        if (in_array($batch->status, ProcessFlow::CLOSED_STATUSES, true)) {
            $plan['blocked'] = 'reset_batch_closed';

            return $plan;
        }
        if ($moves->isEmpty()) {
            return $plan;   // hanya scan audit: cukup disembunyikan, posisi batch tidak berubah
        }

        $snap = $reg?->checkpoint_data['batch_snapshot'] ?? null;
        if (! $reg || ! is_array($snap) || ! isset($snap['process_stage'], $snap['status'])) {
            $plan['blocked'] = 'reset_no_baseline';

            return $plan;
        }

        $manualWork = $manual !== null
            || $batch->labAnalyses()->where('created_at', '>=', $reg->recorded_at)->exists()
            || $batch->childBatches()->exists()
            || $batch->transformations()->exists();
        if ($manualWork) {
            $plan['blocked'] = 'reset_has_manual_work';

            return $plan;
        }

        $target = [
            'process_stage'       => $snap['process_stage'],
            'status'              => $snap['status'],
            'current_location_id' => $snap['current_location_id'] ?? null,
        ];
        $changed = $batch->process_stage !== $target['process_stage']
            || $batch->status !== $target['status']
            || $batch->current_location_id !== $target['current_location_id'];
        $plan['rewind'] = $changed ? $target : null;

        return $plan;
    }

    /**
     * Reset uji berdasarkan UID (alur "Kelola Tag"). Tag bebas/milik perusahaan lain → tag_free (tanpa bocor lintas tenant).
     *
     * @return array{uid:string,batch_id:int,batch_code:string,voided:int,rewound:bool,rewind_to:?array}
     *
     * @throws \DomainException tag_free | reset_disabled | reset_batch_closed | reset_no_baseline | reset_has_manual_work
     */
    public function resetByUid(int $companyId, string $rawUid, User $user, ?string $note = null): array
    {
        $uid   = $this->rfid->sanitizeUid($rawUid);
        $owner = Batch::withTrashed()->where('company_id', $companyId)->where('rfid_tag', $uid)->first();

        if (! $owner) {
            throw new \DomainException('tag_free');
        }

        return $this->resetForTest($owner, $user, $note);
    }

    /**
     * Reset uji: sembunyikan scan tag ini, kembalikan posisi batch ke keadaan saat registrasi (bila perlu),
     * bersihkan jejak sementara. Tag tetap terpasang sehingga pengujian bisa diulang langsung.
     *
     * @return array{uid:string,batch_id:int,batch_code:string,voided:int,rewound:bool,rewind_to:?array}
     *
     * @throws \DomainException tag_free | reset_disabled | reset_batch_closed | reset_no_baseline | reset_has_manual_work
     */
    public function resetForTest(Batch $batch, User $user, ?string $note = null): array
    {
        $result = DB::transaction(function () use ($batch, $user, $note) {
            $locked = Batch::withTrashed()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $uid    = $locked->rfid_tag;

            if (! $uid) {
                throw new \DomainException('tag_free');
            }

            $plan = $this->resetPlan($locked, $uid);
            if (! $plan['enabled']) {
                throw new \DomainException('reset_disabled');
            }
            if ($plan['blocked']) {
                throw new \DomainException($plan['blocked']);
            }

            if ($plan['scan_ids']) {
                Checkpoint::whereIn('id', $plan['scan_ids'])->update([
                    'voided_at'   => now(),
                    'voided_by'   => $user->id,
                    'void_reason' => 'reset_uji',
                ]);
            }
            if ($plan['rewind']) {
                $locked->update($plan['rewind']);   // tercatat di activity_log (audit) oleh LogsActivity
            }

            RfidTagEvent::create([
                'company_id'   => $locked->company_id,
                'batch_id'     => $locked->id,
                'uid'          => $uid,
                'event'        => 'reset',
                'reason'       => 'test',
                'note'         => $note ? mb_substr($note, 0, 255) : null,
                'performed_by' => $user->id,
            ]);

            Log::info(sprintf(
                'RFID tag RESET UJI: UID %s batch %s scan_disembunyikan=%d dikembalikan=%s oleh user_id=%d',
                $uid, $locked->batch_code, count($plan['scan_ids']), $plan['rewind'] ? 'ya' : 'tidak', $user->id
            ));

            return [
                'uid' => $uid, 'company_id' => $locked->company_id, 'batch_id' => $locked->id,
                'batch_code' => $locked->batch_code, 'voided' => count($plan['scan_ids']),
                'rewound' => $plan['rewind'] !== null, 'rewind_to' => $plan['rewind'],
            ];
        });

        $this->scans->forgetUid($result['company_id'], $result['uid']);
        unset($result['company_id']);

        return $result;
    }

    // ─── Riwayat ──────────────────────────────────────────────────────────

    /** Riwayat tag untuk satu batch (terbaru dulu). */
    public function history(Batch $batch, int $limit = 20): array
    {
        return RfidTagEvent::with('performer:id,name')
            ->where('batch_id', $batch->id)
            ->latest('id')->limit($limit)->get()
            ->map(fn ($e) => [
                'id'      => $e->id,
                'uid'     => $e->uid,
                'event'   => $e->event,
                'reason'  => $e->reason,
                'note'    => $e->note,
                'by'      => $e->performer?->name,
                'at'      => $e->created_at?->toIso8601String(),
            ])->all();
    }

    /** Riwayat satu UID (lintas batch) dalam perusahaan pemanggil. */
    private function uidHistory(int $companyId, string $uid, int $limit): array
    {
        return RfidTagEvent::with(['performer:id,name', 'batch:id,batch_code'])
            ->where('company_id', $companyId)->where('uid', $uid)
            ->latest('id')->limit($limit)->get()
            ->map(fn ($e) => [
                'id'         => $e->id,
                'event'      => $e->event,
                'reason'     => $e->reason,
                'note'       => $e->note,
                'batch_code' => $e->batch?->batch_code,
                'by'         => $e->performer?->name,
                'at'         => $e->created_at?->toIso8601String(),
            ])->all();
    }
}
