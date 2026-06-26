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
 * ARSITEKTUR RFID — MODE MANUAL (Industri Real)
 *
 * ┌─────────────────────────────────────────────────────────────────┐
 * │  PRINSIP: RFID = AUDIT TRAIL, bukan pemindah stage otomatis    │
 * │                                                                 │
 * │  ESP32 scan → Catat log saja (checkpoint_data + timestamp)     │
 * │  Operator web → Yang memindahkan stage secara formal           │
 * │                                                                 │
 * │  Alasan industri:                                               │
 * │  - Verifikasi fisik tetap dilakukan manusia                    │
 * │  - Tidak ada false-positive dari tag yang terbaca dari jauh    │
 * │  - Operator bertanggung jawab atas setiap perpindahan stage    │
 * │  - Audit trail RFID dan stage transition tercatat terpisah     │
 * └─────────────────────────────────────────────────────────────────┘
 *
 * ALUR KERJA:
 *
 *   1. Operator set mode di web panel:
 *      POST /api/v1/company/rfid/set-mode { mode:'scan_in', stage:'wet_process' }
 *      → Cache: rfid_active_mode_{company_id} = { mode, stage }
 *      → ESP32 LCD menampilkan: "[ WET SCAN IN ] / Tempel Tag..."
 *
 *   2. ESP32 scan karung → hanya CATAT LOG:
 *      POST /api/rfid/login { uid:'A3B2C1D0' }
 *      → checkpointIn(uid, companyId, stage) → Checkpoint::create()
 *      → TIDAK ada batch->update(process_stage) ← PERUBAHAN UTAMA
 *      → Response LCD: "Scan IN OK / B-20260615-001"
 *
 *   3. Operator lihat log di web → konfirmasi penerimaan via:
 *      ProcessController::receiveBatch() → batch resmi pindah stage
 *
 * STAGE FLOW: Mendefinisikan ENUM checkpoint_stage valid per stage.
 * ════════════════════════════════════════════════════════════════════════
 */
class RfidService
{
    // ─── Cache key constants ──────────────────────────────────────────────
    private const CACHE_PENDING_PREFIX = 'rfid_pending_batch_';
    private const CACHE_PENDING_GLOBAL = 'rfid_pending_batch_global';
    private const CACHE_ACTIVE_MODE    = 'rfid_active_mode_';

    private const CACHE_PENDING_TTL = 90;    // detik
    private const CACHE_MODE_TTL   = 3600;   // 1 jam
    private const TZ               = 'Asia/Jakarta';

