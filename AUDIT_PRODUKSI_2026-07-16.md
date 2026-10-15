> Catatan: nama produk saat dokumen ini ditulis adalah MINETRA (kini MINETRACE). Dokumen historis — isi tidak diubah.

# MINETRA — Audit Produksi Langsung (16 Juli 2026)

> Dokumen referensi teknis mentah. Untuk ringkasan presentasi, lihat
> `PRESENTASI_BRIEFING.md`. Untuk skrip demo, lihat `SKRIP_DEMO_LIVE.md`.
> Setiap klaim di sini ditelusuri dari perintah nyata yang dijalankan
> langsung terhadap VPS produksi (`212.85.24.189`,
> `https://mining-traceability.com`) sesi ini, bukan asumsi atau ekstrapolasi
> dari lingkungan lokal.

---

## 1. Checklist Verifikasi — Status Akhir

| # | Item | Status | Bukti |
|---|---|---|---|
| 1.1 | Kesehatan umum produksi | ✅ | 7 container up, disk 19% terpakai (79G tersisa), RAM sehat (6.8G available), SSL valid s.d. 7 Okt 2026, DNS bersih (IPv4-only), tidak ada migrasi pending |
| 1.2 | Mode CCTV: simulasi vs real | ✅ Real, terkonfirmasi | `CCTV_USE_REAL=true`, 5/5 kamera produksi `is_simulation=false`, semua path mediamtx beralias ke satu kamera fisik |
| 1.3 | Jembatan Tailscale ke kamera fisik | ❌ Tidak aktif | `tailscale status`: 2 peer saja (VPS + laptop), tidak ada subnet router; `ip route`: nol rute ke `192.168.18.0/24` |
| 1.4 | Integritas network Docker (no split-network regression) | ✅ | 6/7 container di `minetra_minetra_net` yang sama; hanya `certbot` beda network (`minetra_default`, expected — tidak butuh akses DB/app) |
| 1.5 | RFID Progressive Authorization di DB produksi | ✅ | Tabel `rfid_authorization_modes` kosong (0 baris) → kode fallback eksplisit ke `audit_only` untuk semua stage — perilaku aman terkonfirmasi aktif |
| 1.6 | Rotasi kredensial Tapo | ❌ Belum dirotasi | `Tapo123!`/`Tapo123%21` masih plaintext di `.env.production:36`, `mediamtx.prod.yml:50`, `mediamtx.yml:122` (lokal) |
| 1.7a | Bug `rfid_tag` hilang saat create batch | ❌ Masih ada | `BatchService::createBatch()` tidak menyertakan `rfid_tag` di `Batch::create([...])` meski diterima dari controller |
| 1.7b | Sanctum ter-install | ✅ | `composer.lock` berisi `laravel/sanctum` |
| 1.7c | Dead code `ApiHardwareController::rfidScan()` | ❌ Masih ada | Memanggil `RfidService::processScan()` yang tidak ada — route `POST /api/v1/hardware/rfid-scan` masih terdaftar, terpisah dari endpoint asli ESP32 |
| 1.7d | `worker` `depends_on` `service_healthy` | ✅ Sudah benar | `docker-compose.prod.yml`: `db`/`redis` pakai `condition: service_healthy`, `mediamtx` pakai `service_started` — kontras dengan asumsi awal bahwa ini mungkin masih bermasalah |
| — | **Temuan baru**: `RFID_API_TOKEN` kosong | ❌ Kerentanan keamanan | `.env.production`: `RFID_API_TOKEN=` (kosong). `RfidService::validateToken()` baris 802-807: fail-open jika token kosong. Endpoint hardware publik efektif tanpa otentikasi |
| — | Bug respons HTML endpoint RFID | ❌→✅ Ditemukan & diperbaiki sesi ini | Reproduksi dikonfirmasi, diperbaiki, di-deploy ke produksi, diverifikasi ulang (detail bagian 3) |

---

## 2. Live-Fire End-to-End Test — Bukti Mentah

