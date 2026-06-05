<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MassBalanceThreshold;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RfidService — Business logic integrasi hardware RFID ESP32 untuk tracking batch mineral.
 *
 * ════════════════════════════════════════════════════════════════════════
 * ARSITEKTUR SISTEM:
 *
 *   Browser Operator (MINETRA Web)
 *     │
 *     ├─ Simpan ESP32 IP ke CompanySetting
 *     ├─ Pilih Batch → POST /api/v1/rfid/pending-batch → Cache 90 detik
 *     └─ Klik Mode → fetch langsung ke ESP32 IP /set-mode
 *
 *   ESP32 Hardware (RC522 RFID + LCD I2C)
 *     │
 *     ├─ Boot: GET /api/v1/hardware/rfid/esp-config (ambil config dari MINETRA)
 *     ├─ Mode REGISTER → LCD "Tempel Tag" → scan karung
 *     │   └─ POST /api/rfid/register {uid} → MINETRA simpan batch.rfid_tag
 *     ├─ Mode LOGIN → LCD "Tempel Tag" → scan karung masuk gudang
 *     │   └─ POST /api/rfid/login {uid} → MINETRA catat checkpoint IN
 *     └─ Mode LOGOUT → LCD "Tempel Tag" → scan karung keluar gudang
 *         └─ POST /api/rfid/logout {uid} → MINETRA catat checkpoint OUT
 *
 * PENTING — ENUM RULE:
 *   Semua nilai checkpoint_stage HARUS menggunakan match() PHP 8.
 *   JANGAN string concatenation → SQLSTATE[23514] Check violation!
 * ════════════════════════════════════════════════════════════════════════
 */
class RfidService
{
    private const CACHE_PENDING_BATCH_PREFIX = 'rfid_pending_batch_';
    private const CACHE_PENDING_TTL          = 90;  // detik
    private const TZ                         = 'Asia/Jakarta';

