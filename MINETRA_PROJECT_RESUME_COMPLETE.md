# MINETRA — Project Resume & Changelog

Dokumen ini mencatat perubahan signifikan pada arsitektur dan status
operasional sistem MINETRA (traceability mineral PT Timah Tbk) yang tidak
sepenuhnya tercermin dari riwayat commit git semata — terutama insiden,
root cause, dan keputusan desain yang melatarbelakanginya.

---

## 2026-07-01 — Perbaikan Pipeline RFID → CCTV Snapshot + Konsolidasi Navigasi

### Insiden
Batch yang di-tag via simulasi RFID (dan, sebelum diverifikasi eksplisit,
diduga juga hardware ESP32 asli) tidak pernah menampilkan foto CCTV di
halaman detail batch, walaupun snapshot seharusnya terekam otomatis untuk
keperluan audit setiap kali terjadi scan.

### Root Cause (dikonfirmasi berlapis, via elimination test end-to-end)
Enam lapisan masalah independen ditemukan bertumpuk, masing-masing
diverifikasi lewat trace langsung (bukan asumsi):

1. **ffmpeg tidak terpasang di host** yang menjalankan `queue:work` — hanya
   tersedia di dalam container `mediamtx`. Setiap percobaan probe/capture
   gagal dengan exit code 127.
2. **Flag `-timeout` ffmpeg deprecated** pada ffmpeg 4.4.x untuk RTSP
   demuxer — semantiknya berubah menjadi "listen mode" (server), sehingga
   ffmpeg mencoba bind ke port yang sudah dipakai mediamtx sendiri
   ("Address already in use"). Dihapus dari kedua pemanggilan ffmpeg;
   wall-clock timeout diserahkan ke wrapper shell `timeout N`.
3. **`mediamtx.yml` path `cam_gate1` diarahkan ke kamera fisik TP-Link**
   (bukan lavfi simulation loop seperti `cam_wet`/`cam_warehouse`) —
   mode simulasi untuk stage receiving diam-diam bergantung pada hardware
   LAN yang tidak reachable di lingkungan dev.
4. **`PtTimahCctvCameraSeeder` hardcode `company_id => 1`** — padahal
   `company_id=1` adalah "Badan Industri Mineral" (BIM, regulator), BUKAN
   PT Timah Tbk (yang sesungguhnya `company_id=2`). Seluruh kamera CCTV
   ter-seed ke tenant yang salah sejak awal.
5. **`CaptureCctvSnapshotJob` di-dispatch dengan `company_id` hasil resolve
   request** (default ke 1) alih-alih `company_id` milik batch — celah
   multi-tenancy yang bisa menyebabkan resolusi kamera lintas-tenant yang
   salah.
6. **`RfidPanel.vue` `simulateScan()` tidak mengirim header
   `X-Requested-With` maupun `X-Company-Code`** — setiap scan simulasi
   salah diklasifikasikan sebagai `rfid_origin=real_esp32`, dan selalu
   jatuh ke default tenant yang salah karena endpoint `/api/rfid/*`
   berjalan tanpa session (`api` middleware group, stateless).

### Perbaikan
- ffmpeg diinstal di host (`sudo apt install ffmpeg`).
- `-timeout` dihapus dari `CaptureCctvSnapshotJob::probeRtspReady()` dan
  `::captureFrame()`; `-analyzeduration`/`-probesize` ditambahkan.
- `docker/mediamtx/mediamtx.yml`: `cam_gate1` diubah menjadi lavfi
  simulation loop (pola sama dengan `cam_wet`/`cam_warehouse`).
- `PtTimahCctvCameraSeeder` sekarang resolve `company_id` dinamis via
  `Company::where('code', 'TIMAH')`.
- `RfidService::checkpointIn()`/`checkpointOut()` mengembalikan
  `company_id` milik batch di response array; `ApiRfidController`
  menggunakan nilai ini untuk dispatch job (bukan hasil resolve request).
- `RfidPanel.vue`: `simulateScan()`/`simulateRegister()` sekarang mengirim
  `X-Requested-With` dan `X-Company-Code` (sumber: prop `company_code`
  baru dari `HardwareController`).
- `ApiRfidController::resolveCompanyId()` menambahkan fallback
  `RFID_DEFAULT_COMPANY_CODE` (.env) sebelum jatuh ke
  `DEFAULT_COMPANY_ID` hardcode — mencegah asumsi keliru bahwa
  `company_id=1` selalu perusahaan operasional.
