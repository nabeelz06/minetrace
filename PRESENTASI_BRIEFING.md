# MINETRA — Briefing Presentasi untuk Prof. Iwan Vanany

> Disusun 14 Juli 2026, berdasarkan audit teknis menyeluruh sesi ini.
> **Diverifikasi langsung terhadap produksi: 16 Juli 2026** — audit lanjutan
> dengan akses SSH penuh ke VPS produksi (sebelumnya tidak tersedia),
> termasuk satu live-fire test nyata (login → buat batch → registrasi RFID
> → scan → percobaan snapshot CCTV → generate laporan PDF & Excel) langsung
> terhadap `https://mining-traceability.com`, bukan lingkungan lokal.
> Baca bersama `MINETRA_PROJECT_RESUME_COMPLETE.md` (detail teknis lengkap
> + bukti per klaim), `SKRIP_DEMO_LIVE.md` (langkah demo langsung), dan
> `AUDIT_PRODUKSI_2026-07-16.md` (bukti mentah lengkap sesi audit produksi).

---

## (a) Ringkasan Eksekutif

MINETRA adalah sistem traceability mineral berbasis web (Laravel 11 + Vue 3
+ Inertia) yang dibangun untuk PT Timah Tbk, melacak batch mineral (fokus
Zircon/ZIR, Ilmenite/ILM, Monazite/MON) melalui 6 tahap proses — Lab Kimia,
Receiving, Wet Process, Dry Process, Warehouse, hingga Project Plant/LTJ —
dengan audit trail lengkap (checkpoint tertimbang per tahap, deteksi
selisih berat), integrasi hardware RFID (ESP32) untuk pencatatan
otomatis scan masuk/keluar area, integrasi CCTV (kamera Tapo C500 +
MediaMTX) untuk bukti visual otomatis setiap scan, kontrol akses berbasis
peran (11 role berbeda, dari operator stasiun hingga auditor regulator
BIM/ESDM), serta pelaporan (PDF/Excel, 5 jenis laporan) untuk kebutuhan
audit dan ekspor. Sistem sudah live di `https://mining-traceability.com`
dengan SSL valid.

**Status per 16 Juli 2026**: seluruh klaim fitur inti (Progressive
Authorization RFID, navigasi terkonsolidasi, endpoint hardware, pelaporan
PDF/Excel) **dikonfirmasi langsung berfungsi di produksi**, bukan lagi
hanya di lingkungan lokal. Satu bug (respons HTML alih-alih JSON pada
endpoint RFID) ditemukan masih hidup di produksi sesi ini dan **sudah
diperbaiki serta di-deploy ulang ke produksi sesi ini juga** — diverifikasi
ulang langsung setelah deploy. Satu keterbatasan infrastruktur nyata
dikonfirmasi dengan bukti definitif: jembatan jaringan (Tailscale) dari VPS
ke kamera CCTV fisik **belum aktif**, sehingga snapshot CCTV otomatis akan
gagal (dengan graceful, ~4 menit) saat demo produksi kecuali diperbaiki
sebelum hari-H. Detail lengkap di bagian (e).

---

## (b) Arsitektur & Tech Stack

- **Backend**: Laravel 11.51, PHP 8.3, PostgreSQL 15, Redis (cache +
  queue + session), Spatie `laravel-permission` 6.7 (RBAC, mode teams
  dinonaktifkan — isolasi tenant dilakukan manual via `company_id`
  scoping per query, bukan fitur teams Spatie).
- **Frontend**: Vue 3.5 + Inertia.js 3.1 (SPA tanpa API terpisah untuk UI
  utama — render sisi server, hidrasi sisi klien) + Tailwind + Shadcn-Vue.
- **Hardware**: ESP32 (firmware ringan, komunikasi HTTP polos ke endpoint
  `/api/rfid/*`, LCD 16×2 untuk feedback), kamera CCTV Tapo C500 via
  protokol RTSP, di-relay oleh MediaMTX (server media open-source) yang
  menyediakan HLS untuk live-view browser dan endpoint capture-frame untuk
  snapshot otomatis.
