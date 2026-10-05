<?php

namespace App\Http\Controllers\Api\V1\Hardware;

use App\Http\Controllers\Controller;
use App\Models\RfidReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ApiRfidDeviceController — CRUD Device Registry RFID Reader (Fase 7).
 *
 * ════════════════════════════════════════════════════════════════════════
 * Dipanggil dari tab "Perangkat"/"Devices" di RfidPanel.vue (session auth,
 * middleware ['web','auth'] — sama seperti endpoint company/rfid lainnya).
 *
 * SENGAJA dipisah dari ApiRfidController (yang menangani event scan
 * hardware real-time). Alasan Single Responsibility: scan-event logic
 * (register/login/logout tag) dan device lifecycle management (daftar/
 * cabut/generate ulang key reader) adalah dua concern dengan kecepatan
 * perubahan yang berbeda — scan logic jarang berubah setelah stabil,
 * sedangkan device management akan sering dipakai setiap kali reader baru
 * di-rollout ke checkpoint baru. Memisahnya mencegah ApiRfidController
 * (sudah 600+ baris) membengkak lebih jauh dengan concern yang tidak
 * berhubungan langsung.
 *
 * OTORISASI:
 *   - index()  → company_admin ATAU company_auditor (lihat saja).
 *   - semua method lain (store/update/regenerateKey/revoke/reactivate)
 *     → company_admin SAJA.
 *   Pola ini konsisten dengan CctvController (manajemen kamera) dan
 *   ApiRfidController::setAuthorizationMode() — keduanya infra-level
 *   hardware config, bukan aksi operasional harian, jadi dibatasi ke role
 *   admin yang sama.
 * ════════════════════════════════════════════════════════════════════════
 */
class ApiRfidDeviceController extends Controller
{
    private const MANAGE_ROLES = ['company_admin'];
    private const VIEW_ROLES   = ['company_admin', 'company_auditor'];

    private const VALID_LOCATIONS = [
        'receiving', 'wet_process', 'dry_process', 'warehouse', 'project_plant',
    ];

    private const VALID_HARDWARE_TYPES = ['esp32_rc522', 'uhf_vx6336', 'other'];

