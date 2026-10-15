# 00 — Laporan Pemahaman (Fase 0)

Branch `feat/minetrace-rebrand-ux-rfid` (dari `main` @ `fddf183`). Stack terverifikasi: Laravel 11, PHP 8.3, PostgreSQL, Vue 3.5 + Inertia.
Status: **Fase 0 selesai sebagian** — pemetaan kode + verifikasi T1–T14 selesai; penelusuran UI interaktif dan screenshot BELUM (lihat §7).

## 1. Peta sistem (ringkas)
- Rute: `routes/web.php` (sesi, `role:` Spatie berbasis string) dan `routes/api.php` (`/api/rfid/*` legacy, `/api/v1/*`, Sanctum). Peran ditegakkan lewat string role; 41 permission di seeder **tidak pernah ditegakkan** (tidak ada `can()`/Policy).
- Tahap: `lab_kimia → receiving → wet_process → dry_process → warehouse`; `warehouse → project_plant → warehouse` (jalur LTJ). `BatchStage` enum punya 9 nilai (+ smelter, exported, terminated).
- RFID: `RfidService` + 3 Strategy (`audit_only`, `scan_to_receive`, `scan_to_dispatch`); `batches.rfid_tag` UNIQUE(50); sesi registrasi = kunci cache TTL 90 dtk; mode aktif = cache TTL 1 jam; tiap scan men-dispatch `CaptureCctvSnapshotJob`.
- Tes: 18 tes (17 lulus, 1 gagal sebelum perubahan: `BatchCreationTest > batch from different company is unauthorized`, FK `product_codes` pada fixture). Nol tes untuk RFID/Process/CCTV/RBAC/verify.

## 2. Alur teks
**Registrasi**: UI `POST /api/v1/company/rfid/pending-batch` → cache `rfid_pending_batch_{co}` + `_global` → ESP32 `POST /api/rfid/register` → ikat UID ke batch + checkpoint `lab_kimia_create`.
**Scan IN/OUT**: ESP32 `POST /api/rfid/login|logout` → `findBatchByUid` → strategi per tahap → `Checkpoint::create` → job CCTV (tidak pernah menunggu CCTV).

## 3. Verifikasi T1–T14
| T | Putusan | Bukti |
|---|---|---|
| T1 | **TERBUKTI P0** | `route:list` hanya `api/v1/verify/batch/{id}`; request nyata ke URL QR → **404**. API publik tanpa throttle (docblock "10 req/mnt" basi) dan membuka berat + lokasi. |
| T2 | Terbukti | `BatchController:275` meneruskan `rfid_tag`, tetapi `BatchService::createBatch` tidak menyimpannya (dibuang diam-diam). Plus validasi hex ≥8 menolak hasil scan barcode/QR yang dijanjikan UI. Show.vue tanpa tombol daftar tag. |
| T3 | Terbukti, **lebih buruk** | Kunci global `rfid_pending_batch_global` + fallback lintas-perusahaan di `registerBatchTag`, `getPendingBatch`, `getUntaggedBatches/TaggedBatches`, `findBatchByUid`; `?? 1` di banyak tempat. Kebocoran lintas-tenant nyata. |
| T4 | Terbukti sebagian | Mode = toggle cache per perusahaan+tahap. `rfid_readers` TIDAK ADA di repo ini (lihat §6). |
| T5 | Terbukti | Konfigurasi tahap tersebar di ≥11 tempat (STAGES, STAGE_FLOW, 3 Strategy, UserRole, HandleInertiaRequests, enum DB, AppLayout, dll). Ketidakkonsistenan nyata: label ("Project Plant" vs "Lab Analisis LTJ"), `STAGE_FLOW` tanpa `lab_kimia`. |
| T6 | Terbukti | Tidak ada dedupe; tiap scan = checkpoint + job CCTV baru. `rfid_origin` ditentukan header yang dapat dipalsukan. |
| T7 | Terbukti | CSRF dikecualikan untuk `api/*`; hanya `SameSite=lax` yang melindungi. Plus: cek peran tidak seragam di `ApiRfidController`, IDOR `CctvController::snapshot`, `bim/alerts` lintas-tenant. |
| T8 | Terbukti | `removeTag` hanya `rfid_tag=null` + log; tanpa checkpoint audit. UNIQUE mencakup baris soft-deleted tetapi cek aplikasi tidak → 500. `max:100` vs kolom 50. |
| T9 | Terbukti | 350× `text-[Npx]` (hingga 7–9px), 246 hex, 1.292 kelas palet mentah, transisi global di `*`, 14 handler hover imperatif, ±349 baris emoji-ikon, favicon 0 byte, 0 `aria-*`, 0 `prefers-reduced-motion`. |
| T10 | Terbukti sebagian | Kamus 495=495 kunci (597/539 itu jumlah BARIS), tetapi kamus nyaris tak dipakai; 617 ternary inline; 10 halaman tanpa i18n. |
| T11 | Terbukti | Tanpa CI; `package.json` hanya `dev`/`build`; `DEPLOYMENT.md` 0 B; log `single`; `cloudflared*.deb` (18 MB) ter-track; `deploy.sh` tanpa backup sebelum migrate. |
| T12 | Terbukti | `rfid-panel.html` 65 baris "minetra", tak dirujuk apa pun, 3 endpoint-nya (`/api/rfid/status|users|pending`) tidak ada. |
| T13 | Terbukti, **rusak** | `rfidScan` → `processScan()` dan `rfidAssign` → `assignTagToBatch()` tidak ada → 500. Tak dipanggil frontend. |
| T14 | Terbukti, **lebih buruk** | `force_password_change` ada tetapi **tidak ditegakkan** di mana pun; seeder mencetak tabel kredensial ke konsol; `CctvCamera.rtsp_url` plaintext. |