- **Infrastruktur**: Docker Compose (7 service: nginx, app, db, redis,
  mediamtx, worker, certbot), reverse-proxy nginx dengan terminasi
  HTTPS/Let's Encrypt (sertifikat aktif, berlaku hingga 7 Oktober 2026),
  HLS CCTV di-proxy lewat nginx (bukan expose port mediamtx langsung —
  mencegah mixed-content block browser). Seluruh 7 container dikonfirmasi
  berjalan pada Docker network yang sama (`minetra_minetra_net`), tidak ada
  regresi dari insiden split-network yang pernah terjadi sebelumnya.
- **Pelaporan**: `barryvdh/laravel-dompdf` (PDF), `maatwebsite/excel`
  (Excel) — 5 jenis laporan × 2 format. Kedua format **dikonfirmasi
  berfungsi langsung di produksi** sesi ini (lihat bagian (d) audit).
- **API tambahan**: `laravel/sanctum` 4.3 terpasang dan berfungsi sebagai
  scaffolding REST API v1 untuk klien mobile masa depan — belum dipakai
  frontend saat ini, murni kesiapan arsitektur.

---

## (c) Perjalanan Infrastruktur Produksi

Bagian ini secara sengaja disusun sebagai narasi kerekayasaan (engineering
rigor) — masalah nyata yang ditemukan, di-root-cause dengan bukti (bukan
tebakan), dan diperbaiki secara terverifikasi. Ini materi presentasi yang
kuat karena menunjukkan proses debugging sistematis, bukan sekadar "semua
berjalan lancar."

1. **Pipeline RFID→CCTV yang diam-diam gagal total (Juli 2026 awal)** —
   enam lapisan masalah independen ditemukan bertumpuk (ffmpeg tidak
   terpasang di host, flag `-timeout` ffmpeg yang berubah semantik di versi
   4.4.x, kamera simulasi yang diam-diam bergantung hardware LAN,
   `company_id` yang di-hardcode salah ke tenant regulator alih-alih PT
   Timah, celah multi-tenancy di job dispatch, dan header yang hilang di
   simulasi scan). Setiap lapisan diverifikasi dengan trace langsung
   (exit code, log terstruktur), bukan asumsi — pola debugging elimination
   test yang sistematis.
2. **Sanctum yang direferensikan tapi tidak pernah ter-install** — rute API
   sudah lama memakai middleware `auth:sanctum`, tapi package-nya sendiri
   tidak pernah di-`composer require`. Bug laten (tidak terasa karena
   frontend memakai autentikasi session, bukan token) yang akan langsung
   pecah begitu ada klien mobile — ditemukan lewat audit proaktif, bukan
   insiden produksi.
3. **Landmine `.env.local`** — Laravel memuat `.env.{APP_ENV}` sebagai
   PENGGANTI (bukan tambahan) `.env` biasa jika `APP_ENV` sudah di-set di
   level OS sebelum Dotenv jalan. File referensi lokal yang ter-commit
   dengan `APP_KEY` kosong nyaris menyebabkan setiap request via
   `php artisan serve` gagal — ditemukan lewat elimination test bertingkat
   (tinker vs `php -r` vs server manual vs `artisan serve`).
4. **`docker-compose.prod.yml` tidak punya service `mediamtx` sama sekali**
   — ditemukan saat audit kesiapan go-live, sebelum sempat jadi insiden
   produksi nyata. Seluruh fitur CCTV akan gagal total tanpa perbaikan ini.
5. **Guard produksi Telescope** (12 Juli 2026) — Telescope (tool debugging
   Laravel yang mencatat SELURUH request/query/exception, termasuk header
   yang berisi kredensial) sekarang hanya aktif di environment `local`,
   dicegah aktif di produksi lewat guard eksplisit di `AppServiceProvider`.
   **Dikonfirmasi ulang langsung di kode produksi 16 Juli 2026** — guard
   masih aktif dan benar, `curl -I https://mining-traceability.com/telescope`
   mengembalikan 404.
6. **Bug response HTML pada endpoint RFID — ditemukan 14 Juli, DEPLOY KE
   PRODUKSI 16 Juli 2026** — endpoint hardware ESP32 mengembalikan halaman
   HTML redirect (bukan JSON) ketika body request kosong ATAU tidak
   mengirim header `Accept`, alih-alih kontrak JSON yang didokumentasikan
   untuk firmware. Root cause: perilaku default Laravel yang me-render
   exception validasi sebagai HTML untuk request tanpa header
   `Accept: application/json` — kondisi yang realistis untuk firmware ESP32
   yang sederhana. **Dikonfirmasi masih reproducible di produksi sesi audit
   16 Juli** (`curl` tanpa header Accept → HTTP 302 + HTML), diperbaiki,
   di-deploy penuh ke produksi (build image `--no-cache`, restart 7
   container, rebuild asset frontend, migrate, optimize), dan diverifikasi
   ulang langsung setelahnya (`curl` yang sama → HTTP 422 JSON yang benar).