- `RfidService::getEspBootConfig()` sekarang mengembalikan `company_code`
  (string) di samping `company_id` (integer), karena endpoint scan hanya
  menerima `X-Company-Code` string, bukan ID numerik.

Diverifikasi end-to-end untuk **kedua jalur** (simulasi browser DAN
payload bergaya ESP32 asli tanpa header apa pun): `snapshot_status:
captured`, `capture_succeeded: true`, `rfid_origin` benar, file JPEG valid
di disk dengan sha256 yang cocok, dan dapat diakses publik via URL
`/storage/...`.

### Keputusan Desain — Konsolidasi Navigasi RFID
`RfidPanel.vue` (konsol admin/supervisor) dan interface scan lapangan
sebelumnya muncul sebagai dua item navigasi sidebar yang terpisah
("Hardware RFID" dan "Mobile RFID Scan"), padahal keduanya melayani
konteks kerja yang berbeda, bukan fitur duplikat. Dikonsolidasi menjadi
satu kapabilitas adaptif:

- `MobileRfidScan.vue` di-rename menjadi `RfidFieldMode.vue`, dengan
  tambahan preview snapshot CCTV (polling `recent-snapshots`) dan feedback
  `navigator.vibrate()` pada hasil scan.
- Route direstrukturisasi: `hardware/rfid/mobile` → `hardware/rfid/field`
  (`company.hardware.rfid.field`).
- `HardwareController::rfidPanel()` sekarang redirect otomatis (302
  eksplisit, bukan swap rendering diam-diam) untuk role operator stage
  yang mengakses dari User-Agent mobile.
- Link manual dua arah: "Buka Mode Lapangan" di `RfidPanel.vue`, "Buka
  Panel Lengkap" di `RfidFieldMode.vue` (khusus company_admin/auditor).
- `AppLayout.vue`: item nav "Mobile RFID Scan" dihapus — sidebar kini
  menampilkan tepat satu entri RFID.

---

## Bagian 12 — Status Operasional Saat Ini

| Komponen | Status |
|---|---|
| RFID scan (simulasi) → checkpoint audit trail | ✅ Berfungsi |
| RFID scan (payload gaya ESP32 asli) → checkpoint audit trail | ✅ Berfungsi |
| CCTV snapshot capture (simulasi, 3 stage: receiving/wet/warehouse) | ✅ Berfungsi |
| Multi-tenancy scoping (company_id batch vs kamera) | ✅ Diperbaiki |
| Progressive Authorization (audit_only / scan_to_receive / scan_to_dispatch) | ✅ Berfungsi (dari sesi sebelumnya) |
| Navigasi RFID (satu kapabilitas adaptif) | ✅ Dikonsolidasi |
| `npm run build` | ✅ 0 error |

---

## 2026-07-06 — Bagian 13: Env-ifikasi Domain, Readiness Check, & CctvSnapshot First-Class Entity

Sesi ini mengaudit seluruh codebase untuk kesiapan go-live (deploy-target
apapun yang akhirnya dipilih), menjalankan readiness check menyeluruh
website + RFID + CCTV, dan mengubah snapshot CCTV dari JSON blob menjadi
entitas first-class yang bisa di-CRUD, di-soft-delete dengan audit trail,
dan diverifikasi.

### Bagian A — Env-ifikasi domain/URL

- **`laravel/sanctum` ternyata tidak pernah terinstal** meski
  `routes/api.php` sudah lama mereferensikan middleware `auth:sanctum`
  untuk `api/v1/auth/*`, `company/batches|checkpoints|hardware/*`, dan
  `bim/*` — setiap request ke rute tersebut akan gagal dengan
  `Auth guard [sanctum] is not defined`. Frontend tidak pernah memanggil
  rute ini (hanya memakai rute berbasis session `web`+`auth`), jadi bug
  ini laten, bukan insiden aktif — namun akan langsung pecah begitu ada
  klien mobile/API. Diperbaiki: `composer require laravel/sanctum`,
  publish config+migrasi, `User` model memakai `HasApiTokens`,
  `SANCTUM_STATEFUL_DOMAINS` ditambahkan ke `.env`/`.env.example`.