    // ═══════════════════════════════════════════════════════════════════
    // REGISTER — Link RFID tag ke batch tertentu (dilakukan di Lab Kimia)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Daftarkan UID RFID tag ke batch yang sedang pending registrasi.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/register
     * Skenario: Operator Lab menempel tag RFID ke karung mineral baru.
     *
     * @param  string $uid       UID hex dari tag RFID (contoh: "A3B2C1D0")
     * @param  int    $companyId Company ID
     * @return array             Response untuk LCD ESP32 (max 16 karakter per field)
     */
    public function registerBatchTag(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cek apakah UID sudah terdaftar ke batch lain ─────────────
        $existingBatch = Batch::where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if ($existingBatch) {
            Log::warning("RFID Register: UID {$uid} sudah dipakai oleh batch {$existingBatch->batch_code}");
            return [
                'status'  => 'failed',
                'name'    => substr($existingBatch->batch_code, 0, 16),
                'message' => 'Tag sudah dipakai',
                'detail'  => "UID ini sudah digunakan oleh batch {$existingBatch->batch_code}.",
            ];
        }

        // ─── Ambil pending batch dari cache ───────────────────────────
        $cacheKey   = self::CACHE_PENDING_BATCH_PREFIX . $companyId;
        $pendingData = Cache::get($cacheKey);

        if (!$pendingData) {
            Log::info("RFID Register: Tidak ada pending batch untuk company {$companyId}");
            return [
                'status'  => 'failed',
                'name'    => 'Tidak Ada Sesi',
                'message' => 'Tidak ada sesi',
                'detail'  => 'Mulai sesi registrasi dari panel web MINETRA terlebih dahulu.',
            ];
        }

        $batch = Batch::where('company_id', $companyId)->find($pendingData['batch_id']);

        if (!$batch) {
            Cache::forget($cacheKey);
            return ['status' => 'failed', 'name' => 'Error', 'message' => 'Batch tidak ada'];
        }

        // ─── Hubungkan tag UID ke batch ────────────────────────────────
        $batch->update([
            'rfid_tag'   => $uid,
            'updated_by' => null, // sistem, bukan user
            'metadata'   => array_merge($batch->metadata ?? [], [
                'rfid_registered_at' => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                'rfid_registered_by' => $pendingData['initiated_by'] ?? 'System',
            ]),
        ]);

        // ─── Catat checkpoint register di Lab Kimia ───────────────────
        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => 'lab_kimia_create',     // ← ENUM valid
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => true,
            'notes'            => "RFID Tag terdaftar: UID {$uid} — {$pendingData['initiated_by']}",
            'checkpoint_data'  => [
                'action'         => 'rfid_register',
                'rfid_uid'       => $uid,
                'initiated_by'   => $pendingData['initiated_by'] ?? 'System',
                'timestamp_wib'  => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            ],
            'rfid_scan_code'   => $uid,
            'recorded_by'      => null,
            'recorded_at'      => now(),
        ]);

        // ─── Hapus pending dari cache setelah berhasil ─────────────────
        Cache::forget($cacheKey);

        Log::info("RFID Register: Berhasil. UID {$uid} → Batch {$batch->batch_code} (ID:{$batch->id})");

        $displayCode = $this->truncate($batch->batch_code, 16);
        return [
            'status'  => 'success',
            'name'    => $displayCode,
            'message' => 'Tag terdaftar!',
            'detail'  => "Batch {$batch->batch_code} ({$batch->productCode?->code}) berhasil ditag UID {$uid}.",
            'batch_id'   => $batch->id,
            'batch_code' => $batch->batch_code,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // LOGIN (CHECKPOINT IN) — Scan karung saat MASUK area/gudang
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Catat checkpoint MASUK ketika karung di-scan di pintu masuk gudang.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/login
     * Skenario: Karung mineral tiba di depan gudang, operator scan RFID.
     *
     * @param  string $uid       UID tag RFID yang discan
     * @param  int    $companyId Company ID
     * @return array             Response untuk LCD ESP32
     */
    public function checkpointIn(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);
        $batch = Batch::with('productCode')
            ->where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$batch) {
            Log::warning("RFID Login (IN): UID {$uid} tidak terdaftar pada batch manapun");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar. Lakukan registrasi terlebih dahulu.",
            ];
        }

        // ─── Tentukan checkpoint_stage ENUM yang valid ─────────────────
        // WAJIB menggunakan match() — JANGAN string concatenation
        $checkpointStage = match($batch->process_stage) {
            'lab_kimia'     => 'lab_kimia_create',
            'receiving'     => 'receiving_verify',
            'wet_process'   => 'wet_process_input',
            'dry_process'   => 'dry_process_input',
            'warehouse'     => 'warehouse_inbound',
            'project_plant' => 'project_plant_receive',
            default         => 'warehouse_inbound',
        };

        // ─── Cek threshold mass balance ────────────────────────────────
        $threshold = MassBalanceThreshold::where('company_id', $companyId)
            ->where('process_stage', $batch->process_stage)
            ->first();

        // ─── Catat checkpoint IN ───────────────────────────────────────
        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => $checkpointStage,
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => true,
            'notes'            => "RFID Scan MASUK — Stage: {$batch->process_stage} — " . Carbon::now(self::TZ)->format('H:i:s') . ' WIB',
            'checkpoint_data'  => [
                'action'          => 'rfid_checkpoint_in',
                'rfid_uid'        => $uid,
                'process_stage'   => $batch->process_stage,
                'product_code'    => $batch->productCode?->code,
                'weight_kg'       => $batch->current_weight,
                'timestamp_wib'   => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            ],
            'rfid_scan_code'   => $uid,
            'recorded_by'      => null,
            'recorded_at'      => now(),
        ]);

        Log::info("RFID IN: Batch {$batch->batch_code} (UID:{$uid}) checkpoint masuk. Stage: {$batch->process_stage}");

        $timeWib     = Carbon::now(self::TZ)->format('H:i:s');
        $displayCode = $this->truncate($batch->batch_code, 16);
        $displayProd = $this->truncate($batch->productCode?->code ?? 'BATCH', 8);

        return [
            'status'     => 'success',
            'name'       => $displayCode,
            'message'    => 'Batch masuk OK',
            'detail'     => "Batch {$batch->batch_code} ({$displayProd}) {$batch->current_weight}kg — Checkpoint IN dicatat pukul {$timeWib} WIB.",
            'batch_id'   => $batch->id,
            'batch_code' => $batch->batch_code,
            'product'    => $batch->productCode?->code,
            'weight_kg'  => $batch->current_weight,
            'stage'      => $batch->process_stage,
            'timestamp'  => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // LOGOUT (CHECKPOINT OUT) — Scan karung saat KELUAR area/gudang
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Catat checkpoint KELUAR ketika karung di-scan di pintu keluar.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/logout
     * Skenario: Karung mineral dipindahkan keluar dari gudang.
     *
     * @param  string $uid       UID tag RFID yang discan
     * @param  int    $companyId Company ID
     * @return array             Response untuk LCD ESP32
     */
    public function checkpointOut(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);
        $batch = Batch::with('productCode')
            ->where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$batch) {
            Log::warning("RFID Logout (OUT): UID {$uid} tidak terdaftar");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar.",
            ];
        }

        // ─── Tentukan checkpoint_stage ENUM yang valid untuk OUT ───────
        // WAJIB menggunakan match() — ENUM PostgreSQL constraint
        $checkpointStage = match($batch->process_stage) {
            'lab_kimia'     => 'in_transit',
            'receiving'     => 'in_transit',
            'wet_process'   => 'wet_process_output',
            'dry_process'   => 'dry_process_output',
            'warehouse'     => 'warehouse_transfer',
            'project_plant' => 'in_transit',
            default         => 'warehouse_transfer',
        };

        // ─── Catat checkpoint OUT ──────────────────────────────────────
        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => $checkpointStage,
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => true,
            'notes'            => "RFID Scan KELUAR — Stage: {$batch->process_stage} — " . Carbon::now(self::TZ)->format('H:i:s') . ' WIB',
            'checkpoint_data'  => [
                'action'         => 'rfid_checkpoint_out',
                'rfid_uid'       => $uid,
                'process_stage'  => $batch->process_stage,
                'product_code'   => $batch->productCode?->code,
                'weight_kg'      => $batch->current_weight,
                'timestamp_wib'  => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            ],
            'rfid_scan_code'   => $uid,
            'recorded_by'      => null,
            'recorded_at'      => now(),
        ]);

        Log::info("RFID OUT: Batch {$batch->batch_code} (UID:{$uid}) checkpoint keluar. Stage: {$batch->process_stage}");

        $timeWib     = Carbon::now(self::TZ)->format('H:i:s');
        $displayCode = $this->truncate($batch->batch_code, 16);

        return [
            'status'     => 'success',
            'name'       => $displayCode,
            'message'    => 'Batch keluar OK',
            'detail'     => "Batch {$batch->batch_code} keluar pukul {$timeWib} WIB. Checkpoint OUT dicatat.",
            'batch_id'   => $batch->id,
            'batch_code' => $batch->batch_code,
            'product'    => $batch->productCode?->code,
            'stage'      => $batch->process_stage,
            'timestamp'  => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // PENDING BATCH — Antrian registrasi tag ke batch
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Set pending batch — batch mana yang akan menerima tag berikutnya.
     * Dipanggil dari web MINETRA sebelum operator tempel tag.
     */
   public function setPendingBatch(int $batchId, int $companyId, string $initiatedBy = 'System'): void
    {
        // ─── Guard: company_id harus valid ────────────────────────────────
        if ($companyId <= 0) {
            $companyId = 1; // fallback ke PT Timah
            Log::warning("RfidService::setPendingBatch — company_id invalid, fallback ke 1");
        }

        $batch    = Batch::where('company_id', $companyId)->findOrFail($batchId);
        $cacheKey = self::CACHE_PENDING_BATCH_PREFIX . $companyId;

        Cache::put($cacheKey, [
            'batch_id'     => $batchId,
            'batch_code'   => $batch->batch_code,
            'product_code' => $batch->productCode?->code,
            'initiated_by' => $initiatedBy,
            'company_id'   => $companyId,    // ← simpan untuk debug
            'started_at'   => now()->toISOString(),
        ], self::CACHE_PENDING_TTL);

        Log::info("RFID Pending Batch SET: batch={$batch->batch_code} company_id={$companyId} oleh={$initiatedBy}");
    }

    /**
     * Ambil status pending batch saat ini.
     */
    public function getPendingBatch(int $companyId): ?array
    {
        $cacheKey = self::CACHE_PENDING_BATCH_PREFIX . $companyId;
        return Cache::get($cacheKey);
    }

    /**
     * Batalkan pending batch.
     */
    public function cancelPendingBatch(int $companyId): void
    {
        Cache::forget(self::CACHE_PENDING_BATCH_PREFIX . $companyId);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ESP32 DEVICE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Simpan IP address ESP32 ke company settings.
     * IP ini digunakan web MINETRA untuk kirim perintah mode ke ESP32.
     */
    public function saveEsp32Ip(int $companyId, string $ip): void
    {
        $ip = rtrim(trim($ip), '/');
        if (!str_starts_with($ip, 'http')) {
            $ip = 'http://' . $ip;
        }

        CompanySetting::updateOrCreate(
            ['company_id' => $companyId, 'key' => 'esp32_ip'],
            ['value' => $ip]
        );

        Log::info("ESP32 IP disimpan: {$ip} untuk company_id={$companyId}");
    }

    /**
     * Ambil IP ESP32 dari company settings.
     */
    public function getEsp32Ip(int $companyId): ?string
    {
        return CompanySetting::where('company_id', $companyId)
            ->where('key', 'esp32_ip')
            ->value('value');
    }

    /**
     * Konfigurasi yang dikirim ke ESP32 saat boot.
     * ESP32 GET /api/v1/hardware/rfid/esp-config pada startup.
     */
    public function getEspBootConfig(int $companyId): array
    {
        return [
            'api_base_url'      => config('app.url'),
            'endpoint_register' => '/api/rfid/register',
            'endpoint_login'    => '/api/rfid/login',
            'endpoint_logout'   => '/api/rfid/logout',
            'api_token'         => config('app.rfid_api_token', env('RFID_API_TOKEN', '')),
            'company_id'        => $companyId,
            'timeout_ms'        => 5000,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // BATCH STATUS — Daftar batch dengan info RFID
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Daftar batch yang BELUM punya RFID tag (untuk dropdown register).
     */
    public function getUntaggedBatches(int $companyId): array
    {
        $query = Batch::with('productCode')
            ->whereNull('rfid_tag')
            ->whereNotIn('status', ['exported', 'terminated']);

        $withCompany = (clone $query)->where('company_id', $companyId);

        if ($withCompany->count() > 0) {
            $query = $withCompany;
        } else {
            Log::warning("RfidService::getUntaggedBatches — company_id={$companyId} tidak memiliki batch. Fallback ke semua batch.");
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn($b) => [
                'id'            => $b->id,
                'batch_code'    => $b->batch_code,
                'product_code'  => $b->productCode?->code ?? '—',
                'product_name'  => $b->productCode?->name ?? '—',
                'process_stage' => $b->process_stage ?? 'unknown',
                'status'        => $b->status ?? 'active',
                'weight_kg'     => (float) ($b->current_weight ?? 0),
                'created_at'    => $b->created_at?->timezone(self::TZ)->format('d/m/Y') ?? '—',
            ])
            ->toArray();
    }

    /**
     * Daftar batch yang SUDAH punya RFID tag (untuk monitoring).
     */
    public function getTaggedBatches(int $companyId): array
    {
        $query = Batch::with([
                'productCode',
                'checkpoints' => fn($q) => $q->latest('recorded_at')->limit(1)
            ])
            ->whereNotNull('rfid_tag')
            ->whereNotIn('status', ['exported', 'terminated']);

        // Fallback sama seperti getUntaggedBatches
        $withCompany = (clone $query)->where('company_id', $companyId);
        if ($withCompany->count() > 0) {
            $query = $withCompany;
        }

        return $query
            ->orderBy('updated_at', 'desc')
            ->limit(30)
            ->get()
            ->map(fn($b) => [
                'id'              => $b->id,
                'batch_code'      => $b->batch_code,
                'rfid_tag'        => $b->rfid_tag,
                'product_code'    => $b->productCode?->code ?? '—',
                'process_stage'   => $b->process_stage ?? 'unknown',
                'status'          => $b->status ?? 'active',
                'weight_kg'       => (float) ($b->current_weight ?? 0),
                'last_checkpoint' => $b->checkpoints->first() ? [
                    'stage'            => $b->checkpoints->first()->checkpoint_stage,
                    'recorded_at'      => $b->checkpoints->first()->recorded_at?->timezone(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                    'recorded_at_diff' => $b->checkpoints->first()->recorded_at?->diffForHumans(),
                ] : null,
                'updated_at'      => $b->updated_at?->timezone(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                'updated_at_diff' => $b->updated_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Riwayat aktivitas RFID terbaru (semua checkpoint yang via RFID scan).
     */
    public function getRecentRfidActivity(int $companyId): array
    {
        return \App\Models\Checkpoint::with(['batch.productCode'])
            ->whereHas('batch', function($q) use ($companyId) {
                // Fallback: jika tidak ada checkpoint untuk company ini, ambil semua
                $hasForCompany = \App\Models\Checkpoint::whereHas('batch',
                    fn($bq) => $bq->where('company_id', $companyId)
                )->whereNotNull('rfid_scan_code')->exists();

                if ($hasForCompany) {
                    $q->where('company_id', $companyId);
                }
            })
            ->whereNotNull('rfid_scan_code')
            ->latest('recorded_at')
            ->limit(20)
            ->get()
            ->map(fn($cp) => [
                'id'               => $cp->id,
                'batch_id'         => $cp->batch?->id,
                'batch_code'       => $cp->batch?->batch_code ?? '—',
                'product_code'     => $cp->batch?->productCode?->code ?? '—',
                'rfid_uid'         => $cp->rfid_scan_code,
                'action'           => $cp->checkpoint_data['action'] ?? $cp->checkpoint_stage,
                'stage'            => $cp->checkpoint_stage,
                'weight_kg'        => (float) ($cp->weight_recorded ?? 0),
                'recorded_at'      => $cp->recorded_at?->timezone(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                'recorded_at_diff' => $cp->recorded_at?->diffForHumans(),
            ])
            ->toArray();
    }

    // ═══════════════════════════════════════════════════════════════════
    // TOKEN VALIDATION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Validasi API token dari ESP32.
     * Jika RFID_API_TOKEN di .env kosong, semua request diterima (dev mode).
     */
    public function validateToken(?string $token): bool
    {
        $configured = config('app.rfid_api_token', '');

        $configured = trim((string) ($configured ?? ''));

        if ($configured === '') {
            return true;
        }

        return trim((string) ($token ?? '')) === $configured;
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /** Truncate string untuk tampilan LCD (max 16 karakter) */
    private function truncate(string $text, int $max = 16): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UID SANITIZATION — Handle berbagai format output RC522
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Sanitasi UID dari ESP32/RC522 — handle semua format yang mungkin.
     *
     * RC522 bisa kirim berbagai format:
     *   "A3B2C1D0"      → OK, uppercase hex tanpa spasi
     *   "a3b2c1d0"      → lowercase → uppercase
     *   "A3 B2 C1 D0"   → dengan spasi → hapus spasi
     *   "A3:B2:C1:D0"   → dengan colon → hapus colon
     *   " A3B2C1D0 "    → dengan whitespace → trim
     */
    private function sanitizeUid(string $uid): string
    {
        return strtoupper(
            str_replace([' ', ':', '-', '.'], '', trim($uid))
        );
    }
}