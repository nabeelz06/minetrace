<?php

namespace App\Services\Hardware;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\CompanySetting;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RfidService — Business logic integrasi hardware RFID ESP32.
 *
 * ════════════════════════════════════════════════════════════════════════
 * ARSITEKTUR RFID (Industri):
 *
 *   Operator set mode di web:
 *     POST /api/v1/company/rfid/set-mode { mode:'login', stage:'wet_process' }
 *     → Cache: rfid_active_mode_{company_id} = { mode, stage }
 *     → ESP32 LCD: "[ WET LOGIN ] / Tempel Tag..."
 *
 *   ESP32 scan karung:
 *     POST /api/rfid/login { uid:'A3B2C1D0' }
 *     → checkpointIn(uid, companyId, stage=null)
 *     → Baca stage dari cache → 'wet_process'
 *     → PINDAHKAN batch: process_stage='wet_process', status='in_process'
 *     → Catat checkpoint: 'wet_process_input'
 *     → Batch muncul di Wet Process page ✓
 *
 * STAGE FLOW:
 *   lab_kimia → receiving → wet_process → dry_process → warehouse
 *   → project_plant → warehouse → export
 *
 * ENUM RULE (WAJIB): match() PHP 8 untuk checkpoint_stage
 * ════════════════════════════════════════════════════════════════════════
 */
class RfidService
{
    // ─── Cache key constants ──────────────────────────────────────────────
    private const CACHE_PENDING_PREFIX = 'rfid_pending_batch_';
    private const CACHE_PENDING_GLOBAL = 'rfid_pending_batch_global';
    private const CACHE_ACTIVE_MODE    = 'rfid_active_mode_';

    private const CACHE_PENDING_TTL    = 90;    // detik
    private const CACHE_MODE_TTL       = 3600;  // 1 jam
    private const TZ                   = 'Asia/Jakarta';