7. **Kerentanan keamanan ditemukan 16 Juli 2026 — token otentikasi RFID
   kosong di produksi** — `RFID_API_TOKEN` di `.env.production` kosong, dan
   `RfidService::validateToken()` sengaja didesain "fail open" (menerima
   SEMUA request tanpa token) ketika token belum dikonfigurasi. Artinya
   endpoint `/api/rfid/register|login|logout` — yang publik by design agar
   ESP32 bisa memanggilnya tanpa sesi — saat ini TIDAK memiliki lapisan
   otentikasi apa pun. Siapa pun di internet bisa mendaftarkan tag RFID
   palsu atau memalsukan event scan in/out untuk batch mana pun.
   **Belum diperbaiki** (memerlukan koordinasi dua sisi — set token di
   server DAN update firmware ESP32 fisik — di luar scope perbaikan aman
   sesi ini, direkomendasikan ke pemilik sistem, lihat bagian (f)).
8. **Timing kegagalan CCTV terukur presisi lewat live-fire test nyata (16
   Juli 2026)** — sebelumnya didokumentasikan sebagai "gagal graceful dalam
   ~10 detik". Live-fire test terhadap produksi mengukur angka sebenarnya:
   job `CaptureCctvSnapshotJob` butuh **~4 menit** (`$tries=3` ×
   `$timeout=60` detik + backoff `[5,15,30]` detik) sebelum checkpoint
   akhirnya berstatus `failed`. Checkpoint tetap tercatat dengan benar dan
   RFID tetap merespons cepat (non-blocking) — desain "gagal graceful"
   terbukti benar — tapi jeda visualnya jauh lebih lama dari perkiraan
   sebelumnya. Skrip demo (bagian (e) dan `SKRIP_DEMO_LIVE.md`) diperbarui
   dengan angka ini.

---

## (d) Status Fitur RFID

| Sub-fitur | Status |
|---|---|
| Progressive Authorization (audit_only / scan_to_receive / scan_to_dispatch) | ✅ **Berfungsi, dikonfirmasi di produksi 16 Juli 2026** — tabel `rfid_authorization_modes` di database produksi kosong (belum ada stage yang di-override), dan kode (`RfidAuthorizationService::getMode()`) memang secara eksplisit fallback ke `audit_only` saat tidak ada baris — perilaku default yang aman ini terkonfirmasi aktif untuk semua stage |
| Navigasi RFID tunggal (bukan dua item terpisah) | ✅ **Berfungsi** — satu entri sidebar adaptif, auto-redirect server-side berdasarkan role + User-Agent mobile |
| Endpoint hardware ESP32 (`register`/`login`/`logout`) | ✅ **Berfungsi, DIVERIFIKASI DI PRODUKSI 16 Juli 2026** melalui live-fire test nyata: registrasi tag → scan IN → checkpoint tercatat, seluruhnya lewat `https://mining-traceability.com` (bukan lokal). Bug respons HTML ditemukan masih ada di produksi lalu diperbaiki + di-deploy + diverifikasi ulang sesi ini juga (lihat (c) poin 6) |
| Alur registrasi tag (pending-batch → tempel tag fisik) | ✅ **Berfungsi, DIVERIFIKASI DI PRODUKSI** — batch uji `TMH.SGT-LK.MIT-260716-001` (id=28, sengaja dibuat sebagai data disposable, lihat catatan di bawah) berhasil ditag UID uji lewat alur ini di produksi nyata |
| Field `rfid_tag` di form pembuatan batch (scan QR saat create) | ⚠️ **Bug dikonfirmasi ulang, masih ada di produksi** — `BatchService::createBatch()` menerima `rfid_tag` dari controller tapi tidak pernah menyertakannya di `Batch::create([...])`, field diam-diam hilang. Alur registrasi yang benar-benar berfungsi adalah proses dua langkah terpisah (baris di atas) — **inilah yang dipakai di skrip demo** |
| Keamanan token endpoint hardware (`RFID_API_TOKEN`) | ❌ **Temuan baru 16 Juli 2026** — token kosong di produksi, endpoint efektif tanpa otentikasi. Lihat (c) poin 7 dan (f) untuk detail & rekomendasi |
| Mode operasional saat ini | `audit_only` secara default — scan RFID mencatat audit trail, TIDAK memindah stage batch secara otomatis (operator konfirmasi manual via panel web). Ini keputusan desain yang disengaja, dikonfirmasi aktif di database produksi |