Seluruh langkah dijalankan langsung terhadap `https://mining-traceability.com`
menggunakan kredensial produksi asli (`admin@ptimah.co.id`, role
`company_admin`, `company_id=2` / PT Timah Tbk).

### 2.1 — Buat batch uji disposable

```
POST https://mining-traceability.com/company/batches
  product_code_id=1 (MIT), location_id=1 (Sungailiat), initial_weight=12.5
  notes=[AUDIT-TEST 2026-07-16] Batch disposable untuk live-fire E2E test produksi.
→ HTTP 302 → /company/batches/28
```

Hasil di database: `id=28, batch_code=TMH.SGT-LK.MIT-260716-001,
process_stage=lab_kimia, status=active`.

### 2.2 — Registrasi tag RFID

```
POST /api/v1/company/rfid/pending-batch  {"batch_id":28}
→ {"success":true,"message":"Sesi registrasi aktif..."}

POST /api/rfid/register  {"uid":"AUDIT16JUL2026TEST"}
→ {"status":"success","message":"Tag terdaftar!","batch_id":28,...}
```

### 2.3 — Scan IN (trigger CCTV pipeline)

```
POST /api/rfid/login  {"uid":"AUDIT16JUL2026TEST","stage":"receiving"}
→ HTTP 200, {"status":"success","message":"Scan IN OK",
   "manual_mode":true,"scan_stage":"receiving", timestamp 2026-07-16 12:53:03 WIB}
```

Log worker: `App\Jobs\CaptureCctvSnapshotJob ................ RUNNING`
(05:53:06 UTC — dispatch delay 2 detik + job start, sesuai desain).

### 2.4 — Resolusi job (dipantau real-time)

| Waktu (UTC) | Event |
|---|---|
| 05:53:03 | Scan IN diterima, checkpoint id 244 dibuat (`snapshot_status: pending`) |
| 05:53:06 | Job attempt #1 mulai |
| 05:54:38 | Job attempt #2 mulai (setelah timeout 60s + backoff) |
| 05:56:10 | Job attempt #3 mulai |
| 05:57:15 | Job gagal total setelah 3 percobaan — log: `CaptureCctvSnapshotJob ............. 1m 4s FAIL` |
| 05:57:15 | `failed()` handler jalan → checkpoint di-update `snapshot_status: failed` |

**Total waktu dari scan hingga status akhir: 4 menit 12 detik.**

Root cause tiap percobaan (dari log mediamtx, berjalan independen sejak
sebelum test): `dial tcp 192.168.18.38:554: i/o timeout` — kamera fisik
tidak reachable dari VPS.

### 2.5 — Verifikasi state akhir di database

```sql
SELECT id, snapshot_status, capture_attempted FROM checkpoints WHERE id=244;
→ 244 | failed | t

SELECT photos FROM checkpoints WHERE id=244;
→ [{"type":"snapshot_failed","scan_type":"scan_in","stage":"receiving",
    "rfid_uid":"AUDIT16JUL2026TEST","camera_name":"Unknown","mode":"simulation",
    "failed_at":"16/07/2026 12:57:15 WIB","reason":"job_exhausted"}]

SELECT id, checkpoint_id, capture_status, failure_reason FROM cctv_snapshots WHERE batch_id=28;
→ 63 | 244 | failed | job_exhausted
```

**Kesimpulan**: TIDAK ADA celah senyap. Kegagalan tercatat lengkap dan
konsisten di kedua tempat (`checkpoints.photos` mirror lama +
`cctv_snapshots` sebagai sumber kebenaran kanonik). Satu catatan minor:
entri kegagalan menunjukkan `camera_name: "Unknown"` dan `mode:
"simulation"` meski kamera nyata (`is_simulation=false`) yang sebenarnya
dipakai — ini karena callback `failed()` Laravel menulis entri fallback
tanpa akses ke variabel `$camera`/`$captureMode` yang sudah di-resolve di
`handle()` (scope terpisah). Kosmetik, tidak memengaruhi status
fungsional (`snapshot_status`/`capture_status` tetap benar `failed`).

