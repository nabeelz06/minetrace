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
 * RfidService — logika bisnis scan RFID (pencatatan checkpoint, mode aktif, daftar batch/tag).
 *
 * ════════════════════════════════════════════════════════════════════════
 * PERAN SCAN bergantung pada MODE OTORISASI per tahap (RfidAuthorizationService):
 *   audit_only        (default) : scan hanya mencatat checkpoint; perpindahan tahap lewat web.
 *   scan_to_receive             : Scan IN juga memicu penerimaan batch (dengan guard tahap).
 *   scan_to_dispatch            : Scan OUT juga memicu pengiriman batch (tandai in_transit).
 * Checkpoint scan selalu dicatat lebih dulu; transisi hanya bila guard terpenuhi.
 *
 * ORKESTRASI (identitas perangkat, dedupe/idempotensi, lock, snapshot CCTV) ada di
 * RfidScanService. Registrasi tag ada di RfidRegistrationService. Kelas ini TIDAK
 * memutuskan tenant: pemanggil wajib menyerahkan company_id yang sudah tervalidasi.
 *
 * "Mode aktif" (scan_in/scan_out + tahap) di cache hanya dipakai perangkat legacy yang
 * tidak terdaftar; reader terdaftar membawa read point tetap (tahap + arah) sendiri.
 * ════════════════════════════════════════════════════════════════════════
 */
class RfidService
{
    // ─── Cache key constants ──────────────────────────────────────────────
    private const CACHE_ACTIVE_MODE    = 'rfid_active_mode_';

    private const CACHE_MODE_TTL   = 3600;   // 1 jam
    private const TZ               = 'Asia/Jakarta';

    private function modeKey(int $companyId, string $stage): string
    {
        return self::CACHE_ACTIVE_MODE . $companyId . '_' . $stage;
    }

    private function stagesTrackerKey(int $companyId): string
    {
        return self::CACHE_ACTIVE_MODE . $companyId . '_stages';
    }

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
    public function checkpointIn(string $uid, int $companyId, ?string $scanStage = null, string $rfidOrigin = 'real_esp32', array $meta = []): array
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

