<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\CompanySetting;
use App\Models\MassBalanceThreshold;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RfidService — Business logic integrasi hardware RFID ESP32.
 *
 * ════════════════════════════════════════════════════════════════════════
 * FIX: Company ID Mismatch
 *
 *   SEBELUMNYA (bug):
 *     setPendingBatch(batchId, company_id=2) → Cache key: rfid_pending_batch_2
 *     ESP32 POST /api/rfid/register → resolveCompanyId() = 1 (DEFAULT)
 *     Cache::get('rfid_pending_batch_1') → NULL → GAGAL
 *
 *   SESUDAH (fix):
 *     setPendingBatch() → tulis ke DUA cache:
 *       rfid_pending_batch_2       (per-company)
 *       rfid_pending_batch_global  (fallback untuk ESP32/simulasi)
 *
 *     registerBatchTag(uid, company_id=1):
 *       1. Cek rfid_pending_batch_1 → NULL
 *       2. Fallback ke rfid_pending_batch_global → ADA
 *       3. Ambil company_id=2 dari data cache → berhasil
 *
 * ENUM RULE (TIDAK BOLEH DILANGGAR):
 *   checkpoint_stage HARUS pakai match() — JANGAN string concatenation
 * ════════════════════════════════════════════════════════════════════════
 */
class RfidService
{
    // Cache key per-company: rfid_pending_batch_{company_id}
    private const CACHE_PENDING_PREFIX = 'rfid_pending_batch_';

    // Cache key global: fallback untuk ESP32 yang tidak kirim company_id
    private const CACHE_PENDING_GLOBAL = 'rfid_pending_batch_global';

    private const CACHE_PENDING_TTL    = 90;   // detik
    private const TZ                   = 'Asia/Jakarta';