**Catatan data uji**: batch id **28** (`TMH.SGT-LK.MIT-260716-001`) dan
snapshot terkait dibuat khusus untuk live-fire test sesi ini, sengaja
diberi label `[AUDIT-TEST 2026-07-16]` di catatan batch agar mudah
dibedakan dari 20+ batch riset asli. **Belum dihapus** — menunggu
konfirmasi/tindakan pemilik sistem (lihat `AUDIT_PRODUKSI_2026-07-16.md`).

---

## (e) Status Fitur CCTV

| Sub-fitur | Status |
|---|---|
| Pipeline RFID scan → capture snapshot otomatis | ✅ **Alur & logika dikonfirmasi benar di produksi** — checkpoint dibuat, job `CaptureCctvSnapshotJob` di-dispatch otomatis, retry 3× dengan backoff terstruktur, resolusi akhir tercatat rapi ke `checkpoints.snapshot_status` DAN entitas `cctv_snapshots` sebagai sumber kebenaran kanonik. Diverifikasi via live-fire test nyata 16 Juli 2026 |
| Mode simulasi vs kamera real di **PRODUKSI** | ✅ **Dikonfirmasi definitif** — `.env.production`: `CCTV_USE_REAL=true`. Tabel `cctv_cameras` di database produksi: seluruh 5 kamera (satu per stage) berstatus `is_simulation=false`, mengarah ke kamera fisik yang sama (`192.168.18.38`). Produksi 100% berjalan di mode kamera real, tidak ada mode simulasi aktif untuk stage manapun |
| Konfigurasi mediamtx produksi | ✅ **Dikonfirmasi definitif, temuan sesi sebelumnya BENAR** — `docker/mediamtx/mediamtx.prod.yml` baris 45-58: path `cam_gate1`, `cam_wet`, `cam_warehouse` seluruhnya beralias ke `rtsp://localhost:8554/live_gate1`, dan `live_gate1` sendiri bersumber dari `rtsp://admin_minetra:Tapo123%21@192.168.18.38/stream2` — satu-satunya kamera fisik. Tidak ada jalur simulasi independen di produksi (berbeda dari `mediamtx.yml` lokal yang pakai `lavfi testsrc2` sintetis) |
| Jembatan jaringan (Tailscale) VPS ↔ kamera fisik | ❌ **Dikonfirmasi TIDAK AKTIF, dengan bukti definitif** — Tailscale terpasang & berjalan di VPS, tapi `tailscale status` hanya menunjukkan 2 peer (VPS itu sendiri + laptop pemilik), TIDAK ADA subnet router yang meng-advertise jaringan `192.168.18.0/24` tempat kamera berada. `ip route` VPS mengonfirmasi nol rute ke jaringan tersebut. Log mediamtx menunjukkan kegagalan koneksi berulang tiap ~15 detik: `dial tcp 192.168.18.38:554: i/o timeout` — pola ini SUDAH TERJADI SEJAK MINIMAL 13 Juli 2026 (terlihat dari riwayat `failed_jobs`), bukan kondisi baru |
| **Hasil live-fire test scan → snapshot di produksi (16 Juli 2026)** | ⚠️ **Snapshot GAGAL, sesuai desain graceful-failure, tapi butuh ~4 menit** — checkpoint tercatat sukses dalam < 1 detik (RFID tidak pernah menunggu CCTV), tapi `snapshot_status` checkpoint baru berubah dari `pending` → `failed` setelah 4 menit 12 detik (3× percobaan ffmpeg × timeout 60 detik + backoff 5/15/30 detik). `cctv_snapshots` mencatat `failure_reason: job_exhausted` dengan benar. **TIDAK ADA celah senyap** — setiap kegagalan tercatat dan dapat diaudit |
| Live-view HLS via nginx proxy | ✅ Path `/hls/cam_gate1/index.m3u8` dikonfirmasi ter-proxy nginx dengan benar (mengembalikan 404 konsisten karena stream sumber memang tidak aktif — bukan error proxy/CORS), sesuai arsitektur yang didokumentasikan |

