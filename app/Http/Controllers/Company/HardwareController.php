<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HardwareController — Panel manajemen hardware RFID.
 */
class HardwareController extends Controller
{
    public function __construct(
        private RfidService             $rfid,
        private RfidAuthorizationService $authorization,
    ) {}

    public function mobileRfidScan(): Response
    {
        $user      = Auth::user();
        $companyId = $user->company_id ?? 1;
        $tz        = 'Asia/Jakarta';

        return Inertia::render('Company/Hardware/MobileRfidScan', [
            'authorization_modes' => $this->authorization->getAllModes($companyId),
            'valid_modes'         => $this->authorization->getValidModes(),
            'tagged_batches'      => $this->rfid->getTaggedBatches($companyId),
            'recent_activity'     => $this->rfid->getRecentRfidActivity($companyId),
            'user_role'           => $user->getRoleNames()->first() ?? 'company_admin',
            'company_id'          => $companyId,
            'current_time'        => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    public function rfidPanel(): Response
    {
        $user      = Auth::user();
        $companyId = $user->company_id ?? 1;
        $tz        = 'Asia/Jakarta';

        // ─── Load data batch ───────────────────────────────────────
        $untaggedBatches = $this->rfid->getUntaggedBatches($companyId);
        $taggedBatches   = $this->rfid->getTaggedBatches($companyId);
        $pendingBatch    = $this->rfid->getPendingBatch($companyId);
        $recentActivity  = $this->rfid->getRecentRfidActivity($companyId);

        // ─── Debug log — untuk verify data yang dikirim ke Vue ─────
        Log::info('HardwareController::rfidPanel', [
            'user_id'         => $user->id,
            'company_id'      => $companyId,
            'untagged_count'  => count($untaggedBatches),
            'tagged_count'    => count($taggedBatches),
        ]);

        return Inertia::render('Company/Hardware/RfidPanel', [
            'esp32_ip'         => $this->rfid->getEsp32Ip($companyId),
            'untagged_batches' => $untaggedBatches,
            'tagged_batches'   => $taggedBatches,
            'pending_batch'    => $pendingBatch,
            'recent_activity'  => $recentActivity,
            'stats' => [
                'untagged_count' => count($untaggedBatches),
                'tagged_count'   => count($taggedBatches),
                'activity_count' => count($recentActivity),
            ],
            'user_role'    => $user->getRoleNames()->first() ?? 'company_admin',
            'company_id'   => $companyId,   // ← kirim ke Vue untuk debug
            'current_time' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }
}