    /**
     * GET /api/v1/company/rfid/devices
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(self::VIEW_ROLES), 403);

        $companyId = Auth::user()->company_id;

        $readers = RfidReader::where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get()
            ->map->toDisplayArray();

        return response()->json(['success' => true, 'data' => $readers]);
    }

    /**
     * POST /api/v1/company/rfid/devices
     * Body: { name, location_key?, hardware_type? }
     *
     * Response data menyertakan `plain_key` SEKALI SAJA — frontend WAJIB
     * menampilkannya dalam panel "salin sekarang, tidak akan tampil lagi"
     * lalu membuangnya dari state setelah admin menutupnya. Nilai ini TIDAK
     * PERNAH disimpan ke DB (hanya hash-nya) maupun ditulis ke log.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(self::MANAGE_ROLES), 403,
            'Hanya company_admin yang dapat mendaftarkan reader baru.');

        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'location_key'  => 'nullable|string|in:' . implode(',', self::VALID_LOCATIONS),
            'hardware_type' => 'nullable|string|in:' . implode(',', self::VALID_HARDWARE_TYPES),
        ]);

        $companyId   = Auth::user()->company_id;
        $credentials = RfidReader::generateCredentials($companyId);

        $reader = RfidReader::create([
            'company_id'      => $companyId,
            'name'            => $validated['name'],
            'device_code'     => $credentials['device_code'],
            'device_key_hash' => $credentials['device_key_hash'],
            'location_key'    => $validated['location_key'] ?? null,
            'hardware_type'   => $validated['hardware_type'] ?? 'other',
            'is_active'       => true,
            'created_by'      => Auth::id(),
        ]);

        Log::info("RfidReader didaftarkan: {$reader->device_code} ({$reader->name}) company_id={$companyId} oleh user_id=" . Auth::id());

        return response()->json([
            'success' => true,
            'message' => "Reader '{$reader->name}' berhasil didaftarkan. Salin device key sekarang — tidak akan ditampilkan lagi.",
            'data'    => $reader->toDisplayArray() + ['plain_key' => $credentials['plain_key']],
        ], 201);
    }

    /**
     * PUT /api/v1/company/rfid/devices/{reader}
     * Body: { name?, location_key?, hardware_type? }
     * TIDAK mengubah device_key — gunakan regenerateKey() untuk itu.
     */
    public function update(Request $request, RfidReader $reader): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(self::MANAGE_ROLES), 403);
        abort_if($reader->company_id !== Auth::user()->company_id, 403);

        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:100',
            'location_key'  => 'nullable|string|in:' . implode(',', self::VALID_LOCATIONS),
            'hardware_type' => 'nullable|string|in:' . implode(',', self::VALID_HARDWARE_TYPES),
        ]);

        $reader->update($validated);

        return response()->json([
            'success' => true,
            'message' => "Reader '{$reader->name}' berhasil diperbarui.",
            'data'    => $reader->refresh()->toDisplayArray(),
        ]);
    }

    /**
     * POST /api/v1/company/rfid/devices/{reader}/regenerate-key
     *
     * Invalidate key lama, generate key baru SECARA LANGSUNG. Reader yang
     * masih memakai key lama akan mulai menerima 401 pada request
     * berikutnya — ini SENGAJA, bukan bug: tujuan regenerate biasanya
     * karena key dicurigai bocor/salah kirim, jadi key lama harus mati
     * seketika, bukan menunggu kadaluarsa.
     */
    public function regenerateKey(Request $request, RfidReader $reader): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(self::MANAGE_ROLES), 403);
        abort_if($reader->company_id !== Auth::user()->company_id, 403);

        $credentials = RfidReader::generateCredentials($reader->company_id);
        $reader->update(['device_key_hash' => $credentials['device_key_hash']]);

        Log::warning("RfidReader device_key di-regenerate: {$reader->device_code} oleh user_id=" . Auth::id());

        return response()->json([
            'success' => true,
            'message' => "Device key baru dibuat untuk '{$reader->name}'. Key lama tidak berlaku lagi.",
            'data'    => $reader->toDisplayArray() + ['plain_key' => $credentials['plain_key']],
        ]);
    }

    /**
     * POST /api/v1/company/rfid/devices/{reader}/revoke
     *
     * Soft-disable, BUKAN hard delete — riwayat reader (nama, device_code,
     * timestamp last_seen) dipertahankan untuk audit trail. Konsisten
     * dengan pola is_active di CctvCamera/CompanySetting: entity hardware
     * yang pernah menghasilkan data produksi real tidak pernah benar-benar
     * dihapus dari sistem traceability ini.
     */
    public function revoke(Request $request, RfidReader $reader): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(self::MANAGE_ROLES), 403);
        abort_if($reader->company_id !== Auth::user()->company_id, 403);

        $reader->revoke(Auth::id());

        Log::warning("RfidReader dicabut: {$reader->device_code} oleh user_id=" . Auth::id());

        return response()->json([
            'success' => true,
            'message' => "Akses reader '{$reader->name}' dicabut. Device ini tidak dapat lagi mengirim data sampai diaktifkan ulang.",
            'data'    => $reader->refresh()->toDisplayArray(),
        ]);
    }

    /**
     * POST /api/v1/company/rfid/devices/{reader}/reactivate
     * Catatan: device_key TIDAK berubah — key lama (jika device fisiknya
     * masih menyimpan yang sama) langsung berfungsi lagi.
     */
    public function reactivate(Request $request, RfidReader $reader): JsonResponse
    {
        abort_unless(Auth::user()->hasAnyRole(self::MANAGE_ROLES), 403);
        abort_if($reader->company_id !== Auth::user()->company_id, 403);

        $reader->reactivate();

        Log::info("RfidReader diaktifkan kembali: {$reader->device_code} oleh user_id=" . Auth::id());

        return response()->json([
            'success' => true,
            'message' => "Reader '{$reader->name}' diaktifkan kembali.",
            'data'    => $reader->refresh()->toDisplayArray(),
        ]);
    }
}