## 4. Temuan baru
1. `routes/console.php` mengimpor `App\Jobs\GenerateMonthlyReportJob` (kelas ada di `App\Jobs\Reports\`) → scheduler laporan bulanan fatal; antrean `reports` juga tak diproses worker.
2. `forwardBatch` memindahkan `process_stage` langsung, sedangkan `receiveBatch` mensyaratkan batch masih di tahap sebelumnya → langkah "Terima" tak pernah valid; status akhir beda (`in_process` vs `in_transit`). Perlu keputusan semantik (§8-Q3).
3. `recordCheckpoint` tidak memeriksa tahap batch maupun `STAGE_ROLES`; tanpa `lockForUpdate` pada transisi.
4. Inertia klien 3.1.0 vs adapter server `inertia-laravel` 1.3.4 (mayor berbeda); `app.js` memuat semua halaman eager (bundel 1,1 MB) dan masih `console.log` di produksi.
5. `components/ui/*` (±100 berkas) dan pinia tidak dipakai; `tailwindcss-animate` tak terdaftar.
6. Data simulasi (`rfid_origin=simulation`) ikut masuk laporan PDF/Excel.
7. Token Sanctum tanpa kedaluwarsa; `v1/auth/login` tanpa throttle; `RFID_API_TOKEN` fail-open.
8. Peringatan produksi (hanya dicatat, tidak disentuh): AUDIT mencatat SSL s.d. 7 Okt 2026 — sudah lewat; pastikan certbot memperbarui. Beberapa dokumen repo menuliskan kata sandi kamera plaintext.

## 5. Inventaris rename awal (226 baris, ±68 berkas)
A ≈ 100 · B ≈ 12 (localStorage, nama unduhan `MINETRA-Report-…`, prefiks temp) · C ≈ 47 (compose, DB/user, container, `fastcgi_pass`) · D ≈ 68 (65 = `rfid-panel.html`, 3 = kredensial). Tidak ada nama permission/role/cache key bermuatan "minetra". `APP_NAME` mengendalikan `SESSION_COOKIE`, `CACHE_PREFIX`, `REDIS_PREFIX` (tak ada yang di-pin di `.env*`) → pin dulu sebelum rilis.

## 6. Risiko
- **Kode Fase 7 yang dijelaskan brief tidak ada di checkout ini** (commit `05edd2c`, `rfid_readers`, `X-Device-Key`, `heartbeat`, `ApiRfidDeviceController`). Kontrak L3 sebagian tak dapat dikunci sebelum ini jelas.
- Rebrand → logout massal bila `APP_NAME` berubah tanpa pin.
- Kebocoran lintas-tenant RFID sudah aktif; perbaikannya menyentuh jalur ESP32 (harus aditif + contract test).

## 7. Belum dikerjakan di Fase 0 (jujur)
Penelusuran UI interaktif dengan akun seeder (butuh browser) dan screenshot sebelum/sesudah: Playwright/Chromium tidak terpasang. `npm run build` baseline belum dijalankan. Alur register→scan hanya ditelusuri lewat kode, belum dijalankan.

## 8. Rencana (estimasi relatif) & pertanyaan terbuka
WS1 (M) → WS2 (L, nilai utama) → WS3 (M; T1/T2/i18n diperbaiki) → WS4 (L, nilai utama) → WS5 (S–M, dokumen).
Pertanyaan: lihat pesan checkpoint (Q1–Q5 + K1–K9).
