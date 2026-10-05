<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\RfidReader;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HardwareController — Panel manajemen hardware RFID.
 *
 * ════════════════════════════════════════════════════════════════════════
 * KONSOLIDASI NAVIGASI RFID (Section 3 Part B)
 *
 * Dua permukaan RFID melayani konteks kerja yang berbeda, bukan fitur
 * duplikat:
 *   - RfidPanel.vue     → konsol admin/supervisor: konfigurasi ESP32,
 *                          manajemen mode otorisasi, registrasi tag,
 *                          audit log lengkap. Ditujukan untuk desktop.
 *   - RfidFieldMode.vue → interface operator lapangan: satu tujuan (scan
 *                          konfirmasi + preview CCTV), touch-target besar,
 *                          getaran perangkat sebagai feedback. Ditujukan
 *                          untuk mobile.
 *
 * Sebelumnya kedua halaman ini muncul sebagai DUA item navigasi sibling
 * yang terpisah ("Hardware RFID" dan "Mobile RFID Scan") di sidebar,
 * membuat navigasi terasa terfragmentasi. Sekarang keduanya dikonsolidasi
 * menjadi SATU kapabilitas adaptif: rfidPanel() secara otomatis meng-
 * arahkan operator lapangan yang mengakses dari perangkat mobile ke
 * RfidFieldMode.vue (redirect eksplisit, BUKAN swap rendering diam-diam —
 * agar URL browser selalu mencerminkan mode yang aktif dan bisa di-
 * bookmark). Admin/auditor tetap melihat panel penuh dari perangkat apa
 * pun, dengan link manual ("Buka Mode Lapangan") untuk preview/pakai versi
 * lapangan bila diperlukan.
 * ════════════════════════════════════════════════════════════════════════
 */
class HardwareController extends Controller
{
    /**
     * Role operator stage — jika mengakses /company/hardware/rfid dari
     * perangkat mobile, di-redirect otomatis ke mode lapangan (B2).
     * company_admin dan company_auditor SENGAJA tidak dimasukkan di sini —
     * mereka selalu melihat panel penuh terlepas dari perangkat.
     */
    private const STAGE_OPERATOR_ROLES = [
        'operator_lab_station',
        'operator_lab_ltj',
        'operator_wet',
        'operator_dry',
        'operator_warehouse',
    ];

    /** Role yang boleh melihat link balik ke panel penuh dari mode lapangan (B3) */
    private const FULL_PANEL_ROLES = ['company_admin', 'company_auditor'];

    /** Token User-Agent umum untuk mendeteksi perangkat mobile (B2) */
    private const MOBILE_UA_PATTERN = '/Mobi|Android|iPhone|iPad/i';

    public function __construct(
        private RfidService             $rfid,
        private RfidAuthorizationService $authorization,
    ) {}

    /**
     * GET /company/hardware/rfid/field
     *
     * Mode Lapangan RFID — Progressive Authorization interface.
     * Diakses langsung (bookmark) ATAU via redirect otomatis dari
     * rfidPanel() (lihat B2).
     */
    public function rfidFieldMode(): Response
    {
        $user      = Auth::user();
        $companyId = $user->company_id ?? 1;
        $tz        = 'Asia/Jakarta';
        $userRole  = $user->getRoleNames()->first() ?? 'company_admin';

        return Inertia::render('Company/Hardware/RfidFieldMode', [
            'authorization_modes' => $this->authorization->getAllModes($companyId),
            'valid_modes'         => $this->authorization->getValidModes(),
            'tagged_batches'      => $this->rfid->getTaggedBatches($companyId),
            'recent_activity'     => $this->rfid->getRecentRfidActivity($companyId),
            'user_role'           => $userRole,
            'company_id'          => $companyId,
            // Kode company (mis. 'TIMAH') — WAJIB dikirim sebagai header
            // X-Company-Code pada setiap panggilan /api/rfid/* dari browser.
            // Endpoint /api/rfid/* berjalan di bawah middleware group 'api'
            // (stateless, tanpa session), sehingga Auth::check() TIDAK
            // pernah bernilai true di sana — X-Company-Code adalah satu-
            // satunya cara controller tahu tenant mana yang sedang aktif
            // saat request datang dari panel web, bukan dari ESP32 asli.
            'company_code'        => $user->company?->code,
            // B3: link balik ke panel penuh hanya untuk admin/auditor
            'can_view_full_panel' => in_array($userRole, self::FULL_PANEL_ROLES, true),
            'current_time'        => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }

    /**
     * GET /company/hardware/rfid
     *
     * Konsol RFID penuh (admin/supervisor). Jika diakses oleh role operator
     * stage DARI perangkat mobile, redirect otomatis ke mode lapangan (B2)
     * — redirect eksplisit (302), bukan swap rendering diam-diam, sehingga
     * URL selalu mencerminkan mode yang aktif dan bisa di-bookmark.
     */
    public function rfidPanel(Request $request): Response|RedirectResponse
    {
        $user      = Auth::user();
        $companyId = $user->company_id ?? 1;
        $tz        = 'Asia/Jakarta';
        $userRole  = $user->getRoleNames()->first() ?? 'company_admin';

        $isStageOperator = in_array($userRole, self::STAGE_OPERATOR_ROLES, true);
        $isMobileDevice  = (bool) preg_match(self::MOBILE_UA_PATTERN, (string) $request->userAgent());

        if ($isStageOperator && $isMobileDevice) {
            Log::info('HardwareController::rfidPanel — redirect otomatis ke mode lapangan.', [
                'user_id'    => $user->id,
                'role'       => $userRole,
                'user_agent' => $request->userAgent(),
            ]);

            return redirect()->route('company.hardware.rfid.field');
        }

        // ─── Load data batch ───────────────────────────────────────
        $untaggedBatches = $this->rfid->getUntaggedBatches($companyId);
        $taggedBatches   = $this->rfid->getTaggedBatches($companyId);
        $pendingBatch    = $this->rfid->getPendingBatch($companyId);
        $recentActivity  = $this->rfid->getRecentRfidActivity($companyId);

        // ─── Device registry (Fase 7) ───────────────────────────────
        // Hanya di-query & dikirim ke frontend untuk role yang memang
        // boleh melihatnya (sama seperti can_view_full_panel di
        // rfidFieldMode() di atas) — role lain menerima array kosong di
        // props, BUKAN cuma disembunyikan di UI, supaya tidak ada data
        // reader yang "ikut terkirim" ke payload Inertia role yang tidak
        // berwenang (defense in depth; endpoint /devices sendiri juga
        // tetap melakukan abort_unless-nya sendiri, lihat
        // ApiRfidDeviceController).
        $canViewDevices = in_array($userRole, self::FULL_PANEL_ROLES, true);
        $devices        = $canViewDevices
            ? RfidReader::where('company_id', $companyId)->orderByDesc('created_at')->get()->map->toDisplayArray()
            : [];

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
            'devices'          => $devices,
            'stats' => [
                'untagged_count' => count($untaggedBatches),
                'tagged_count'   => count($taggedBatches),
                'activity_count' => count($recentActivity),
            ],
            'user_role'    => $userRole,
            'company_id'   => $companyId,   // ← kirim ke Vue untuk debug
            // Kode company untuk header X-Company-Code (lihat catatan di
            // rfidFieldMode() di atas — mekanisme yang sama berlaku di sini).
            'company_code' => $user->company?->code,
            'current_time' => Carbon::now($tz)->format('d/m/Y H:i:s') . ' WIB',
        ]);
    }
}