**Implikasi untuk demo**: karena TIDAK ADA jembatan jaringan ke kamera
fisik saat ini, snapshot CCTV otomatis **akan gagal setiap kali** dipicu
di produksi sampai salah satu dari ini terjadi: (1) jembatan Tailscale
diaktifkan dengan benar (perlu perangkat/subnet-router di lokasi kamera
yang meng-advertise `192.168.18.0/24`), atau (2) kamera dipindah ke jalur
yang bisa dijangkau VPS secara langsung. **Ini bukan bug kode** — kode
CCTV terbukti benar dan robust lewat live-fire test; ini murni masalah
konektivitas jaringan fisik yang perlu ditangani di sisi infrastruktur,
bukan software.

---

## (f) Postur Keamanan

| Item | Status | Tindak Lanjut |
|---|---|---|
| SSL/TLS produksi | ✅ Valid, Let's Encrypt, berlaku s.d. 7 Oktober 2026, dikonfirmasi ulang 16 Juli 2026 | Tidak ada |
| Guard produksi Telescope | ✅ Aktif, dikonfirmasi ulang di kode produksi — `/telescope` → 404 | Tidak ada |
| Eksposur file sensitif (`.env`, `.git/config`, log) | ✅ Dikonfirmasi ke produksi langsung — `.env` dan `.git/config` → 403, `storage/logs/laravel.log` → 404. Tidak ada kebocoran | Tidak ada |
| Eksposur port container (db, redis, mediamtx) | ✅ Dikonfirmasi — hanya nginx (80/443) yang di-publish ke `0.0.0.0`. PostgreSQL (5432), Redis (6379), dan port mediamtx sepenuhnya internal ke Docker network, tidak reachable dari internet | Tidak ada |
| **Token otentikasi endpoint hardware RFID kosong** | ❌ **TEMUAN BARU, PRIORITAS TINGGI** — `RFID_API_TOKEN` kosong di `.env.production`, `validateToken()` fail-open (terima semua request tanpa token). Endpoint publik `/api/rfid/*` efektif tanpa proteksi | **(1)** Set `RFID_API_TOKEN` ke nilai rahasia di `.env.production`. **(2)** Update firmware ESP32 fisik untuk mengirim token yang sama via header `X-Rfid-Token`. **Kedua langkah harus dilakukan bersamaan** — mengatur token di server saja tanpa update firmware akan langsung memutus hardware yang sedang berjalan. Keputusan timing eksekusi ada di pemilik sistem |
| **Kredensial kamera `Tapo123!` bocor di kode** | ❌ **Masih ada**, dikonfirmasi ulang di produksi (`.env.production` baris 36, `mediamtx.prod.yml` baris 50) dan file lokal (`mediamtx.yml` baris 122). Password fisik kamera **dikonfirmasi belum dirotasi** oleh pemilik sistem | **(1)** Rotasi password fisik di perangkat Tapo C500 terlebih dahulu. **(2)** Parameterisasi YAML untuk membaca dari environment variable. **(3)** Pertimbangkan pembersihan riwayat git jika dianggap perlu — keputusan pemilik proyek |
| Kode dead/broken yang reachable (`ApiHardwareController`) | ⚠️ Dikonfirmasi ulang di produksi — route `POST /api/v1/hardware/rfid-scan` masih terdaftar dan memanggil `RfidService::processScan()` yang tidak ada di class tersebut, akan fatal error 500 jika benar-benar dipanggil. Route ini terpisah dari endpoint yang benar-benar dipakai ESP32 (`/api/rfid/*`, controller berbeda) | Bukan risiko keamanan aktif (tidak ada kebocoran info, `APP_DEBUG=false`), technical debt untuk sesi maintenance berikutnya |
| Multi-tenancy (`company_id` scoping) | ✅ Pola manual konsisten di semua file yang dibaca | Struktural rawan human-error jangka panjang, bukan bug aktif saat ini |
| Backup database otomatis | ⚠️ **Tidak ada** — hanya satu `pg_dump` manual (9 Juli 2026, sudah 7 hari) di `~/minetra-backups/`, tidak ada crontab terjadwal | Direkomendasikan menyiapkan backup otomatis harian (cron + `pg_dump` + rotasi retensi) — bukan krisis, tapi gap durabilitas data nyata yang layak diketahui sebelum ditanya |
| Rotasi log aplikasi | ⚠️ `config/logging.php` default ke channel `single` (file tunggal tak terbatas), bukan `daily`. Ukuran saat ini 7.0MB setelah ~3 hari uptime — belum mendesak, tapi akan tumbuh tanpa batas | Pertimbangkan ubah `LOG_CHANNEL`/`LOG_STACK` ke `daily` di `.env.production` saat ada jendela maintenance |
| Sesi pengguna hilang saat deploy | ℹ️ **Ditemukan sesi ini** — deploy produksi (`docker compose down && up`) menyebabkan seluruh sesi login aktif (termasuk sesi audit ini sendiri) ter-logout, kemungkinan besar data sesi Redis tidak bertahan penuh melalui siklus restart container. Bukan kerentanan keamanan, tapi berdampak operasional: jika ada deploy tepat sebelum presentasi, semua orang yang sedang login akan perlu login ulang | Informasional — rencanakan waktu deploy terakhir sebelum hari-H dengan jeda cukup untuk re-login |

