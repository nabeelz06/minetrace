<?php

namespace App\Listeners;

use App\Events\RfidTagScanned;
use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\Checkpoint;

/**
 * DispatchCctvSnapshotForScan — pembungkus tipis: terima RfidTagScanned, dispatch job CCTV yang
 * SUDAH ADA (CaptureCctvSnapshotJob, ShouldQueue). Listener ini SENGAJA TIDAK implements
 * ShouldQueue — ia hanya memanggil dispatch(), yang murah (push ke queue) dan tidak perlu
 * antrean kedua. Non-blocking datang dari job itu sendiri, bukan dari listener ini.
 */
class DispatchCctvSnapshotForScan
{
    public function handle(RfidTagScanned $event): void
    {
        $log = $event->log;
        if (! $log->checkpoint_id) {
            return;   // tidak terjadi untuk scan sukses (selalu punya checkpoint), jaga-jaga saja
        }

        Checkpoint::whereKey($log->checkpoint_id)->update(['snapshot_status' => 'pending']);
        $log->update(['snapshot_status' => 'pending']);

        dispatch(new CaptureCctvSnapshotJob(
            checkpointId: $log->checkpoint_id,
            companyId: $log->company_id,
            stageName: $log->stage,
            scanType: $log->action === 'out' ? 'scan_out' : 'scan_in',
            rfidUid: $log->epc,
            readLogId: $log->id,
            readerId: $log->reader_id,
        ))->onQueue(config('cctv.queue', 'default'))
            ->delay(now()->addSeconds(config('cctv.snapshot_delay', 2)));
    }
}