    // ═══════════════════════════════════════════════════════════════════
    // REGISTER — Link RFID tag ke batch (Lab Kimia)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Daftarkan UID ke batch yang sedang pending registrasi.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/register
     * ATAU oleh Simulasi via Vue (simulateRegister)
     *
     * FIX company_id mismatch:
     *   Cek per-company cache dulu → jika tidak ada → cek global cache
     *   Global cache berisi company_id yang benar dari session web operator
     */
    public function registerBatchTag(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cek UID sudah dipakai (cek semua company) ────────────────
        $existingBatch = Batch::where('rfid_tag', $uid)
            ->whereNull('deleted_at')
            ->first();

        if ($existingBatch) {
            Log::warning("RFID Register: UID {$uid} sudah dipakai batch {$existingBatch->batch_code}");
            return [
                'status'  => 'failed',
                'name'    => substr($existingBatch->batch_code, 0, 16),
                'message' => 'Tag sudah dipakai',
                'detail'  => "UID ini sudah digunakan oleh batch {$existingBatch->batch_code}.",
            ];
        }

        // ─── Cari pending batch — per-company dulu, fallback ke global ─
        $perCompanyKey = self::CACHE_PENDING_PREFIX . $companyId;
        $pendingData   = Cache::get($perCompanyKey);
        $usedGlobal    = false;

        if (!$pendingData) {
            // Fallback: cek global cache (set oleh web operator, mungkin company berbeda)
            $pendingData = Cache::get(self::CACHE_PENDING_GLOBAL);
            if ($pendingData) {
                $usedGlobal = true;
                // Gunakan company_id dari data yang disimpan operator web
                $companyId = (int) ($pendingData['company_id'] ?? $companyId);
                Log::info("RFID Register: Pakai global cache. company_id resolved={$companyId}");
            }
        }

        if (!$pendingData) {
            Log::info("RFID Register: Tidak ada pending batch (company={$companyId}, global=empty)");
            return [
                'status'  => 'failed',
                'name'    => 'Tidak Ada Sesi',
                'message' => 'Tidak ada sesi',
                'detail'  => 'Mulai sesi registrasi dari panel web MINETRA terlebih dahulu.',
            ];
        }

        // ─── Cari batch dengan company_id yang sudah resolved ─────────
        $batch = Batch::where('company_id', $companyId)
            ->find($pendingData['batch_id']);

        if (!$batch) {
            Cache::forget($perCompanyKey);
            Cache::forget(self::CACHE_PENDING_GLOBAL);
            return ['status' => 'failed', 'name' => 'Error', 'message' => 'Batch tidak ada'];
        }

        // ─── Link UID ke batch ─────────────────────────────────────────
        $batch->update([
            'rfid_tag' => $uid,
            'metadata' => array_merge($batch->metadata ?? [], [
                'rfid_registered_at' => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                'rfid_registered_by' => $pendingData['initiated_by'] ?? 'System',
            ]),
        ]);

        // ─── Catat checkpoint register ─────────────────────────────────
        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => 'lab_kimia_create',   // ← ENUM valid, match() pattern
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => true,
            'notes'            => "RFID Tag terdaftar: UID {$uid} — {$pendingData['initiated_by']}",
            'checkpoint_data'  => [
                'action'        => 'rfid_register',
                'rfid_uid'      => $uid,
                'initiated_by'  => $pendingData['initiated_by'] ?? 'System',
                'timestamp_wib' => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            ],
            'rfid_scan_code'   => $uid,
            'recorded_by'      => null,
            'recorded_at'      => now(),
        ]);

        // ─── Hapus KEDUA cache setelah berhasil ───────────────────────
        Cache::forget(self::CACHE_PENDING_PREFIX . $companyId);
        Cache::forget(self::CACHE_PENDING_GLOBAL);

        Log::info("RFID Register: Berhasil. UID={$uid} → Batch={$batch->batch_code} company_id={$companyId}");

        return [
            'status'     => 'success',
            'name'       => $this->truncate($batch->batch_code, 16),
            'message'    => 'Tag terdaftar!',
            'detail'     => "Batch {$batch->batch_code} ({$batch->productCode?->code}) berhasil ditag UID {$uid}.",
            'batch_id'   => $batch->id,
            'batch_code' => $batch->batch_code,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHECKPOINT IN — Scan karung saat MASUK gudang
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Catat checkpoint masuk.
     *
     * FIX: Jika batch tidak ditemukan di company tertentu,
     * cari secara global berdasarkan rfid_tag UID.
     * ESP32 tidak tahu company_id → cari berdasarkan UID saja.
     */
    public function checkpointIn(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cari batch: per-company dulu → fallback global ───────────
        $batch = Batch::with('productCode')
            ->where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$batch) {
            // Fallback: cari di semua company berdasarkan UID
            $batch = Batch::with('productCode')
                ->where('rfid_tag', $uid)
                ->whereNull('deleted_at')
                ->first();

            if ($batch) {
                Log::info("RFID IN: Batch ditemukan via global search. company_id={$batch->company_id} != requested={$companyId}");
            }
        }

        if (!$batch) {
            Log::warning("RFID IN: UID {$uid} tidak terdaftar pada batch manapun");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar. Lakukan registrasi terlebih dahulu.",
            ];
        }

        // ─── Tentukan checkpoint_stage ENUM — WAJIB pakai match() ─────
        $checkpointStage = match($batch->process_stage) {
            'lab_kimia'     => 'lab_kimia_create',
            'receiving'     => 'receiving_verify',
            'wet_process'   => 'wet_process_input',
            'dry_process'   => 'dry_process_input',
            'warehouse'     => 'warehouse_inbound',
            'project_plant' => 'project_plant_receive',
            default         => 'warehouse_inbound',
        };

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
                'action'        => 'rfid_checkpoint_in',
                'rfid_uid'      => $uid,
                'process_stage' => $batch->process_stage,
                'product_code'  => $batch->productCode?->code,
                'weight_kg'     => $batch->current_weight,
                'timestamp_wib' => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            ],
            'rfid_scan_code'   => $uid,
            'recorded_by'      => null,
            'recorded_at'      => now(),
        ]);

        Log::info("RFID IN: Batch {$batch->batch_code} (UID:{$uid}) masuk. Stage: {$batch->process_stage}");

        $timeWib = Carbon::now(self::TZ)->format('H:i:s');
        return [
            'status'     => 'success',
            'name'       => $this->truncate($batch->batch_code, 16),
            'message'    => 'Batch masuk OK',
            'detail'     => "Batch {$batch->batch_code} ({$batch->productCode?->code}) {$batch->current_weight}kg — CP IN pukul {$timeWib} WIB.",
            'batch_id'   => $batch->id,
            'batch_code' => $batch->batch_code,
            'product'    => $batch->productCode?->code,
            'weight_kg'  => $batch->current_weight,
            'stage'      => $batch->process_stage,
            'timestamp'  => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHECKPOINT OUT — Scan karung saat KELUAR gudang
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Catat checkpoint keluar.
     *
     * FIX: Sama dengan checkpointIn — global search fallback.
     */
    public function checkpointOut(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cari batch: per-company dulu → fallback global ───────────
        $batch = Batch::with('productCode')
            ->where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$batch) {
            $batch = Batch::with('productCode')
                ->where('rfid_tag', $uid)
                ->whereNull('deleted_at')
                ->first();

            if ($batch) {
                Log::info("RFID OUT: Batch ditemukan via global search. company_id={$batch->company_id}");
            }
        }

        if (!$batch) {
            Log::warning("RFID OUT: UID {$uid} tidak terdaftar");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar.",
            ];
        }

        // ─── Tentukan checkpoint_stage ENUM — WAJIB pakai match() ─────
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
                'action'        => 'rfid_checkpoint_out',
                'rfid_uid'      => $uid,
                'process_stage' => $batch->process_stage,
                'product_code'  => $batch->productCode?->code,
                'weight_kg'     => $batch->current_weight,
                'timestamp_wib' => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            ],
            'rfid_scan_code'   => $uid,
            'recorded_by'      => null,
            'recorded_at'      => now(),
        ]);

        Log::info("RFID OUT: Batch {$batch->batch_code} (UID:{$uid}) keluar. Stage: {$batch->process_stage}");

        $timeWib = Carbon::now(self::TZ)->format('H:i:s');
        return [
            'status'     => 'success',
            'name'       => $this->truncate($batch->batch_code, 16),
            'message'    => 'Batch keluar OK',
            'detail'     => "Batch {$batch->batch_code} keluar pukul {$timeWib} WIB. CP OUT dicatat.",
            'batch_id'   => $batch->id,
            'batch_code' => $batch->batch_code,
            'product'    => $batch->productCode?->code,
            'stage'      => $batch->process_stage,
            'timestamp'  => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // PENDING BATCH MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Set pending batch — tulis ke DUA cache key secara bersamaan.
     *
     * FIX company_id mismatch:
     *   rfid_pending_batch_{company_id} → untuk web UI yang tahu company_id
     *   rfid_pending_batch_global       → untuk ESP32/simulasi yang tidak tahu company_id
     */
    public function setPendingBatch(int $batchId, int $companyId, string $initiatedBy = 'System'): void
    {
        if ($companyId <= 0) {
            $companyId = 1;
            Log::warning("RfidService::setPendingBatch — company_id invalid, fallback ke 1");
        }

        $batch = Batch::where('company_id', $companyId)->findOrFail($batchId);

        $data = [
            'batch_id'     => $batchId,
            'batch_code'   => $batch->batch_code,
            'product_code' => $batch->productCode?->code,
            'initiated_by' => $initiatedBy,
            'company_id'   => $companyId,   // ← DISIMPAN agar ESP32 bisa resolve
            'started_at'   => now()->toISOString(),
        ];

        // Tulis ke per-company cache
        Cache::put(self::CACHE_PENDING_PREFIX . $companyId, $data, self::CACHE_PENDING_TTL);

        // JUGA tulis ke global cache — fallback untuk ESP32 & simulasi
        Cache::put(self::CACHE_PENDING_GLOBAL, $data, self::CACHE_PENDING_TTL);

        Log::info("RFID Pending SET: batch={$batch->batch_code} company_id={$companyId} oleh={$initiatedBy} (per-company + global)");
    }

    /**
     * Ambil status pending batch — cek per-company dulu, fallback global.
     */
    public function getPendingBatch(int $companyId): ?array
    {
        return Cache::get(self::CACHE_PENDING_PREFIX . $companyId)
            ?? Cache::get(self::CACHE_PENDING_GLOBAL);
    }

    /**
     * Batalkan pending batch — hapus KEDUA cache key.
     */
    public function cancelPendingBatch(int $companyId): void
    {
        Cache::forget(self::CACHE_PENDING_PREFIX . $companyId);
        Cache::forget(self::CACHE_PENDING_GLOBAL);
        Log::info("RFID Pending CANCELLED: company_id={$companyId}");
    }

    // ═══════════════════════════════════════════════════════════════════
    // ESP32 DEVICE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /** Simpan IP ESP32 ke CompanySetting */
    public function saveEsp32Ip(int $companyId, string $ip): void
    {
        $ip = rtrim(trim($ip), '/');
        if (!str_starts_with($ip, 'http')) {
            $ip = 'http://' . $ip;
        }

        \App\Models\CompanySetting::updateOrCreate(
            ['company_id' => $companyId, 'key' => 'esp32_ip'],
            ['value' => $ip]
        );

        Log::info("ESP32 IP disimpan: {$ip} untuk company_id={$companyId}");
    }

    /** Ambil IP ESP32 dari CompanySetting */
    public function getEsp32Ip(int $companyId): ?string
    {
        return \App\Models\CompanySetting::where('company_id', $companyId)
            ->where('key', 'esp32_ip')
            ->value('value');
    }

    /** Konfigurasi untuk ESP32 saat boot */
    public function getEspBootConfig(int $companyId): array
    {
        return [
            'api_base_url'      => config('app.url'),
            'endpoint_register' => '/api/rfid/register',
            'endpoint_login'    => '/api/rfid/login',
            'endpoint_logout'   => '/api/rfid/logout',
            'api_token'         => '',   // kosong = open mode
            'company_id'        => $companyId,
            'timeout_ms'        => 5000,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // BATCH QUERIES
    // ═══════════════════════════════════════════════════════════════════

    /** Batch belum ter-tag (untuk dropdown register) */
    public function getUntaggedBatches(int $companyId): array
    {
        $query = Batch::with('productCode')
            ->whereNull('rfid_tag')
            ->whereNotIn('status', ['exported', 'terminated']);

        $withCompany = (clone $query)->where('company_id', $companyId);
        if ($withCompany->count() > 0) {
            $query = $withCompany;
        } else {
            Log::warning("RfidService::getUntaggedBatches — company_id={$companyId} kosong, fallback semua");
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

    /** Batch sudah ter-tag (untuk monitoring) */
    public function getTaggedBatches(int $companyId): array
    {
        $query = Batch::with([
                'productCode',
                'checkpoints' => fn($q) => $q->latest('recorded_at')->limit(1),
            ])
            ->whereNotNull('rfid_tag')
            ->whereNotIn('status', ['exported', 'terminated']);

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

    /** Riwayat aktivitas RFID terbaru */
    public function getRecentRfidActivity(int $companyId): array
    {
        return \App\Models\Checkpoint::with(['batch.productCode'])
            ->whereHas('batch', function ($q) use ($companyId) {
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
     * Validasi token dari ESP32.
     * Kosong = open mode (terima semua).
     */
    public function validateToken(?string $token): bool
    {
        // getenv() bypass config cache — paling reliable di production Docker
        $configured = trim((string) (getenv('RFID_API_TOKEN') ?: ''));

        // Open mode: jika token tidak dikonfigurasi → terima semua
        if ($configured === '') {
            return true;
        }

        return trim((string) ($token ?? '')) === $configured;
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /** Truncate string untuk LCD 16 karakter */
    private function truncate(string $text, int $max = 16): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max);
    }

    /**
     * Sanitasi UID dari RC522 — handle semua format:
     *   "A3B2C1D0" | "a3b2c1d0" | "A3 B2 C1 D0" | "A3:B2:C1:D0"
     */
    private function sanitizeUid(string $uid): string
    {
        return strtoupper(str_replace([' ', ':', '-', '.'], '', trim($uid)));
    }
}