    /**
     * Konfigurasi stage untuk menentukan ENUM checkpoint_stage yang valid.
     *
     * CATATAN: in_checkpoint dan out_checkpoint HANYA digunakan untuk
     * mencatat audit trail RFID scan. TIDAK ada perubahan process_stage
     * atau status batch yang dilakukan di sini.
     *
     * Perpindahan stage tetap melalui ProcessController (web panel).
     */
    private const STAGE_FLOW = [
        'receiving' => [
            'prev_stage'     => 'lab_kimia',
            'in_checkpoint'  => 'receiving_verify',
            'out_checkpoint' => 'in_transit',
            'location_type'  => 'receiving',
            'label'          => 'Receiving Area',
            'label_en'       => 'Receiving Area',
        ],
        'wet_process' => [
            'prev_stage'     => 'receiving',
            'in_checkpoint'  => 'wet_process_input',
            'out_checkpoint' => 'wet_process_output',
            'location_type'  => 'wet_process',
            'label'          => 'Wet Process',
            'label_en'       => 'Wet Process',
        ],
        'dry_process' => [
            'prev_stage'     => 'wet_process',
            'in_checkpoint'  => 'dry_process_input',
            'out_checkpoint' => 'dry_process_output',
            'location_type'  => 'dry_process',
            'label'          => 'Dry Process',
            'label_en'       => 'Dry Process',
        ],
        'warehouse' => [
            'prev_stage'     => 'dry_process',
            'in_checkpoint'  => 'warehouse_inbound',
            'out_checkpoint' => 'warehouse_transfer',
            'location_type'  => 'warehouse',
            'label'          => 'LTJ Warehouse',
            'label_en'       => 'LTJ Warehouse',
        ],
        'project_plant' => [
            'prev_stage'     => 'warehouse',
            'in_checkpoint'  => 'project_plant_input',
            'out_checkpoint' => 'project_plant_output',
            'location_type'  => 'project_plant',
            'label'          => 'Lab Analisis LTJ',
            'label_en'       => 'LTJ Lab Analysis',
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════
    // REGISTER — Link RFID tag ke batch (Lab Kimia)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Daftarkan UID RFID tag ke batch yang sedang pending.
     * Dipanggil ESP32 via POST /api/rfid/register
     */
    public function registerBatchTag(string $uid, int $companyId = 1): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cek UID sudah dipakai ─────────────────────────────────────────
        $existingBatch = Batch::where('rfid_tag', $uid)->whereNull('deleted_at')->first();

        if ($existingBatch) {
            Log::warning("RFID Register: UID {$uid} sudah dipakai batch {$existingBatch->batch_code}");
            return [
                'status'  => 'failed',
                'name'    => $this->truncate($existingBatch->batch_code, 16),
                'message' => 'Tag sudah dipakai',
                'detail'  => "UID ini sudah digunakan oleh batch {$existingBatch->batch_code}.",
            ];
        }

        // ─── Cari pending batch (per-company → global fallback) ────────────
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
                'checkpoint_stage' => 'lab_kimia_create',
                'weight_recorded'  => $batch->current_weight,
                'weight_previous'  => $batch->current_weight,
                'weight_loss'      => 0,
                'weight_loss_pct'  => 0,
                'has_discrepancy'  => false,
                'is_verified'      => true,
                'notes'            => "RFID Tag terdaftar: UID {$uid} oleh {$pendingData['initiated_by']}",
                'checkpoint_data'  => [
                    'action'        => 'rfid_register',
                    'rfid_uid'      => $uid,
                    'initiated_by'  => $pendingData['initiated_by'] ?? 'System',
                    'timestamp_wib' => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                ],
                'rfid_scan_code' => $uid,
                'recorded_by'    => null,
                'recorded_at'    => now(),
            ]);

            Cache::forget($perCompanyKey);
            Cache::forget(self::CACHE_PENDING_GLOBAL);

            Log::info("RFID Register: UID={$uid} → Batch={$batch->batch_code}");

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
    // SCAN IN — Rekam audit trail karung MASUK area (MANUAL)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Proses scan RFID saat karung/batch memasuki area tertentu.
     *
     * ══════════════════════════════════════════════════════
     * MODE MANUAL: Metode ini HANYA mencatat log audit.
     * TIDAK ada perubahan process_stage atau status batch.
     *
     * Perpindahan stage resmi dilakukan oleh operator via
     * web panel (ProcessController::receiveBatch()).
     * ══════════════════════════════════════════════════════
     *
     * Dipanggil ESP32 via POST /api/rfid/login
     *
     * @param  string      $uid        UID dari kartu RFID
     * @param  int         $companyId  Company ID
     * @param  string|null $scanStage  Stage dimana scan IN terjadi
     */
    public function checkpointIn(string $uid, int $companyId = 1, ?string $scanStage = null): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cari batch (per-company → global fallback) ───────────────────
        $batch = $this->findBatchByUid($uid, $companyId);

