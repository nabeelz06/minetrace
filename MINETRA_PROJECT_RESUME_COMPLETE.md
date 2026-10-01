# MINETRA — Project Resume & Changelog

> **Diaudit ulang: 14 Juli 2026** — Audit menyeluruh sesi ini: seluruh
> backend (Controllers, Models, Services, Jobs, Requests, Enums, Traits,
> Contracts, routes, config, migrations, seeders, docker) dan seluruh
> frontend (Pages, Layouts, composables, lang, components) dibaca penuh.
> Setiap klaim di bawah ini diverifikasi ulang terhadap kode aktual dan/atau
> pengujian HTTP nyata sesi ini — bukan diwariskan begitu saja dari entri
> changelog sebelumnya. Entri changelog historis (di bawah marker ini)
> dipertahankan sebagai catatan insiden, tapi bagian **Status Operasional**
> di paling bawah dokumen ini adalah SATU-SATUNYA sumber kebenaran terkini.
>
> **Keterbatasan audit sesi ini**: akses SSH ke VPS produksi
> (`minetra@212.85.24.189`) gagal (publickey ditolak) dan tidak dipulihkan
> sesi ini atas keputusan pemilik proyek. Akibatnya, beberapa item yang
> SECARA KETAT membutuhkan query langsung ke server produksi (nilai kolom
> `cctv_cameras.is_simulation` di DB produksi, status Tailscale di sisi VPS,
> `docker compose ps`/`migrate:status` real-time) **tidak dapat
> dikonfirmasi langsung** sesi ini — ditandai jujur sebagai demikian di
> bawah, bukan diasumsikan.

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
  `X-Requested-With` dan `X-Company-Code`.
- `ApiRfidController::resolveCompanyId()` menambahkan fallback
  `RFID_DEFAULT_COMPANY_CODE` (.env) sebelum jatuh ke `DEFAULT_COMPANY_ID`
  hardcode.
- `RfidService::getEspBootConfig()` sekarang mengembalikan `company_code`
  (string) di samping `company_id` (integer).

### Keputusan Desain — Konsolidasi Navigasi RFID
`RfidPanel.vue` (konsol admin/supervisor) dan interface scan lapangan
sebelumnya muncul sebagai dua item navigasi sidebar terpisah, dikonsolidasi
menjadi satu kapabilitas adaptif (`MobileRfidScan.vue` → `RfidFieldMode.vue`,
route `hardware/rfid/field`, auto-redirect server-side untuk mobile
operator).

---

## 2026-07-06 — Env-ifikasi Domain, Readiness Check, & CctvSnapshot First-Class Entity

Audit kesiapan go-live, readiness check menyeluruh, dan migrasi snapshot
CCTV dari JSON blob menjadi entitas first-class (`cctv_snapshots`) dengan
CRUD, soft-delete, dan audit trail.

Ringkasan singkat (detail lengkap ada di riwayat git commit terkait):
Sanctum baru benar-benar terpasang (sebelumnya hanya direferensikan tapi
tidak ter-`composer require`), `.env.local` landmine `APP_ENV` diperbaiki,
`docker-compose.prod.yml` mendapat service `mediamtx` (sebelumnya tidak
ada sama sekali → CCTV akan gagal total di produksi tanpa ini),
`MassBalanceExport.php` dibuat (sebelumnya dirujuk tapi tidak ada), dan
`cctv_snapshots` menjadi entitas first-class dengan `CctvSnapshotRepository`
sebagai satu-satunya sumber presentasi data.

---

## 2026-07-12 — "Merge VPS" Guard Produksi Telescope (diinvestigasi ulang sesi ini)

Commit `6a0728f` (author: `VPS Admin <admin@mining-traceability.com>`,
dijalankan langsung dari VPS, bukan lewat alur dev normal — inilah yang
membuatnya tampak "mencurigakan" pada sesi audit sebelumnya) diinvestigasi
ulang penuh sesi ini via `git show 6a0728f`. Hasilnya **dikonfirmasi
jinak**:

- `app/Providers/AppServiceProvider.php` — `TelescopeServiceProvider` dan
  `Laravel\Telescope\TelescopeServiceProvider` sekarang hanya di-register
  di dalam blok `if ($this->app->environment('local'))`.
- `bootstrap/providers.php` — baris registrasi Telescope tanpa syarat
  di-comment-out (`//App\Providers\TelescopeServiceProvider::class`).
- `docker/nginx/conf.d/app.prod.conf` — **satu-satunya perubahan adalah
  penghapusan baris komentar** (`# Certbot challenge`, `# SSL Certificates`,
  dst.) dan perbaikan trailing newline. Tidak ada perubahan `server_name`,
  `ssl_certificate`, `location` block, atau header keamanan apa pun.

**Kesimpulan**: ini memang perbaikan guard produksi Telescope yang benar
(mencegah Telescope — yang menyimpan seluruh query/request/exception,
termasuk kredensial di header — aktif dan dapat diakses di lingkungan
produksi), plus pembersihan komentar nginx yang murni kosmetik. Tidak ada
indikasi kompromi atau perubahan berbahaya.

---

## 2026-07-14 — Audit Menyeluruh Pra-Presentasi (sesi ini)

Tujuan sesi: memverifikasi ground truth codebase dan lingkungan sebelum
presentasi ke Prof. Iwan Vanany, memperbaiki HANYA bug kritis yang
terkonfirmasi dan berdampak langsung pada demo, dan menghasilkan dokumen
resume + briefing + skrip demo yang seluruhnya berbasis bukti sesi ini.

### Bug ditemukan & diperbaiki

**1. `ApiRfidController` — response HTML alih-alih JSON pada body kosong**

Direproduksi via `curl -X POST /api/rfid/login` (tanpa body sama sekali):
sebelum perbaikan, respons adalah `302 Found` dengan body
`<!DOCTYPE html>...<meta http-equiv="refresh">` — bukan JSON kontrak yang
didokumentasikan untuk firmware ESP32 (LCD 16×2 membaca `name`/`message`/
`status` dari JSON).

