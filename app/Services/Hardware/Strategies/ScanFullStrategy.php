<?php

namespace App\Services\Hardware\Strategies;

use App\Contracts\ScanAuthorizationStrategy;
use App\Models\Batch;
use App\Services\Hardware\RfidService;

/**
 * ScanFullStrategy — serah-terima otomatis penuh untuk gate DUA ARAH:
 *   Scan IN  → batch DITERIMA di tahap ini   (ScanToReceiveStrategy)
 *   Scan OUT → batch DIKIRIM ke tahap berikutnya (ScanToDispatchStrategy)
 *
 * Komposisi, bukan salinan: seluruh guard serah-terima tetap berasal dari StageTransitionService (identik dengan web),
 * sehingga aturan tidak bisa menyimpang antara mode satu-arah dan mode penuh.
 */
class ScanFullStrategy implements ScanAuthorizationStrategy
{
    private readonly ScanToReceiveStrategy $receive;

    private readonly ScanToDispatchStrategy $dispatch;

    public function __construct(
        RfidService $rfid,
        ?ScanToReceiveStrategy $receive = null,
        ?ScanToDispatchStrategy $dispatch = null,
    ) {
        $this->receive  = $receive ?? new ScanToReceiveStrategy($rfid);
        $this->dispatch = $dispatch ?? new ScanToDispatchStrategy($rfid);
    }

    public function handleScanIn(Batch $batch, string $stage, array $context): array
    {
        return $this->receive->handleScanIn($batch, $stage, $context);
    }

    public function handleScanOut(Batch $batch, string $stage, array $context): array
    {
        return $this->dispatch->handleScanOut($batch, $stage, $context);
    }

    public function getMode(): string { return 'scan_full'; }

    public function getDescription(): string
    {
        return 'Scan IN menerima batch dan Scan OUT mengirim batch secara otomatis (guard identik dengan web).';
    }
}
