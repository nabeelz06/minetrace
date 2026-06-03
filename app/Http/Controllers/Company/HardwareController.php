<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
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
        private RfidService $rfid
    ) {}

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