Root cause **BUKAN** seperti hipotesis sesi sebelumnya ("request tidak
pernah sampai ke Laravel") — dikonfirmasi salah: `laravel.log` mencatat
entri `RFID /login → uid:... ip:...` untuk SETIAP request termasuk yang
gagal, membuktikan request selalu sampai ke controller. Root cause
sebenarnya: `$request->validate(['uid' => 'required|...'])` melempar
`ValidationException`, dan Laravel secara default me-render exception ini
sebagai `redirect()->back()` (HTML) untuk request yang tidak dianggap
"expects JSON" (tidak ada header `Accept: application/json` maupun
`X-Requested-With: XMLHttpRequest`) — persis kondisi request ESP32 asli
maupun `curl` polos.

**Perbaikan**: `registerCard()`, `loginCard()`, `logoutCard()` di
`app/Http/Controllers/Api/V1/Hardware/ApiRfidController.php` sekarang
membungkus `$request->validate()` dalam `try/catch
(\Illuminate\Validation\ValidationException $e)` dan mengembalikan JSON
error (`422`) secara eksplisit, dengan `Log::warning()` terstruktur.

Diverifikasi ulang setelah perbaikan — ketiga endpoint (`register`,
`login`, `logout`) dengan body kosong sekarang mengembalikan
`Content-Type: application/json` dan body
`{"status":"failed","name":"Error","message":"UID tidak valid atau kosong"}`.

**2. `Batches/Show.vue` — fungsi `snapshotStatusBadge()` dipanggil tapi tidak pernah didefinisikan**

Ditemukan oleh sub-agent pembacaan kode, dikonfirmasi manual via `grep`:
dipanggil 6× di template (tab Checkpoint & tab Batch Induk) tapi tidak ada
definisi `function snapshotStatusBadge` di manapun dalam file. Setiap
checkpoint dengan `snapshot_status` non-null (yaitu SETIAP checkpoint yang
melalui pipeline RFID→CCTV — persis alur inti demo) akan menyebabkan error
runtime Vue yang merusak render **seluruh halaman detail batch**, bukan
cuma badge-nya.

**Perbaikan**: fungsi ditambahkan (pola identik dengan
`snapshotStatusLabel()`/`snapshotStatusColor()` yang sudah terbukti bekerja
di `CctvMonitor.vue`), mengembalikan `{cls, label}` sesuai kontrak
pemanggilan di template.

**Diverifikasi end-to-end** (lihat bagian pengujian di bawah) — batch
detail page (`GET /company/batches/1`) mengembalikan `200` dengan props
`snapshot_status: "captured"` dan `snapshot_status: "failed"` pada
checkpoint berbeda, keduanya sekarang tertangani oleh fungsi yang baru
ditambahkan.

### Bug ditemukan, TIDAK diperbaiki (di luar scope sesi ini — didokumentasikan)

- **`ApiHardwareController`** (`app/Http/Controllers/Api/V1/Hardware/`) —
  method `rfidScan()`, `simulateScan()`, `rfidAssign()` memanggil
  `RfidService::processScan()`, `::simulateScan()`, `::assignTagToBatch()`
  — **ketiga method ini tidak ada sama sekali** di `RfidService`
  (dikonfirmasi `grep -rn` penuh). Route publik tanpa auth
  `POST /api/v1/hardware/rfid-scan` dan 2 route Sanctum-gated akan
  fatal error jika benar-benar dipanggil. **Dikonfirmasi TIDAK dipanggil
  oleh frontend saat ini** (`RfidPanel.vue`/`RfidFieldMode.vue` memanggil
  `/api/rfid/*` dan `/api/v1/company/rfid/*`, bukan endpoint ini) — kode
  legacy yang sudah digantikan `ApiRfidController`, sekadar belum
  dibersihkan. Tidak berdampak pada demo. Tidak diperbaiki sesi ini
  (di luar scope "fix hanya yang load-bearing untuk demo").
- **`CreateLabAnalysisRequest`** — `authorize()` mengecek role
  `operator_lab` yang tidak ada di `UserRole` enum (role sebenarnya
  `operator_lab_ltj`). Dikonfirmasi **dead code** — `LabController` tidak
  meng-type-hint FormRequest ini di method manapun, memakai
  `$request->validate()` inline dengan pengecekan role yang benar. Tidak
  berdampak nyata. Tidak diperbaiki.
- **`rfid_tag` pada form pembuatan batch (`Batches/Create.vue`)** —
  frontend punya scanner QR/barcode kamera yang mengisi field `rfid_tag`
  dan mengirimkannya saat `POST /company/batches`. `CreateBatchRequest`
  memvalidasi field ini. **Tapi `BatchService::createBatch()` tidak pernah
  menyertakan `rfid_tag` di array `Batch::create([...])`** — field
  tervalidasi tapi diam-diam dibuang, tag TIDAK pernah tersimpan.
  Dikonfirmasi via pengujian langsung sesi ini (batch dibuat dengan
  `rfid_tag=AABBCCDD11` di request, kolom `rfid_tag` di DB tetap kosong).
  Alur yang BENAR-BENAR berfungsi dan sudah teruji: set pending batch
  (`POST /api/v1/company/rfid/pending-batch`) lalu tempel tag fisik
  (`POST /api/rfid/register`) — dua langkah terpisah, bukan satu langkah
  di form create. **Skrip demo di bawah memakai alur yang terbukti
  berfungsi ini, bukan field `rfid_tag` di form create.** Tidak diperbaiki
  sesi ini karena bukan jalur yang akan didemokan, dan memperbaikinya
  berarti keputusan desain (apakah field itu harus dihapus dari UI atau
  di-wire ke backend) yang di luar scope audit.
- **Kredensial `Tapo123!` plaintext** — masih ada di
  `docker/mediamtx/mediamtx.yml:122` dan
  `docker/mediamtx/mediamtx.prod.yml:50`. **Dikonfirmasi ke pemilik
  proyek sesi ini: password fisik kamera BELUM dirotasi.** Sesuai
  instruksi eksplisit, file TIDAK diubah sesi ini (tidak boleh
  memparameterisasi ke env var sebelum password asli dirotasi — akan
  memberi kesan palsu bahwa masalah sudah selesai). Lihat bagian Postur
  Keamanan di `PRESENTASI_BRIEFING.md` untuk tindak lanjut yang
  direkomendasikan.

---

## Status Operasional Saat Ini (berdasarkan audit 14 Juli 2026)

| Komponen | Status | Bukti |
|---|---|---|
| Progressive Authorization (audit_only/scan_to_receive/scan_to_dispatch) | ✅ **Confirmed** — implementasi nyata, bukan skeleton | `app/Contracts/ScanAuthorizationStrategy.php`, `RfidAuthorizationService`, 3 strategy class, dipanggil dari `ApiRfidController::loginCard/logoutCard`. Badge mode live juga tampil di UI (`Process/Stage.vue`, `RfidFieldMode.vue`) |
| Navigasi RFID (1 entri adaptif) | ✅ **Confirmed** | `AppLayout.vue`: satu `rfidItem`; route `hardware/rfid` + `hardware/rfid/field` dengan auto-redirect mobile |
| Bug RFID `<!DOCTYPE html>` pada body kosong | ✅ **Direproduksi lalu diperbaiki** sesi ini | Lihat bagian di atas — diverifikasi ulang, sekarang selalu JSON |
| RFID scan → checkpoint → CCTV capture → tampil di batch detail (LOKAL) | ✅ **Confirmed end-to-end** (lingkungan dev lokal, sesi ini) | Lihat bagian "Pengujian End-to-End" di bawah |
| CCTV simulasi vs real di PRODUKSI | ⚠️ **Tidak dapat dikonfirmasi langsung** — akses VPS tidak tersedia sesi ini | `.env.production`: `CCTV_USE_REAL=false`, `CCTV_RTSP_URL` masih placeholder `CHANGE_ME` → secara logika seeder (`PtTimahCctvCameraSeeder`) akan memaksa `is_simulation=true` bila di-seed ulang dengan env ini — tapi nilai AKTUAL di DB produksi tidak ter-query sesi ini. **Temuan penting**: `docker/mediamtx/mediamtx.prod.yml` (berbeda dari `mediamtx.yml` lokal) meng-alias SEMUA path `cam_gate1`/`cam_wet`/`cam_warehouse` ke satu sumber fisik `live_gate1` — artinya di produksi, bahkan kamera berstatus "simulasi" tetap bergantung pada kamera fisik Tapo C500 bisa dijangkau mediamtx, TIDAK murni sintetis seperti di lokal |
| Tailscale bridge ke kamera fisik | ❌ **Tidak terpasang** di mesin dev lokal ini. Status di VPS **tidak dapat diverifikasi** sesi ini | `tailscale: command not found` (mesin lokal); SSH ke VPS gagal |
| "Merge VPS" mencurigakan (commit `6a0728f`) | ✅ **Dikonfirmasi jinak** | `git show 6a0728f` — guard produksi Telescope + pembersihan komentar nginx murni |
| Kredensial `Tapo123!` bocor di kode | ❌ **Masih ada**, password fisik belum dirotasi (dikonfirmasi ke pemilik) | `docker/mediamtx/mediamtx.yml:122`, `mediamtx.prod.yml:50` |
| Laporan PDF & Excel (5 jenis × 2 format) | ✅ **Confirmed berfungsi** — 2 dari 5×2 kombinasi diuji langsung via HTTP sesi ini | `batch_traceability` (PDF, valid, 1.27 MB), `mass_balance` (Excel, valid `.xlsx`) — keduanya HTTP 200 via sesi login nyata |
| `npm run build` | ✅ 0 error, 627 module, ~27–30 detik | Dijalankan ulang sesi ini setelah perbaikan `Show.vue` |
| Multi-tenancy scoping | ✅ Pola manual (`scopeOfCompany()` per model), konsisten di seluruh file yang dibaca — tidak ditemukan celah aktif, tapi arsitekturnya rawan human-error (bukan global scope otomatis) | Audit baca penuh `app/Models/`, `app/Http/Controllers/Company/` |
| Sanctum / REST API v1 mirror (Batch, Checkpoint, Company, Auth) | ✅ Terpasang dan fungsional, ⚠️ **tidak dipakai frontend saat ini** — scaffolding untuk klien mobile masa depan | `composer.json`: `laravel/sanctum ^4.3` terpasang; grep frontend tidak menemukan pemanggilan endpoint ini |

---

## Pengujian End-to-End Sesi Ini (lingkungan dev lokal, bukan produksi)

Dijalankan berurutan melalui HTTP nyata (login session asli, CSRF token
asli, bukan Tinker):

1. Login `admin@ptimah.co.id` → `302` ke `/company/dashboard`.
2. `POST /company/batches` (produk CER, lokasi Kundur, berat 1000 kg) →
   batch `TMH.KND-LK.CER-260714-001` dibuat di stage `lab_kimia`.
3. `POST /api/v1/company/rfid/pending-batch` (`batch_id: 1`) → sesi
   registrasi aktif 90 detik.
4. `POST /api/rfid/register` (`uid: AABBCCDD11`) → tag berhasil ditautkan
   ke batch.
5. `POST /api/rfid/login` (scan IN, stage `receiving`) → checkpoint
   tercatat (`manual_mode: true`, sesuai mode `audit_only` — TIDAK
   memindah stage otomatis, sesuai desain).
6. Job `CaptureCctvSnapshotJob` diproses `queue:work` — kamera dalam
   kondisi default lokal (`is_simulation=false`, mengarah ke kamera fisik
   `192.168.18.38` yang tidak reachable dari sandbox ini) → hasil
   `snapshot_status: failed`, `ffmpeg: Connection ... No route to host`,
   3× retry terstruktur, log jelas — **PERSIS perilaku yang didesain dan
   didokumentasikan sebelumnya** (kegagalan kamera fisik dari lingkungan
   dev BUKAN bug).
7. Kamera lokal disetel sementara ke `is_simulation=true` (perubahan lokal
   murni, dikembalikan ke `false` setelah pengujian) → `POST
   /api/rfid/logout` (scan OUT) → job capture berhasil: file JPEG valid
   1280×720 (`storage/app/public/cctv-snapshots/2/snap_2_4_...jpg`, 69 KB),
   `snapshot_status: captured`, tercatat di `cctv_snapshots` (entitas
   first-class) DAN mirror `checkpoints.photos`.
8. `GET /company/batches/1` → `200`, props Inertia berisi kedua checkpoint
   (`failed` dan `captured`) dengan `snapshot_status` terisi benar —
   inilah yang mengonfirmasi perbaikan bug `snapshotStatusBadge` di atas.
9. `POST /company/reports/generate` (`batch_traceability`, format `pdf`)
   → `200`, file PDF valid (`%PDF-1.7`, trailer `%%EOF` benar, 1.27 MB).
10. `POST /company/reports/generate` (`mass_balance`, format `excel`) →
    `200`, file `.xlsx` valid (Microsoft Excel 2007+).

**PENTING**: seluruh 10 langkah di atas dijalankan di **lingkungan dev
lokal** (`php artisan serve` + Docker `postgres`/`redis`/`mediamtx`
lokal), BUKAN terhadap `https://mining-traceability.com`. Akses VPS
produksi tidak tersedia sesi ini. Lihat `SKRIP_DEMO_LIVE.md` untuk
implikasi ini terhadap skrip demo, dan `PRESENTASI_BRIEFING.md` bagian
Keterbatasan untuk rekomendasi rehearsal pra-presentasi.

Data uji yang ditinggalkan di DB lokal sebagai artifact: batch
`TMH.KND-LK.CER-260714-001` (id=1) dengan 2 snapshot CCTV (1 gagal, 1
berhasil). Aman dibiarkan atau dihapus manual sesuai kebutuhan.
