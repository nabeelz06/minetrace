<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\Hardware\RfidService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HardwareController — Halaman manajemen hardware RFID di MINETRA.
 */
class HardwareController extends Controller
{
    public function __construct(
        private RfidService $rfid
    ) {}

    /**
     * Panel RFID — kontrol ESP32, registrasi tag ke batch, monitoring.
     */
    public function rfidPanel(): Response
    {
        $companyId = Auth::user()->company_id;
        $tz        = 'Asia/Jakarta';

        return Inertia::render('Company/Hardware/RfidPanel', [
            // ESP32 device info
            'esp32_ip'         => $this->rfid->getEsp32Ip($companyId),

            // Batch data
            'untagged_batches' => $this->rfid->getUntaggedBatches($companyId),
            'tagged_batches'   => $this->rfid->getTaggedBatches($companyId),

            // Pending session
            'pending_batch'    => $this->rfid->getPendingBatch($companyId),

            // Aktivitas RFID terbaru
            'recent_activity'  => $this->rfid->getRecentRfidActivity($companyId),

            // Stats
            'stats' => [
                'untagged_count' => count($this->rfid->getUntaggedBatches($companyId)),
                'tagged_count'   => count($this->rfid->getTaggedBatches($companyId)),
                'activity_count' => count($this->rfid->getRecentRfidActivity($companyId)),
            ],

            'user_role'    => Auth::user()->getRoleNames()->first(),
            'current_time' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }
}