### 2.6 — Generate laporan (PDF + Excel)

```
POST /company/reports/generate
  report_type=batch_traceability, format=pdf, date_from=2026-07-01, date_to=2026-07-16
→ HTTP 200, BatchTraceability-TIMAH-20260701-20260716.pdf, 1,131,316 bytes,
  PDF 1.7 valid (xref/trailer/%%EOF terkonfirmasi)

  report_type=mass_balance, format=excel, date_from=2026-07-01, date_to=2026-07-16
→ HTTP 200, MassBalance-TIMAH-20260701-20260716.xlsx, 6,679 bytes,
  Microsoft Excel 2007+ (signature file terkonfirmasi)
```

---

## 3. Deploy Perbaikan ke Produksi (dilakukan sesi ini)

**Bug**: endpoint `/api/rfid/*` mengembalikan HTML redirect (302) alih-alih
JSON error saat request tanpa header `Accept`. Dikonfirmasi reproducible
di produksi:

```
POST /api/rfid/register  (tanpa header Accept)  {}
→ SEBELUM fix: HTTP 302, Content-Type: text/html, body <!DOCTYPE html>...
→ SESUDAH fix: HTTP 422, Content-Type: application/json,
   {"message":"The uid field is required.","errors":{"uid":[...]}}
```

**Fix kedua yang ikut di-deploy**: fungsi `snapshotStatusBadge()` yang
hilang di `resources/js/Pages/Company/Batches/Show.vue` (dipanggil 6× di
template, tidak pernah didefinisikan — crash render pada checkpoint
dengan `snapshot_status` non-null).

**Proses deploy** (dijalankan step-by-step, bukan otomatis penuh, karena
kendala kredensial git non-interaktif di kedua sisi — lihat catatan di
bawah):

1. Commit lokal (`287d3b0`), push ke `origin/main` oleh pemilik proyek
   (kredensial GitHub tidak tersedia non-interaktif di sandbox audit).
2. VPS tidak punya kredensial GitHub non-interaktif untuk `git pull`
   otomatis (dikonfirmasi: `fatal: could not read Username for
   'https://github.com'` dari kedua sisi). **Solusi**: 2 file yang
   diperbaiki di-`scp` langsung ke VPS, diverifikasi checksum SHA-256
   identik dengan versi lokal sebelum lanjut.
3. `docker compose -f docker-compose.prod.yml build --no-cache app worker`
   — sukses, ~73 detik per image.
4. `docker compose -f docker-compose.prod.yml down --remove-orphans` →
   `up -d` — seluruh 7 container recreated & healthy.
5. `nginx -t` dijalankan pasca-restart — **syntax OK** (hanya warning
   deprecation `listen ... http2` yang sudah ada sebelumnya, bukan error
   baru).
6. `npm ci && npm run build` di container `app` (asset frontend ditulis ke
   host via bind mount `public/build`, sesuai catatan di `deploy.sh`).
7. `php artisan migrate --force` → "Nothing to migrate" (tidak ada skema
   baru). `optimize:clear` + `optimize` — sukses.
8. Verifikasi pasca-deploy: bug re-test → JSON benar. Situs tetap sehat
   (`/` → 302, `/login` → 200, `/.env` → 403).

**Efek samping yang teramati**: seluruh sesi login aktif (termasuk sesi
audit ini) ter-logout setelah `down`/`up` — kemungkinan data sesi Redis
tidak bertahan penuh melalui siklus restart container. Bukan kerentanan
keamanan, tapi relevan untuk timing operasional (lihat
`PRESENTASI_BRIEFING.md` bagian f).

**Status git VPS pasca-deploy**: `git log` di VPS masih menunjuk ke commit
`12ad584` (satu di belakang `origin/main`), karena deploy dilakukan lewat
`scp` bukan `git pull`. Isi file identik (checksum-verified) dengan
`origin/main`, jadi tidak ada dampak fungsional — murni metadata git yang
akan sinkron otomatis pada `git pull` manual berikutnya.

---