        if (!$batch) {
            Log::warning("RFID SCAN IN: UID {$uid} tidak terdaftar pada batch manapun");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar. Lakukan registrasi terlebih dahulu.",
            ];
        }

        $resolvedCompanyId = $batch->company_id;

        // ─── Tentukan stage konteks dari cache atau request ────────────────
        if (!$scanStage) {
            $activeMode = $this->getActiveMode($resolvedCompanyId);
            $scanStage  = $activeMode['stage'] ?? $batch->process_stage;
        }

        // Fallback ke stage saat ini batch jika stage tidak valid
        if (!isset(self::STAGE_FLOW[$scanStage])) {
            $scanStage = $batch->process_stage;
        }

        $stageConfig  = self::STAGE_FLOW[$scanStage] ?? null;
        $inCheckpoint = $stageConfig['in_checkpoint'] ?? 'in_transit';
        $stageLabel   = $stageConfig['label'] ?? $scanStage;

        // ─── Catat scan IN sebagai audit trail ────────────────────────────
        // TIDAK ada batch->update() di sini
        // Perpindahan stage hanya via ProcessController (web panel)
        $timeWib     = Carbon::now(self::TZ)->format('H:i:s');
        $timeDisplay = Carbon::now(self::TZ)->format('d/m/Y H:i:s');

        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => $inCheckpoint,   // ENUM valid dari STAGE_FLOW
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => false,  // Belum diverifikasi — menunggu konfirmasi manual
            'notes'            => "📡 RFID Scan IN di {$stageLabel} — {$timeWib} WIB (menunggu konfirmasi operator)",
            'checkpoint_data'  => [
                'action'              => 'rfid_scan_in',
                'rfid_uid'            => $uid,
                'scan_stage'          => $scanStage,
                'batch_stage_current' => $batch->process_stage,
                'batch_status'        => $batch->status,
                'product_code'        => $batch->productCode?->code,
                'weight_kg'           => $batch->current_weight,
                'note'                => 'Audit trail RFID. Konfirmasi penerimaan dilakukan via web panel.',
                'timestamp_wib'       => $timeDisplay . ' WIB',
            ],
            'rfid_scan_code' => $uid,
            'recorded_by'    => null,
            'recorded_at'    => now(),
        ]);

        Log::info("RFID SCAN IN (audit only): Batch={$batch->batch_code} UID={$uid} area={$scanStage}");

        return [
            'status'      => 'success',
            'name'        => $this->truncate($batch->batch_code, 16),
            'message'     => 'Scan IN OK',
            'detail'      => "Scan IN tercatat untuk {$batch->batch_code} ({$batch->productCode?->code}) di {$stageLabel} pukul {$timeWib} WIB. Operator konfirmasi via web.",
            'batch_id'    => $batch->id,
            'batch_code'  => $batch->batch_code,
            'product'     => $batch->productCode?->code,
            'weight_kg'   => $batch->current_weight,
            'scan_stage'  => $scanStage,
            'stage_label' => $stageLabel,
            'timestamp'   => $timeDisplay . ' WIB',
            // Manual mode indicator — digunakan UI untuk info banner
            'manual_mode' => true,
            'action_required' => "Konfirmasi penerimaan di web panel stage {$stageLabel}",
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // SCAN OUT — Rekam audit trail karung KELUAR area (MANUAL)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Proses scan RFID saat karung/batch meninggalkan area tertentu.
     *
     * ══════════════════════════════════════════════════════
     * MODE MANUAL: Metode ini HANYA mencatat log audit.
     * TIDAK ada perubahan status batch → 'in_transit'.
     *
     * Status batch hanya berubah saat operator web klik
     * "Kirim ke Stage Berikutnya" (ProcessController::forwardBatch()).
     * ══════════════════════════════════════════════════════
     *
     * Dipanggil ESP32 via POST /api/rfid/logout
     *
     * @param  string      $uid        UID dari kartu RFID
     * @param  int         $companyId  Company ID
     * @param  string|null $scanStage  Stage dimana scan OUT terjadi
     */
    public function checkpointOut(string $uid, int $companyId = 1, ?string $scanStage = null): array
    {
        $uid = $this->sanitizeUid($uid);

        // ─── Cari batch ────────────────────────────────────────────────────
        $batch = $this->findBatchByUid($uid, $companyId);

        if (!$batch) {
            Log::warning("RFID SCAN OUT: UID {$uid} tidak terdaftar");
            return [
                'status'  => 'failed',
                'name'    => 'Tag Asing',
                'message' => 'Tag tidak dikenal',
                'detail'  => "UID {$uid} tidak terdaftar.",
            ];
        }

        $resolvedCompanyId = $batch->company_id;

        // ─── Tentukan stage konteks ────────────────────────────────────────
        if (!$scanStage) {
            $activeMode = $this->getActiveMode($resolvedCompanyId);
            $scanStage  = $activeMode['stage'] ?? $batch->process_stage;
        }

        if (!isset(self::STAGE_FLOW[$scanStage])) {
            $scanStage = $batch->process_stage;
        }

        $stageConfig   = self::STAGE_FLOW[$scanStage];
        $outCheckpoint = $stageConfig['out_checkpoint'];
        $stageLabel    = $stageConfig['label'];

        // ─── Catat scan OUT sebagai audit trail ───────────────────────────
        // TIDAK ada batch->update(['status' => 'in_transit']) di sini
        // Status batch hanya berubah via ProcessController (web panel)
        $timeWib     = Carbon::now(self::TZ)->format('H:i:s');
        $timeDisplay = Carbon::now(self::TZ)->format('d/m/Y H:i:s');

        Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => $outCheckpoint,   // ENUM valid dari STAGE_FLOW
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => false,  // Belum dikonfirmasi — menunggu operator
            'notes'            => "📡 RFID Scan OUT dari {$stageLabel} — {$timeWib} WIB (menunggu konfirmasi operator)",
            'checkpoint_data'  => [
                'action'              => 'rfid_scan_out',
                'rfid_uid'            => $uid,
                'scan_stage'          => $scanStage,
                'batch_stage_current' => $batch->process_stage,
                'batch_status'        => $batch->status,
                'product_code'        => $batch->productCode?->code,
                'weight_kg'           => $batch->current_weight,
                'note'                => 'Audit trail RFID. Pengiriman resmi dilakukan via web panel.',
                'timestamp_wib'       => $timeDisplay . ' WIB',
            ],
            'rfid_scan_code' => $uid,
            'recorded_by'    => null,
            'recorded_at'    => now(),
        ]);

        Log::info("RFID SCAN OUT (audit only): Batch={$batch->batch_code} UID={$uid} area={$scanStage}");

        return [
            'status'      => 'success',
            'name'        => $this->truncate($batch->batch_code, 16),
            'message'     => 'Scan OUT OK',
            'detail'      => "Scan OUT tercatat untuk {$batch->batch_code} ({$batch->productCode?->code}) keluar {$stageLabel} pukul {$timeWib} WIB. Kirim ke stage berikutnya via web.",
            'batch_id'    => $batch->id,
            'batch_code'  => $batch->batch_code,
            'product'     => $batch->productCode?->code,
            'weight_kg'   => $batch->current_weight,
            'scan_stage'  => $scanStage,
            'stage_label' => $stageLabel,
            'timestamp'   => $timeDisplay . ' WIB',
            'manual_mode' => true,
            'action_required' => "Kirim batch ke stage berikutnya via web panel",
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // ACTIVE MODE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Set mode aktif ESP32 untuk company ini.
     *
     * Mode digunakan untuk:
     * 1. Menampilkan label yang benar di LCD ESP32
     * 2. Menentukan checkpoint_stage ENUM yang digunakan saat scan
     *
     * Mode TIDAK lagi menentukan apakah batch berpindah stage.
     *
     * @param  string $mode       'scan_in' | 'scan_out' | 'register' | 'idle'
     * @param  string $stage      Stage konteks scan
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

    /** Ambil mode aktif saat ini */
    public function getActiveMode(int $companyId): ?array
    {
        return Cache::get(self::CACHE_ACTIVE_MODE . $companyId);
    }

    /** Reset mode ke idle */
    public function clearActiveMode(int $companyId): void
    {
        Cache::forget(self::CACHE_ACTIVE_MODE . $companyId);
        Log::info("RFID Active Mode CLEARED: company_id={$companyId}");
    }

    /** Daftar stage yang tersedia untuk dropdown UI */
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
    // PENDING BATCH — Register tag baru ke batch
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Set pending batch — tulis ke DUA cache sekaligus (fix company_id mismatch).
     *
     * rfid_pending_batch_{company_id} → untuk web UI
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

        Cache::put(self::CACHE_PENDING_PREFIX . $companyId, $data, self::CACHE_PENDING_TTL);
        Cache::put(self::CACHE_PENDING_GLOBAL, $data, self::CACHE_PENDING_TTL);

        Log::info("RFID Pending SET: batch={$batch->batch_code} company_id={$companyId} oleh={$initiatedBy}");
    }

    /** Ambil pending batch (per-company → global fallback) */
    public function getPendingBatch(int $companyId): ?array
    {
        return Cache::get(self::CACHE_PENDING_PREFIX . $companyId)
            ?? Cache::get(self::CACHE_PENDING_GLOBAL);
    }

    /** Cancel pending batch */
    public function cancelPendingBatch(int $companyId): void
    {
        Cache::forget(self::CACHE_PENDING_PREFIX . $companyId);
        Cache::forget(self::CACHE_PENDING_GLOBAL);
        Log::info("RFID Pending CANCELLED: company_id={$companyId}");
    }

    // ═══════════════════════════════════════════════════════════════════
    // ESP32 DEVICE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /** Simpan IP ESP32 */
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
            'endpoint_scan_in'  => '/api/rfid/login',    // endpoint sama, semantik berubah
            'endpoint_scan_out' => '/api/rfid/logout',   // endpoint sama, semantik berubah
            'api_token'         => '',
            'company_id'        => $companyId,
            'timeout_ms'        => 5000,
            'mode'              => 'manual',              // informasikan ke ESP32
            'firmware_note'     => 'Scan = audit trail only. Stage transition via web.',
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
                    'is_rfid'          => !is_null($b->checkpoints->first()->rfid_scan_code),
                    'recorded_at'      => $b->checkpoints->first()->recorded_at?->timezone(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                    'recorded_at_diff' => $b->checkpoints->first()->recorded_at?->diffForHumans(),
                ] : null,
                'updated_at'      => $b->updated_at?->timezone(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
                'updated_at_diff' => $b->updated_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Log aktivitas RFID terbaru.
     * Menampilkan semua scan IN/OUT sebagai audit trail.
     */
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
                'is_verified'      => $cp->is_verified,
                'has_snapshot'     => $cp->checkpoint_data['snapshot_captured'] ?? false,
                'snapshot_at'      => $cp->checkpoint_data['snapshot_at'] ?? null,
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
     * Validasi token dari ESP32 menggunakan getenv() langsung
     * (bypass config cache Laravel).
     * Kosong = open mode.
     */
    public function validateToken(?string $token): bool
    {
        $configured = trim((string) (getenv('RFID_API_TOKEN') ?: ''));
        if ($configured === '') return true;
        return trim((string) ($token ?? '')) === $configured;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cari batch berdasarkan rfid_tag UID.
     * Per-company dulu → global fallback (fix company_id mismatch ESP32).
     */
    private function findBatchByUid(string $uid, int $companyId): ?Batch
    {
        $batch = Batch::with(['productCode', 'currentLocation'])
            ->where('rfid_tag', $uid)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$batch) {
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

    /** Truncate string untuk tampilan LCD 16×2 */
    private function truncate(string $text, int $max = 16): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max);
    }

    /**
     * Sanitasi UID dari RC522.
     * Handle: "A3B2C1D0" | "a3b2c1d0" | "A3 B2 C1 D0" | "A3:B2:C1:D0"
     */
    private function sanitizeUid(string $uid): string
    {
        return strtoupper(str_replace([' ', ':', '-', '.'], '', trim($uid)));
    }
}