- **`.env.local` (file ter-commit, dimaksudkan sebagai referensi/template
  Docker local) ternyata adalah LANDMINE nyata**: Laravel secara otomatis
  memuat `.env.{APP_ENV}` sebagai PENGGANTI (bukan overlay) `.env` biasa
  jika `APP_ENV` sudah ter-set di level OS SEBELUM Dotenv membaca file
  manapun. `php artisan serve` sengaja meneruskan `APP_ENV` dari proses
  induk ke proses anaknya (whitelist `$passthroughVariables`), yang
  memicu perilaku ini — dan karena `.env.local` punya `APP_KEY` kosong,
  SETIAP request lewat `php artisan serve` gagal dengan
  `MissingAppKeyException` pada rute manapun yang memakai middleware
  `web` (session/CSRF). Dikonfirmasi via elimination test bertingkat
  (tinker vs `php -r` vs `php -S` manual vs `artisan serve` — hanya jalur
  terakhir yang gagal). Diperbaiki: `git mv .env.local .env.local.example`
  (tidak pernah dibaca oleh `start-local.sh`, jadi aman).
- `resources/js/ziggy.js` (build artifact ter-commit, hardcode
  `http://localhost`) dihapus — tidak pernah di-import; routing Ziggy
  sepenuhnya ditangani via `@routes` Blade directive per-request.
- `docker-compose.prod.yml` tidak punya service `mediamtx` sama sekali —
  seluruh fitur CCTV akan gagal total di produksi. Ditambahkan (tanpa
  publish port ke host; HLS di-reverse-proxy lewat nginx `location /hls/`
  agar tetap di balik HTTPS yang sama, hindari mixed-content block).
- `.env.production` diperbaiki: `CACHE_STORE` (bukan `CACHE_DRIVER` yang
  sudah deprecated di Laravel 11 — sebelumnya cache diam-diam fallback ke
  driver `database`), plus `CCTV_*`/`RFID_DEFAULT_COMPANY_CODE` yang
  sebelumnya tidak diisi sama sekali.
- `CaptureCctvSnapshotJob::probeRtspReady()` sebelumnya menebak kamera
  real vs simulasi via string-matching (`str_contains('192.168')`) —
  diganti membaca `$captureMode` (sumber kebenaran per-kamera) langsung.
- Ditulis `ENVIRONMENT_MIGRATION_CHECKLIST.md` sebagai referensi
  hand-off tunggal saat keputusan hosting akhirnya final.

### Bagian B — Readiness check (evidence, bukan asumsi)

- `migrate:fresh --seed --force`: bersih, 0 error, dijalankan 2×.
- Login HTTP end-to-end untuk seluruh 13 user seed → dashboard redirect
  benar per role, semua render 200.
- Lifecycle batch penuh via HTTP nyata (bukan tinker): lab_kimia →
  receiving → wet_process → dry_process → `dryTransform()` (1 batch → 3
  konsentrat ZIR/ILM/MON dengan `parent_batch_id` benar) → warehouse →
  `warehouseSendToLtj()` → `receiveBatch('project_plant')`. Menemukan dan
  memperbaiki bug nyata: `ProcessController::forwardBatch()` dan
  `::receiveBatch()` crash `Undefined array key "location_id"` saat field
  opsional itu benar-benar tidak dikirim (bukan cuma kosong) — diperbaiki
  jadi `$validated['location_id'] ?? null`.
- RFID scan_in + scan_out via `/api/rfid/login`/`logout` nyata →
  Checkpoint tercatat, `CaptureCctvSnapshotJob` ter-dispatch dan
  ter-proses oleh queue worker. Percobaan capture terhadap kamera REAL
  gagal dengan `No route to host` (kamera fisik `192.168.18.38` tidak
  reachable dari sandbox dev ini) — DIHARAPKAN, bukan bug; pipeline
  menangani kegagalan ini dengan baik (retry 3×, structured failure log,
  `snapshot_status: failed`, tidak pernah membuat RFID response menunggu).
- 3 stream simulasi CCTV (`cam_gate1`/`cam_wet`/`cam_warehouse`) load 200
  berulang kali via `mediamtx`.
- `MassBalanceExport.php` **memang belum ada** (dirujuk di
  `ReportController::generateMassBalanceReport()` tapi class-nya tidak
  ada) — akan fatal error jika format Excel dipilih. Dibuat mengikuti
  pola `ExportSummaryExport`/`LabAnalysisExport`. Kelima jenis laporan
  (batch_traceability, mass_balance, export_summary, lab_analysis,
  monthly_summary) × format (pdf, excel) diuji via HTTP nyata — semua 200
  dengan ukuran file substansial.