## 4. Sweep Keamanan & Stabilitas

| Area | Hasil |
|---|---|
| Eksposur port (ufw) | `sudo ufw status` tidak dapat dijalankan (butuh password sudo, tidak tersedia sesi ini) — **tapi** `docker compose ps` mengonfirmasi hanya nginx (80/443) yang di-publish ke `0.0.0.0`; db/redis/mediamtx tidak punya binding host sama sekali (port hanya reachable di dalam Docker network). Docker memanipulasi iptables langsung untuk `-p`, jadi tidak adanya publish di level Docker sudah cukup sebagai bukti kuat tanpa perlu ufw |
| Eksposur file sensitif | `.env` → 403, `.git/config` → 403, `storage/logs/laravel.log` → 404, `/telescope` → 404. Seluruhnya benar |
| Backup database | Hanya 1 `pg_dump` manual (`minetra_db_20260709_091808.sql`, 9 Juli 2026, 675KB), tidak ada crontab (`crontab -l` → "no crontab for minetra"). **Gap nyata** — tidak ada backup terjadwal otomatis |
| Ukuran log | `storage/logs/laravel.log`: 7.0MB. `config/logging.php` default ke channel `single` (tak terbatas), bukan `daily`. Belum mendesak tapi akan tumbuh tanpa batas |
| Resource usage | Seluruh container sehat: CPU <3%, memori jauh di bawah limit 7.75GB. `mediamtx` menunjukkan NET I/O tinggi (1.49GB) — konsisten dengan retry loop koneksi kamera fisik yang gagal terus-menerus, bukan tanda masalah stabilitas, tapi pemborosan resource ringan yang akan hilang begitu bridge jaringan diperbaiki |
| `failed_jobs` | 6 total, seluruhnya `TimeoutExceededException` pada `CaptureCctvSnapshotJob` — 5 dari riwayat sejak 13 Juli 2026 (mengonfirmasi masalah bridge kamera sudah berlangsung beberapa hari, bukan baru), 1 dari live-fire test sesi ini |

---

## 5. Item yang Sengaja TIDAK Diperbaiki (di luar scope audit)

- **`RFID_API_TOKEN` kosong** — perbaikan butuh koordinasi dua sisi
  (server + firmware ESP32 fisik), berisiko memutus hardware yang sedang
  berjalan jika hanya salah satu sisi diubah. Direkomendasikan ke pemilik
  sistem, bukan dieksekusi sepihak.
- **Kredensial Tapo `Tapo123!`** — menunggu rotasi password fisik oleh
  pemilik sistem (dikonfirmasi belum dilakukan).
- **Jembatan Tailscale ke kamera** — memerlukan perangkat/subnet-router di
  lokasi kamera, di luar kemampuan perbaikan lewat SSH/kode.
- **Bug `rfid_tag` hilang saat create batch** — bukan blocker demo (alur
  yang dipakai adalah registrasi dua langkah terpisah, sudah berfungsi
  benar), risiko regresi jika diburu-buru perbaiki mendekati hari-H.
- **Dead code `ApiHardwareController`** — tidak reachable dari alur nyata,
  technical debt untuk sesi maintenance biasa.
- **Backup otomatis & rotasi log** — perbaikan operasional yang lebih baik
  dilakukan di luar jendela waktu genting menjelang presentasi.

---

## 6. Data Uji yang Perlu Tindak Lanjut Pemilik Sistem

- Batch `id=28` (`TMH.SGT-LK.MIT-260716-001`) — batch disposable, berlabel
  jelas `[AUDIT-TEST 2026-07-16]`, belum dihapus.
- Checkpoint `id=242,243,244` dan `cctv_snapshots.id=63` — terkait batch
  di atas, sama-sama disposable.

Tidak dihapus sesi ini karena instruksi audit eksplisit meminta konfirmasi
pemilik sebelum operasi hapus apa pun. Aman dihapus/soft-delete kapan saja
— cukup jelaskan ke pemilik bahwa ini murni data uji, tidak terkait riset
asli.
