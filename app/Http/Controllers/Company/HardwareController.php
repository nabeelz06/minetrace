<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\Hardware\RfidService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HardwareController — Halaman integrasi hardware (RFID, CCTV, dll).
 */
class HardwareController extends Controller
{
    public function __construct(
        private RfidService $rfid
    ) {}

    /**
     * Panel manajemen RFID — daftar kartu, registrasi, status kehadiran.
     */
    public function rfidPanel(): Response
    {
        $companyId = Auth::user()->company_id;
        $tz        = 'Asia/Jakarta';

        $users   = $this->rfid->getUsersWithRfidStatus($companyId);
        $present = $this->rfid->getPresentOperators($companyId);
        $pending = $this->rfid->getPendingRegistration($companyId);

        return Inertia::render('Company/Hardware/RfidPanel', [
            'rfid_users'       => $users,
            'present_operators'=> $present,
            'pending'          => $pending,
            'stats'            => [
                'total_users'      => count($users),
                'registered_count' => collect($users)->where('has_card', true)->count(),
                'present_count'    => count($present),
                'pending_count'    => $pending ? 1 : 0,
            ],
            'user_role'    => Auth::user()->getRoleNames()->first(),
            'current_time' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }
}