<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\RfidTagEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RfidTagService — siklus hidup tag: terpasang → dilepas (diganti/hilang/rusak) → tag baru.
 * Constraint UNIQUE batches.rfid_tag TIDAK diubah (K2): UID yang pernah dipakai tidak otomatis
 * dapat dipakai ulang; pelepasan mengosongkan kolom batch sehingga UID itu bebas SETELAH dilepas.
 * Setiap pelepasan tercatat di rfid_tag_events (append-only) dengan alasan.
 */
class RfidTagService
{
    /**
     * @throws \DomainException no_tag
     */
    public function release(Batch $batch, User $user, string $reason, ?string $note = null): string
    {
        return DB::transaction(function () use ($batch, $user, $reason, $note) {
            $locked = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
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

            return $uid;
        });
    }

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
}