        $checkpoint = Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => $inCheckpoint,
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => false,
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
            'rfid_scan_code'    => $uid,
            'rfid_origin'       => $rfidOrigin,
            'event_id'          => $meta['event_id'] ?? null,
            'reader_id'         => $meta['reader_id'] ?? null,
            'scan_stage'        => $scanStage,
            'scan_direction'    => 'in',
            'capture_attempted' => false,
            'capture_succeeded' => false,
            'recorded_by'       => null,
            'recorded_at'       => now(),
        ]);

        Log::info("RFID SCAN IN (audit only): Batch={$batch->batch_code} UID={$uid} area={$scanStage}");

        return [
            'status'      => 'success',
            'name'        => $this->truncate($batch->batch_code, 16),
            'message'     => 'Scan IN OK',
            'detail'      => "Scan IN tercatat untuk {$batch->batch_code} ({$batch->productCode?->code}) di {$stageLabel} pukul {$timeWib} WIB. Operator konfirmasi via web.",
            'batch_id'    => $batch->id,
            'batch_code'  => $batch->batch_code,
            'company_id'  => $resolvedCompanyId,
            'product'     => $batch->productCode?->code,
            'weight_kg'   => $batch->current_weight,
            'scan_stage'  => $scanStage,
            'stage_label' => $stageLabel,
            'timestamp'   => $timeDisplay . ' WIB',
            '_checkpoint_id' => $checkpoint->id,   // internal: dibuang RfidScanService sebelum respons
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
    public function checkpointOut(string $uid, int $companyId, ?string $scanStage = null, string $rfidOrigin = 'real_esp32', array $meta = []): array
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

        $stageConfig   = self::STAGE_FLOW[$scanStage] ?? null;
        $outCheckpoint = $stageConfig['out_checkpoint'] ?? 'in_transit';   // tahap di luar STAGE_FLOW (mis. lab_kimia)
        $stageLabel    = $stageConfig['label'] ?? $scanStage;

        // ─── Catat scan OUT sebagai audit trail ───────────────────────────
        // TIDAK ada batch->update(['status' => 'in_transit']) di sini
        // Status batch hanya berubah via ProcessController (web panel)
        $timeWib     = Carbon::now(self::TZ)->format('H:i:s');
        $timeDisplay = Carbon::now(self::TZ)->format('d/m/Y H:i:s');

        $checkpoint = Checkpoint::create([
            'batch_id'         => $batch->id,
            'location_id'      => $batch->current_location_id,
            'checkpoint_stage' => $outCheckpoint,
            'weight_recorded'  => $batch->current_weight,
            'weight_previous'  => $batch->current_weight,
            'weight_loss'      => 0,
            'weight_loss_pct'  => 0,
            'has_discrepancy'  => false,
            'is_verified'      => false,
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
            'rfid_scan_code'    => $uid,
            'rfid_origin'       => $rfidOrigin,
            'event_id'          => $meta['event_id'] ?? null,
            'reader_id'         => $meta['reader_id'] ?? null,
            'scan_stage'        => $scanStage,
            'scan_direction'    => 'out',
            'capture_attempted' => false,
            'capture_succeeded' => false,
            'recorded_by'       => null,
            'recorded_at'       => now(),
        ]);

        Log::info("RFID SCAN OUT (audit only): Batch={$batch->batch_code} UID={$uid} area={$scanStage}");

        return [
            'status'      => 'success',
            'name'        => $this->truncate($batch->batch_code, 16),
            'message'     => 'Scan OUT OK',
            'detail'      => "Scan OUT tercatat untuk {$batch->batch_code} ({$batch->productCode?->code}) keluar {$stageLabel} pukul {$timeWib} WIB. Kirim ke stage berikutnya via web.",
            'batch_id'    => $batch->id,
            'batch_code'  => $batch->batch_code,
            'company_id'  => $resolvedCompanyId,
            'product'     => $batch->productCode?->code,
            'weight_kg'   => $batch->current_weight,
            'scan_stage'  => $scanStage,
            'stage_label' => $stageLabel,
            'timestamp'   => $timeDisplay . ' WIB',
            '_checkpoint_id' => $checkpoint->id,
            'manual_mode' => true,
            'action_required' => "Kirim batch ke stage berikutnya via web panel",
        ];
    }

    /**
     * Tentukan stage konteks scan — dari parameter request, lalu cache mode
     * aktif, lalu fallback ke process_stage batch saat ini jika tidak valid.
     *
     * Logika IDENTIK dengan yang dulu duplikat inline di checkpointIn() dan
     * checkpointOut() — diekstrak jadi method publik agar bisa dipakai
     * ApiRfidController SEBELUM mendelegasikan ke ScanAuthorizationStrategy
     * (strategy butuh tahu stage konteks yang sudah final sebelum
     * memvalidasi process_stage batch terhadap PREV_STAGE/NEXT_STAGE-nya).
     */
    public function resolveScanStage(Batch $batch, ?string $requestedStage): string
    {
        $scanStage = $requestedStage;

        if (!$scanStage) {
            $activeMode = $this->getActiveMode($batch->company_id);
            $scanStage  = $activeMode['stage'] ?? $batch->process_stage;
        }

        if (!isset(self::STAGE_FLOW[$scanStage])) {
            $scanStage = $batch->process_stage;
        }

        return $scanStage;
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

        Cache::put($this->modeKey($companyId, $stage), [
            'mode'      => $mode,
            'stage'     => $stage,
            'label'     => $stageLabel,
            'set_at'    => Carbon::now(self::TZ)->format('d/m/Y H:i:s') . ' WIB',
            'set_at_ts' => now()->toISOString(),
        ], self::CACHE_MODE_TTL);

        $trackerKey   = $this->stagesTrackerKey($companyId);
        $activeStages = Cache::get($trackerKey, []);
        if (!in_array($stage, $activeStages, true)) {
            $activeStages[] = $stage;
            Cache::put($trackerKey, $activeStages, self::CACHE_MODE_TTL);
        }

        Log::info("RFID Active Mode SET: mode={$mode} stage={$stage} company_id={$companyId}");
    }

    /** Ambil mode aktif untuk stage tertentu, atau stage pertama aktif jika stage=null */
    public function getActiveMode(int $companyId, ?string $stage = null): ?array
    {
        if ($stage !== null) {
            return Cache::get($this->modeKey($companyId, $stage));
        }

        $activeStages = Cache::get($this->stagesTrackerKey($companyId), []);
        foreach ($activeStages as $s) {
            $data = Cache::get($this->modeKey($companyId, $s));
            if ($data) return $data;
        }
        return null;
    }

    /** Ambil semua mode aktif untuk semua stage (untuk dashboard) */
    public function getAllActiveModes(int $companyId): array
    {
        $activeStages = Cache::get($this->stagesTrackerKey($companyId), []);
        $modes = [];
        foreach ($activeStages as $s) {
            $data = Cache::get($this->modeKey($companyId, $s));
            if ($data) {
                $modes[$s] = $data;
            }
        }
        return $modes;
    }

    /** Reset mode ke idle — stage tertentu atau semua stage */
    public function clearActiveMode(int $companyId, ?string $stage = null): void
    {
        $trackerKey = $this->stagesTrackerKey($companyId);

        if ($stage !== null) {
            Cache::forget($this->modeKey($companyId, $stage));
            $activeStages = array_values(array_filter(
                Cache::get($trackerKey, []),
                fn ($s) => $s !== $stage
            ));
            empty($activeStages)
                ? Cache::forget($trackerKey)
                : Cache::put($trackerKey, $activeStages, self::CACHE_MODE_TTL);
            Log::info("RFID Active Mode CLEARED: stage={$stage} company_id={$companyId}");
        } else {
            foreach (Cache::get($trackerKey, []) as $s) {
                Cache::forget($this->modeKey($companyId, $s));
            }
            Cache::forget($trackerKey);
            Log::info("RFID Active Mode CLEARED ALL: company_id={$companyId}");
        }
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
     * Mulai sesi registrasi tanpa reader spesifik (kompatibilitas endpoint lama `pending-batch`).
     * Sesi baru per-reader memakai RfidRegistrationService::start() langsung.
     */
    public function setPendingBatch(int $batchId, int $companyId, string $initiatedBy = 'System'): void
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('company_id wajib');
        }

        $batch = Batch::with('productCode')->where('company_id', $companyId)->findOrFail($batchId);
        app(RfidRegistrationService::class)->start($initiatedBy, $batch, null);
    }

    /** Sesi registrasi "any" milik perusahaan ini (bentuk lama) — TANPA fallback lintas-perusahaan. */
    public function getPendingBatch(int $companyId): ?array
    {
        $sessions = collect(app(RfidRegistrationService::class)->activeForCompany($companyId))
            ->firstWhere('reader_id', null);

        return $sessions ? [
            'batch_id'     => $sessions['batch_id'],
            'batch_code'   => $sessions['batch_code'],
            'product_code' => $sessions['product_code'],
            'initiated_by' => $sessions['initiated_by'],
            'company_id'   => $sessions['company_id'],
            'started_at'   => $sessions['started_at'],
        ] : null;
    }

    /** Batalkan sesi registrasi "any" perusahaan ini. */
    public function cancelPendingBatch(int $companyId): void
    {
        $svc = app(RfidRegistrationService::class);
        foreach ($svc->activeForCompany($companyId) as $s) {
            if ($s['reader_id'] === null) {
                $svc->cancel($s['id']);
            }
        }
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

    /**
     * Config untuk ESP32 saat boot.
     *
     * PENTING — company_code (string, mis. "TIMAH") WAJIB disertakan di
     * setiap request scan_in/scan_out/register berikutnya sebagai header
     * "X-Company-Code". company_id (integer) HANYA untuk referensi/logging
     * di sisi firmware — endpoint scan (/api/rfid/login, /api/rfid/logout,
     * /api/rfid/register) TIDAK menerima company_id numerik sebagai
     * identifikasi tenant, hanya X-Company-Code string. Firmware yang hanya
     * mengandalkan company_id tanpa mengirim header ini akan jatuh ke
     * RFID_DEFAULT_COMPANY_CODE (.env) — lihat
     * ApiRfidController::resolveCompanyId().
     */
    public function getEspBootConfig(int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);

        return [
            'api_base_url'      => config('app.url'),
            'endpoint_register' => '/api/rfid/register',
            'endpoint_scan_in'  => '/api/rfid/login',    // endpoint sama, semantik berubah
            'endpoint_scan_out' => '/api/rfid/logout',   // endpoint sama, semantik berubah
            'api_token'         => '',
            'company_id'        => $companyId,
            'company_code'      => $company?->code,
            'company_header'    => 'X-Company-Code',   // firmware WAJIB kirim header ini
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

        $query->where('company_id', $companyId);

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

        $query->where('company_id', $companyId);

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
            ->where('company_id', $companyId)
            ->active()
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
     * Cari batch berdasarkan UID tag — KETAT per perusahaan.
     *
     * Tag milik perusahaan lain dianggap "tidak dikenal" (tanpa membocorkan keberadaannya).
     * $allowGlobalFallback HANYA untuk perangkat anonim legacy (config rfid.legacy_global_tag_fallback):
     * bila tag tidak ada di perusahaan default, cari di seluruh perusahaan (perilaku lama).
     */
    public function findBatchByUid(string $uid, int $companyId, bool $allowGlobalFallback = false): ?Batch
    {
        $query = fn () => Batch::with(['productCode', 'currentLocation'])
            ->where('rfid_tag', $uid)
            ->whereNull('deleted_at');

        $batch = $query()->where('company_id', $companyId)->first();

        if (! $batch && $allowGlobalFallback) {
            $batch = $query()->first();
            if ($batch) {
                Log::warning("RFID: fallback global perangkat anonim — tag milik company_id={$batch->company_id}, diminta company_id={$companyId}");
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
     *
     * PUBLIC — dipanggil ApiRfidController sebelum findBatchByUid() agar
     * aturan sanitasi identik dengan yang dipakai checkpointIn()/checkpointOut()
     * secara internal (termasuk strip karakter '.', bukan hanya spasi/':'/'-'
     * seperti sanitasi level-controller yang lama).
     */
    public function sanitizeUid(string $uid): string
    {
        return strtoupper(str_replace([' ', ':', '-', '.'], '', trim($uid)));
    }
}