- Queue worker: 0 job tertahan, 0 failed_jobs setelah seluruh beban test
  di atas.

### Bagian C — `CctvSnapshot` sebagai entitas first-class

Rationale: JSON blob di `checkpoints.photos` tidak mendukung CRUD nyata,
soft-delete dengan audit trail, filter lintas-batch, atau alur kerja
reviewer pada skala berarti.

- Migrasi `cctv_snapshots` (FK ke company/batch/checkpoint/camera/user,
  `capture_mode`/`capture_status` ENUM, `is_verified`+`verified_by`,
  `SoftDeletes`).
- `App\Models\CctvSnapshot` (scopes `forBatch`/`verified`/`byMode`,
  `LogsActivity` mengikuti pola `Batch`/`LabAnalysis`/`ExportLog`).
- `App\Repositories\CctvSnapshotRepository` — SATU-SATUNYA sumber bentuk
  presentasi data (`present()`), dipakai baik oleh
  `CctvSnapshotController` (global) maupun `BatchController::show()`
  (per-batch), agar `SnapshotGalleryCard.vue` menerima struktur props
  yang identik di kedua konteks.
- `php artisan cctv:migrate-snapshots` — command backfill satu kali dari
  `checkpoints.photos` ke tabel baru, idempotent (diverifikasi lewat
  dry-run + run 2× berturut-turut: run kedua melewati semua baris tanpa
  duplikasi). Menemukan dan memperbaiki bug Postgres: kolom `json` tidak
  mendukung operator `!=` langsung (`operator does not exist: json <>
  unknown`) — diperbaiki via `whereRaw("photos::text != '[]'")`.
- `CaptureCctvSnapshotJob` sekarang menulis `CctvSnapshot::create()`
  sebagai catatan utama (baik jalur sukses maupun gagal), sambil tetap
  menulis mirror ringan ke `checkpoints.photos` untuk kompatibilitas
  mundur.
- CRUD lengkap: `index`/`trashed` (view), `verify` (company_admin/
  company_auditor), `destroy` (company_admin, WAJIB `reason`, soft-delete
  saja, tercatat via `activity()->log()`), `restore` (company_admin).
  Diverifikasi end-to-end via HTTP nyata termasuk penolakan 403 untuk
  role yang tidak berwenang dan penolakan validasi saat `reason` kosong.
- `SnapshotGalleryCard.vue` — komponen tunggal dipakai identik di
  `Batches/Show.vue` (tab Galeri Foto, di-scope per-batch) dan
  `Company/Cctv/SnapshotHistory.vue` (manajemen global lintas-batch) +
  `SnapshotTrash.vue` (arsip terhapus, restore).
- Nav: "Riwayat Capture CCTV" ditambahkan sebagai sub-item accordion di
  bawah entri "CCTV" yang sudah ada (bukan item top-level baru).

| Komponen | Status |
|---|---|
| Domain/IP env-ification (Section 2 grep sweep) | ✅ Bersih |
| Sanctum wiring (guard `auth:sanctum`) | ✅ Diperbaiki |
| `.env.local` APP_ENV landmine | ✅ Diperbaiki |
| Fresh-DB rehearsal (`migrate:fresh --seed`) | ✅ 0 error |
| Login 13 role via HTTP nyata | ✅ Semua 200 |
| Batch lifecycle penuh + `dryTransform()` via HTTP nyata | ✅ Berfungsi |
| `ProcessController::forwardBatch/receiveBatch` location_id bug | ✅ Diperbaiki |
| RFID scan → CCTV capture dispatch (real camera, sandbox) | ⚠️ Gagal karena kamera tidak reachable — diharapkan, ditangani baik |
| 3 stream simulasi CCTV | ✅ 200 berulang |
| `MassBalanceExport.php` | ✅ Dibuat |
| 5 laporan × 2 format via HTTP nyata | ✅ Semua 200 |
| Queue worker health | ✅ 0 job tertahan, 0 gagal |
| `cctv_snapshots` first-class entity + CRUD + backfill | ✅ Berfungsi, idempotent |
| `npm run build` | ✅ 0 error |