---

## (g) Keterbatasan & Rencana Lanjutan

1. **Jembatan jaringan ke kamera fisik belum aktif** — ini SATU-SATUNYA
   blocker fungsional nyata untuk demo visual CCTV, dikonfirmasi dengan
   bukti definitif (lihat (e)). Kode dan arsitektur CCTV terbukti benar;
   murni masalah konektivitas fisik yang perlu ditangani sebelum hari-H
   jika visual CCTV ingin ditampilkan langsung.
2. **Token otentikasi RFID kosong** — gap keamanan nyata yang ditemukan
   sesi ini, memerlukan koordinasi server + firmware fisik untuk
   diperbaiki (lihat (f)). Tidak menghalangi demo, tapi sebaiknya
   ditangani segera setelah presentasi.
3. **Kredensial kamera fisik perlu dirotasi** — item keamanan yang sudah
   diidentifikasi dan dikomunikasikan sejak sesi sebelumnya, menunggu
   tindakan pemilik proyek di perangkat fisik.
4. **REST API v1 (Sanctum) adalah scaffolding, belum ada klien yang
   memakainya** — sengaja disiapkan untuk kemungkinan aplikasi mobile masa
   depan, bukan kebutuhan mendesak saat ini.
5. **Beberapa dead code teridentifikasi** (`ApiHardwareController`,
   `CreateLabAnalysisRequest`, field `rfid_tag` di form create batch),
   dikonfirmasi ulang masih ada di produksi — tidak berdampak pada fungsi
   sistem saat ini, direkomendasikan dibersihkan di sesi maintenance
   berikutnya.
6. **Multi-tenancy scoping manual** — berfungsi benar di semua kode yang
   diaudit, tapi secara arsitektur bergantung pada disiplin developer per
   query. Kandidat perbaikan arsitektur jangka menengah, bukan mendesak.
7. **Backup otomatis & rotasi log belum dikonfigurasi** — gap operasional
   nyata (lihat (f)), tidak mendesak untuk presentasi tapi layak
   ditindaklanjuti segera setelahnya.
8. **VPS git working tree satu commit di belakang origin** — deploy sesi
   ini dilakukan dengan menyalin 2 file yang sudah diperbaiki langsung via
   `scp` (diverifikasi checksum SHA-256 identik) karena VPS tidak punya
   kredensial GitHub non-interaktif untuk `git pull` otomatis. Isi file di
   produksi sudah benar dan sama persis dengan commit terbaru di
   `origin/main`, tapi metadata git VPS (`git log`) masih menunjuk ke
   commit sebelumnya sampai ada `git pull` manual berikutnya. Tidak
   berdampak fungsional, murni housekeeping.
9. **Batch uji `TMH.SGT-LK.MIT-260716-001` (id 28) masih ada di produksi**
   — dibuat untuk live-fire test sesi ini, diberi label jelas di catatan
   batch, belum dihapus menunggu keputusan pemilik sistem.