    /**
     * Konfigurasi lengkap alur stage.
     *
     * Mendefinisikan:
     *   prev_stage      → stage asal batch yang dapat masuk ke stage ini
     *   in_checkpoint   → nilai ENUM checkpoint_stage saat LOGIN (masuk)
     *   out_checkpoint  → nilai ENUM checkpoint_stage saat LOGOUT (keluar)
     *   in_status       → status batch setelah masuk stage ini
     *   out_status      → status batch setelah keluar stage ini
     *   location_type   → tipe lokasi untuk auto-assign
     *   label / label_en→ nama untuk LCD dan UI
     *
     * SEMUA nilai harus sesuai ENUM di database/migrations/checkpoints_table.php
     */
    private const STAGE_FLOW = [
        'receiving' => [
            'prev_stage'    => 'lab_kimia',
            'in_checkpoint' => 'receiving_verify',
            'out_checkpoint'=> 'in_transit',
            'in_status'     => 'in_process',
            'out_status'    => 'in_transit',
            'location_type' => 'receiving',
            'label'         => 'Receiving Area',
            'label_en'      => 'Receiving Area',
        ],
        'wet_process' => [
            'prev_stage'    => 'receiving',
            'in_checkpoint' => 'wet_process_input',
            'out_checkpoint'=> 'wet_process_output',
            'in_status'     => 'in_process',
            'out_status'    => 'in_transit',
            'location_type' => 'wet_process',
            'label'         => 'Wet Process',
            'label_en'      => 'Wet Process',
        ],
        'dry_process' => [
            'prev_stage'    => 'wet_process',
            'in_checkpoint' => 'dry_process_input',
            'out_checkpoint'=> 'dry_process_output',
            'in_status'     => 'in_process',
            'out_status'    => 'in_transit',
            'location_type' => 'dry_process',
            'label'         => 'Dry Process',
            'label_en'      => 'Dry Process',
        ],
        'warehouse' => [
            'prev_stage'    => 'dry_process',
            'in_checkpoint' => 'warehouse_inbound',
            'out_checkpoint'=> 'warehouse_transfer',
            'in_status'     => 'in_warehouse',
            'out_status'    => 'in_transit',
            'location_type' => 'warehouse',
            'label'         => 'LTJ Warehouse',
            'label_en'      => 'LTJ Warehouse',
        ],
        'project_plant' => [
            'prev_stage'    => 'warehouse',
            'in_checkpoint' => 'project_plant_input', 
            'out_checkpoint'=> 'project_plant_output',
            'in_status'     => 'in_process',
            'out_status'    => 'in_transit',
            'location_type' => 'project_plant',
            'label'         => 'Lab Analisis LTJ',
            'label_en'      => 'LTJ Lab Analysis',
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════
    // REGISTER — Link RFID tag ke batch (Lab Kimia)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Daftarkan UID RFID tag ke batch yang sedang pending.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/register
     */
    public function registerBatchTag(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cek UID sudah dipakai (lintas company) ─────────────────────
        $existingBatch = Batch::where('rfid_tag', $uid)
            ->whereNull('deleted_at')
            ->first();

        if ($existingBatch) {
            Log::warning("RFID Register: UID {$uid} sudah dipakai batch {$existingBatch->batch_code}");
            return [
                'status'  => 'failed',
                'name'    => $this->truncate($existingBatch->batch_code, 16),
                'message' => 'Tag sudah dipakai',
                'detail'  => "UID ini sudah digunakan oleh batch {$existingBatch->batch_code}.",
            ];
        }

        // ─── Cari pending batch (per-company → fallback global) ──────────
        $perCompanyKey = self::CACHE_PENDING_PREFIX . $companyId;
        $pendingData   = Cache::get($perCompanyKey);
        $usedGlobal    = false;

        if (!$pendingData) {
            $pendingData = Cache::get(self::CACHE_PENDING_GLOBAL);
            if ($pendingData) {
                $usedGlobal = true;
                $companyId  = (int) ($pendingData['company_id'] ?? $companyId);
                Log::info("RFID Register: Global cache resolved company_id={$companyId}");
            }
        }

        if (!$pendingData) {
            Log::info("RFID Register: Tidak ada pending batch (company={$companyId})");
            return [
                'status'  => 'failed',
                'name'    => 'Tidak Ada Sesi',
                'message' => 'Tidak ada sesi',
                'detail'  => 'Mulai sesi registrasi dari panel web MINETRA terlebih dahulu.',
            ];
        }

        $batch = Batch::where('company_id', $companyId)->find($pendingData['batch_id']);

        if (!$batch) {
            Cache::forget($perCompanyKey);
            Cache::forget(self::CACHE_PENDING_GLOBAL);
            return ['status' => 'failed', 'name' => 'Error', 'message' => 'Batch tidak ada'];
        }

        return DB::transaction(function () use ($batch, $uid, $pendingData, $companyId, $perCompanyKey) {
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
                'checkpoint_stage' => 'lab_kimia_create',   // ENUM valid
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

            // ─── Hapus cache pending ───────────────────────────────────────
            Cache::forget($perCompanyKey);
            Cache::forget(self::CACHE_PENDING_GLOBAL);

            Log::info("RFID Register: UID={$uid} → Batch={$batch->batch_code} company_id={$companyId}");

            return [
                'status'     => 'success',
                'name'       => $this->truncate($batch->batch_code, 16),
                'message'    => 'Tag terdaftar!',
                'detail'     => "Batch {$batch->batch_code} ({$batch->productCode?->code}) berhasil ditag UID {$uid}.",
                'batch_id'   => $batch->id,
                'batch_code' => $batch->batch_code,
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHECKPOINT IN — Scan karung MASUK stage (auto-receive batch)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Proses scan RFID saat batch MASUK ke sebuah stage.
     *
     * FIX UTAMA: Metode ini sekarang MEMINDAHKAN batch ke stage tujuan,
     * bukan hanya mencatat checkpoint. Ini sama dengan ProcessController::receiveBatch().
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/login
     *
     * Flow:
     *   1. Cari batch berdasarkan rfid_tag UID
     *   2. Tentukan scan_stage dari: request param > cache active mode > batch stage
     *   3. Validasi batch dari prev_stage yang benar
     *   4. PINDAHKAN batch: process_stage=scan_stage, status=in_process
     *   5. Catat checkpoint IN dengan enum yang benar
     *
     * @param  string  $uid        UID dari kartu RFID
     * @param  int     $companyId  Company ID (default dari ESP32)
     * @param  string|null $scanStage Stage dimana ESP32 melakukan scan
     */
    public function checkpointIn(string $uid, int $companyId = 1, ?string $scanStage = null): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cari batch (per-company dulu → global fallback) ──────────────
        $batch = $this->findBatchByUid($uid, $companyId);

        if (!$batch) {
            Log::warning("RFID IN: UID {$uid} tidak terdaftar pada batch manapun");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar. Lakukan registrasi terlebih dahulu.",
            ];
        }

        // Gunakan company_id dari batch yang ditemukan (bisa berbeda jika global fallback)
        $resolvedCompanyId = $batch->company_id;

        // ─── Tentukan scan stage ───────────────────────────────────────────
        // Priority: 1. Parameter langsung, 2. Cache active mode, 3. Batch stage saat ini
        if (!$scanStage) {
            $activeMode = $this->getActiveMode($resolvedCompanyId);
            $scanStage  = $activeMode['stage'] ?? null;
        }

        if (!$scanStage || !isset(self::STAGE_FLOW[$scanStage])) {
            // Fallback: gunakan stage saat ini batch (re-scan / checkpoint tanpa pindah)
            $scanStage = $batch->process_stage;
        }

        $stageConfig  = self::STAGE_FLOW[$scanStage] ?? null;
        if (!$stageConfig) {
            return [
                'status'  => 'failed',
                'name'    => 'Stage Error',
                'message' => 'Stage tidak valid',
                'detail'  => "Stage '{$scanStage}' tidak dikenal dalam sistem.",
            ];
        }

        $prevStage    = $stageConfig['prev_stage'];
        $inCheckpoint = $stageConfig['in_checkpoint'];
        $inStatus     = $stageConfig['in_status'];
        $stageLabel   = $stageConfig['label'];

        // ─── Validasi posisi batch terhadap stage yang di-scan ────────────
        $batchCurrentStage = $batch->process_stage;

        if ($batchCurrentStage === $scanStage) {
            // Re-scan: batch sudah di stage ini → catat checkpoint tanpa pindah
            Log::info("RFID IN: Re-scan. Batch {$batch->batch_code} sudah di {$scanStage}");
            $shouldMove = false;
        } elseif ($prevStage && $batchCurrentStage === $prevStage) {
            // Batch di stage sebelumnya → auto-receive ke stage ini
            $shouldMove = true;
        } else {
            // Batch tidak di stage yang tepat
            $currentLabel  = self::STAGE_FLOW[$batchCurrentStage]['label'] ?? $batchCurrentStage;
            $prevLabel     = $prevStage ? (self::STAGE_FLOW[$prevStage]['label'] ?? $prevStage) : 'N/A';
            Log::warning("RFID IN: Batch {$batch->batch_code} stage tidak sesuai. Ada di '{$batchCurrentStage}', scan di '{$scanStage}' (butuh dari '{$prevStage}')");
            return [
                'status'  => 'failed',
                'name'    => $this->truncate($batch->batch_code, 16),
                'message' => 'Urutan salah',
                'detail'  => "Batch {$batch->batch_code} berada di {$currentLabel}. Untuk masuk {$stageLabel}, batch harus dari {$prevLabel} terlebih dahulu.",
            ];
        }

        return DB::transaction(function () use (
            $batch, $scanStage, $stageConfig, $inCheckpoint, $inStatus,
            $stageLabel, $uid, $resolvedCompanyId, $shouldMove
        ) {
            $timeWib = Carbon::now(self::TZ)->format('H:i:s');
            $timeDisplay = Carbon::now(self::TZ)->format('d/m/Y H:i:s');

            // ─── Pindahkan batch ke stage ini jika perlu ──────────────────
            if ($shouldMove) {
                // Cari lokasi default untuk stage tujuan
                $location = Location::where('company_id', $resolvedCompanyId)
                    ->where('location_type', $stageConfig['location_type'])
                    ->where('is_active', true)
                    ->first();

                $batch->update([
                    'process_stage'       => $scanStage,
                    'status'              => $inStatus,
                    'current_location_id' => $location?->id ?? $batch->current_location_id,
                ]);

                Log::info("RFID IN: Batch {$batch->batch_code} auto-dipindah → {$scanStage} (via RFID scan)");
            }

            // ─── Catat checkpoint IN ───────────────────────────────────────
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $batch->current_location_id,
                'checkpoint_stage' => $inCheckpoint,    // ENUM valid dari STAGE_FLOW
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => ($shouldMove
                    ? "Auto-terima via RFID di {$stageLabel}"
                    : "Re-scan RFID di {$stageLabel}"
                ) . " — {$timeWib} WIB",
                'checkpoint_data'  => [
                    'action'        => 'rfid_checkpoint_in',
                    'rfid_uid'      => $uid,
                    'scan_stage'    => $scanStage,
                    'auto_received' => $shouldMove,
                    'product_code'  => $batch->productCode?->code,
                    'weight_kg'     => $batch->current_weight,
                    'timestamp_wib' => $timeDisplay . ' WIB',
                ],
                'rfid_scan_code'   => $uid,
                'recorded_by'      => null,
                'recorded_at'      => now(),
            ]);

            $actionText = $shouldMove ? "Diterima di" : "Scan ulang di";
            $msgShort   = $shouldMove
                ? $this->truncate($stageLabel, 14) . ' OK'
                : 'Re-scan OK';

            return [
                'status'      => 'success',
                'name'        => $this->truncate($batch->batch_code, 16),
                'message'     => $msgShort,
                'detail'      => "{$actionText} {$stageLabel} pukul {$timeWib} WIB. Batch: {$batch->batch_code} ({$batch->productCode?->code}) {$batch->current_weight} kg.",
                'batch_id'    => $batch->id,
                'batch_code'  => $batch->batch_code,
                'product'     => $batch->productCode?->code,
                'weight_kg'   => $batch->current_weight,
                'scan_stage'  => $scanStage,
                'stage_label' => $stageLabel,
                'auto_moved'  => $shouldMove,
                'timestamp'   => $timeDisplay . ' WIB',
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHECKPOINT OUT — Scan karung KELUAR stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Proses scan RFID saat batch KELUAR dari sebuah stage.
     *
     * Dipanggil oleh ESP32 via POST /api/rfid/logout
     *
     * Flow:
     *   1. Cari batch berdasarkan rfid_tag UID
     *   2. Tentukan scan_stage dari: request param > cache active mode > batch stage
     *   3. Catat checkpoint OUT dengan enum yang benar
     *   4. Update status batch → 'in_transit' (siap ke stage berikutnya)
     *
     * @param  string  $uid        UID dari kartu RFID
     * @param  int     $companyId  Company ID
     * @param  string|null $scanStage Stage dimana scan keluar terjadi
     */
    public function checkpointOut(string $uid, int $companyId = 1, ?string $scanStage = null): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cari batch ────────────────────────────────────────────────────
        $batch = $this->findBatchByUid($uid, $companyId);

        if (!$batch) {
            Log::warning("RFID OUT: UID {$uid} tidak terdaftar");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar.",
            ];
        }

        $resolvedCompanyId = $batch->company_id;

        // ─── Tentukan scan stage ───────────────────────────────────────────
        if (!$scanStage) {
            $activeMode = $this->getActiveMode($resolvedCompanyId);
            $scanStage  = $activeMode['stage'] ?? $batch->process_stage;
        }

        if (!isset(self::STAGE_FLOW[$scanStage])) {
            $scanStage = $batch->process_stage; // Fallback ke stage saat ini batch
        }

        $stageConfig    = self::STAGE_FLOW[$scanStage];
        $outCheckpoint  = $stageConfig['out_checkpoint'];
        $stageLabel     = $stageConfig['label'];

        // ─── Cek konsistensi stage (warning jika tidak sesuai) ────────────
        if ($batch->process_stage !== $scanStage) {
            $batchLabel = self::STAGE_FLOW[$batch->process_stage]['label'] ?? $batch->process_stage;
            Log::warning("RFID OUT: Stage mismatch. Batch di '{$batch->process_stage}' ({$batchLabel}), scan keluar di '{$scanStage}' ({$stageLabel}). Lanjut catat.");
        }

        return DB::transaction(function () use ($batch, $scanStage, $outCheckpoint, $stageLabel, $uid, $resolvedCompanyId) {
            $timeWib     = Carbon::now(self::TZ)->format('H:i:s');
            $timeDisplay = Carbon::now(self::TZ)->format('d/m/Y H:i:s');

            // ─── Update status batch → in_transit ─────────────────────────
            $batch->update(['status' => 'in_transit']);

            // ─── Catat checkpoint OUT ──────────────────────────────────────
            Checkpoint::create([
                'batch_id'         => $batch->id,
                'location_id'      => $batch->current_location_id,
                'checkpoint_stage' => $outCheckpoint,   // ENUM valid dari STAGE_FLOW
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => "RFID Scan KELUAR dari {$stageLabel} — {$timeWib} WIB",
                'checkpoint_data'  => [
                    'action'        => 'rfid_checkpoint_out',
                    'rfid_uid'      => $uid,
                    'scan_stage'    => $scanStage,
                    'product_code'  => $batch->productCode?->code,
                    'weight_kg'     => $batch->current_weight,
                    'timestamp_wib' => $timeDisplay . ' WIB',
                ],
                'rfid_scan_code'   => $uid,
                'recorded_by'      => null,
                'recorded_at'      => now(),
            ]);

            Log::info("RFID OUT: Batch {$batch->batch_code} (UID:{$uid}) keluar dari {$scanStage}");

            $msgShort = $this->truncate("Keluar " . $stageLabel, 16);

            return [
                'status'      => 'success',
                'name'        => $this->truncate($batch->batch_code, 16),
                'message'     => $msgShort,
                'detail'      => "Batch {$batch->batch_code} ({$batch->productCode?->code}) keluar dari {$stageLabel} pukul {$timeWib} WIB. Status: Dalam Perjalanan.",
                'batch_id'    => $batch->id,
                'batch_code'  => $batch->batch_code,
                'product'     => $batch->productCode?->code,
                'weight_kg'   => $batch->current_weight,
                'scan_stage'  => $scanStage,
                'stage_label' => $stageLabel,
                'timestamp'   => $timeDisplay . ' WIB',
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // ACTIVE MODE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Set mode aktif ESP32 untuk company ini.
     *
     * Dipanggil dari web panel saat operator klik Login/Logout mode.
     * Stage yang dipilih di-cache sehingga saat ESP32 scan,
     * sistem tahu di stage mana scan terjadi.
     *
     * @param  string $mode       'login' | 'logout' | 'register' | 'idle'
     * @param  string $stage      Stage target (required untuk login/logout)
     * @param  int    $companyId  Company ID
     */
    public function setActiveMode(string $mode, string $stage, int $companyId): void
    {
        $stageLabel = self::STAGE_FLOW[$stage]['label'] ?? $stage;

        Cache::put(self::CACHE_ACTIVE_MODE . $companyId, [
            'mode'      => $mode,
            'stage'     => $stage,
            'label'     => $stageLabel,
            'set_at'    => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            'set_at_ts' => now()->toISOString(),
        ], self::CACHE_MODE_TTL);

        Log::info("RFID Active Mode SET: mode={$mode} stage={$stage} company_id={$companyId}");
    }

    /**
     * Ambil mode aktif ESP32 saat ini.
     */
    public function getActiveMode(int $companyId): ?array
    {
        return Cache::get(self::CACHE_ACTIVE_MODE . $companyId);
    }

    /**
     * Reset mode ke idle.
     */
    public function clearActiveMode(int $companyId): void
    {
        Cache::forget(self::CACHE_ACTIVE_MODE . $companyId);
        Log::info("RFID Active Mode CLEARED: company_id={$companyId}");
    }

    /**
     * Daftar stage yang tersedia untuk RFID tracking.
     */
    public function getAvailableStages(): array
    {
        return collect(self::STAGE_FLOW)->map(fn ($config, $stage) => [
            'stage'    => $stage,
            'label'    => $config['label'],
            'label_en' => $config['label_en'],
            'prev'     => $config['prev_stage'],
        ])->values()->toArray();
    }

    // ═══════════════════════════════════════════════════════════════════
    // PENDING BATCH MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Set pending batch — tulis ke DUA cache sekaligus (FIX company_id mismatch).
     *
     * rfid_pending_batch_{company_id} → untuk web UI yang tahu company
     * rfid_pending_batch_global       → untuk ESP32 yang tidak tahu company
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
            'company_id'   => $companyId,
            'started_at'   => now()->toISOString(),
        ];

        // Tulis ke PER-COMPANY cache
        Cache::put(self::CACHE_PENDING_PREFIX . $companyId, $data, self::CACHE_PENDING_TTL);
        // Tulis ke GLOBAL cache (fallback untuk ESP32)
        Cache::put(self::CACHE_PENDING_GLOBAL, $data, self::CACHE_PENDING_TTL);

        Log::info("RFID Pending SET: batch={$batch->batch_code} company_id={$companyId} oleh={$initiatedBy}");
    }

    /** Ambil pending batch (per-company → global fallback) */
    public function getPendingBatch(int $companyId): ?array
    {
        return Cache::get(self::CACHE_PENDING_PREFIX . $companyId)
            ?? Cache::get(self::CACHE_PENDING_GLOBAL);
    }

    /** Cancel pending batch — hapus KEDUA cache */
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

        CompanySetting::updateOrCreate(
            ['company_id' => $companyId, 'key' => 'esp32_ip'],
            ['value' => $ip]
        );

        Log::info("ESP32 IP disimpan: {$ip} untuk company_id={$companyId}");
    }

    /** Ambil IP ESP32 */
    public function getEsp32Ip(int $companyId): ?string
    {
        return CompanySetting::where('company_id', $companyId)
            ->where('key', 'esp32_ip')
            ->value('value');
    }

    /** Config untuk ESP32 saat boot */
    public function getEspBootConfig(int $companyId): array
    {
        return [
            'api_base_url'      => config('app.url'),
            'endpoint_register' => '/api/rfid/register',
            'endpoint_login'    => '/api/rfid/login',
            'endpoint_logout'   => '/api/rfid/logout',
            'api_token'         => '',  // Open mode
            'company_id'        => $companyId,
            'timeout_ms'        => 5000,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // BATCH QUERIES
    // ═══════════════════════════════════════════════════════════════════

    /** Batch belum ter-tag */
    public function getUntaggedBatches(int $companyId): array
    {
        $query = Batch::with('productCode')
            ->whereNull('rfid_tag')
            ->whereNotIn('status', ['exported', 'terminated']);

        $withCompany = (clone $query)->where('company_id', $companyId);
        if ($withCompany->count() > 0) {
            $query = $withCompany;
        } else {
            Log::warning("getUntaggedBatches: company_id={$companyId} kosong, fallback semua");
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($b) => [
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

    /** Batch sudah ter-tag */
    public function getTaggedBatches(int $companyId): array
    {
        $query = Batch::with([
                'productCode',
                'checkpoints' => fn ($q) => $q->latest('recorded_at')->limit(1),
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
            ->map(fn ($b) => [
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

    /** Log aktivitas RFID terbaru */
    public function getRecentRfidActivity(int $companyId): array
    {
        return Checkpoint::with(['batch.productCode'])
            ->whereHas('batch', function ($q) use ($companyId) {
                $hasData = Checkpoint::whereHas('batch', fn ($bq) => $bq->where('company_id', $companyId))
                    ->whereNotNull('rfid_scan_code')->exists();
                if ($hasData) {
                    $q->where('company_id', $companyId);
                }
            })
            ->whereNotNull('rfid_scan_code')
            ->latest('recorded_at')
            ->limit(20)
            ->get()
            ->map(fn ($cp) => [
                'id'               => $cp->id,
                'batch_id'         => $cp->batch?->id,
                'batch_code'       => $cp->batch?->batch_code ?? '—',
                'product_code'     => $cp->batch?->productCode?->code ?? '—',
                'rfid_uid'         => $cp->rfid_scan_code,
                'action'           => $cp->checkpoint_data['action'] ?? $cp->checkpoint_stage,
                'stage'            => $cp->checkpoint_stage,
                'scan_stage'       => $cp->checkpoint_data['scan_stage'] ?? null,
                'auto_moved'       => $cp->checkpoint_data['auto_received'] ?? false,
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
     * Validasi token dari ESP32 menggunakan getenv() langsung.
     * Kosong = open mode (terima semua request).
     */
    public function validateToken(?string $token): bool
    {
        $configured = trim((string) (getenv('RFID_API_TOKEN') ?: ''));

        if ($configured === '') {
            return true; // Open mode
        }

        return trim((string) ($token ?? '')) === $configured;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cari batch berdasarkan rfid_tag UID.
     * Per-company dulu → fallback global (fix company_id mismatch).
     */
    private function findBatchByUid(string $uid, int $companyId): ?Batch
    {
        $batch = Batch::with(['productCode', 'currentLocation'])
            ->where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$batch) {
            // Global fallback — berguna saat company_id ESP32 ≠ company_id user
            $batch = Batch::with(['productCode', 'currentLocation'])
                ->where('rfid_tag', $uid)
                ->whereNull('deleted_at')
                ->first();

            if ($batch) {
                Log::info("RFID: Batch ditemukan via global search. company_id={$batch->company_id} (requested={$companyId})");
            }
        }

        return $batch;
    }

    /** Truncate string untuk tampilan LCD (max 16 karakter) */
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