# 01 — Inventaris Rename MINETRA → MINETRACE

Dasar: `grep -rIn -i minetra` (tanpa vendor, node_modules, .git, storage, build; 225 baris di luar `rfid-panel.html`, + 65 baris di `rfid-panel.html`).
Aturan: regex idempoten `(?i)minetra(?!ce)`; pemetaan MINETRA→MINETRACE, Minetra→Minetrace, minetra→minetrace. Satu komit per Kelas.

## Kelas A — diganti (teks yang dilihat manusia)
| Berkas | Baris |
|---|---|
| `resources/js/Layouts/AppLayout.vue` | 3, 435, 700 (teks/merek; 50 & 63 = Kelas B) |
| `resources/js/Layouts/AuthLayout.vue` | 80, 146, 175 |
| `resources/js/Pages/Auth/{Login,ForgotPassword,ResetPassword}.vue` | `<Head title>` |
| `resources/js/Pages/SuperAdmin/{Dashboard,Users/Index,Companies/Index}.vue` | teks subjudul |
| `resources/js/Pages/Company/Batches/Create.vue` | 811 (badge; dihapus bersama T2 di WS3) |
| `resources/js/lang/{id,en}.js` | komentar + `app.name`, `sign_in`, subtitle, onboard_desc |
| `resources/js/app.js` | 9 (fallback nama) |
| `resources/js/composables/core/useI18n.js` | 2 (komentar) |
| `resources/css/app.css` | 2, 346 (komentar) |
| `resources/views/app.blade.php` | 8 (fallback judul) |
| `resources/views/pdf/**` (9 berkas) | header/footer PDF; `coa.blade.php:189` fallback e-mail → `config('mail.from.address')` |
| `app/Notifications/ResetPasswordNotification.php` | 11, 37, 39, 43 |
| `app/Jobs/Reports/GenerateMonthlyReportJob.php` | 169 (subjek e-mail) |
| `app/Http/Controllers/Api/V1/Verify/ApiVerifyController.php` | 63 (pesan API) |
| `routes/api.php` 50, 70; `routes/web.php` 22; `routes/console.php` 9; `config/app.php` 128 | komentar & pesan health |
| `app/Services/Hardware/RfidService.php` 169; `Models/{User,Company}`, `Enums/UserRole`, `CompanyController`, `ApiBatchController` | pesan operator / docblock |
| `app/Services/Batch/QrCodeService.php` 13, 16 | docblock basi → ditulis ulang (format URL nyata) |
| `database/seeders/Core/{DatabaseSeeder,UserSeeder:11}.php`, `PtTimahCctvCameraSeeder.php` | komentar/banner |
| `start-local.sh` 5, 112; `stop-local.sh` 2; `deploy.sh` 9 | banner echo saja |
| `.env.local.example` 1; `docker/mediamtx/mediamtx*.yml` baris 1 | komentar/nama aplikasi |
| `.env.example` | `APP_NAME=MINETRACE` + blok pin SESSION_COOKIE/CACHE_PREFIX/REDIS_PREFIX |
| `README.md` | ditulis ulang |
| `PANDUAN_PENGGUNA_MINETRA.md`, `MINETRA_PROJECT_RESUME_COMPLETE.md` | `git mv` → `…MINETRACE…` + isi |
| `PRESENTASI_BRIEFING.md` 1, 9, 17; `ENVIRONMENT_MIGRATION_CHECKLIST.md` 1, 50, 53; `SKRIP_DEMO_LIVE.md` 1 | dokumen aktif |

## Kelas B — kode/kontrak data (diganti dengan kompatibilitas mundur)
| Berkas | Perubahan |
|---|---|
| localStorage `minetra-theme` / `minetra-lang` (`app.js`, `AppLayout.vue`, `useI18n.js`, `StatusBadge.vue`, skrip anti-FOUC `app.blade.php:30`) | kunci baru `minetrace-*`; baca kunci lama bila baru kosong → tulis baru → hapus lama |
| `Company/Reports/Index.vue:165` | prefiks unduhan `MINETRACE-Report-…` |
| `app/Jobs/CaptureCctvSnapshotJob.php:249` | prefiks temp `minetrace_probe_` |

## Kelas C — infrastruktur (TIDAK diganti; lihat `RENAME_INFRA_PLAN.md`)
`docker-compose.yml` (15 baris), `docker-compose.prod.yml` (17), `start-local.sh` baris 29–31, 58, 65–69 (fungsional), `.env.local.example` 14–16, `docker/nginx/conf.d/app.conf` 20/24, `docker/postgres/init/01_init.sql:6`, nama jaringan `minetra_minetra_net`, jalur `~/minetra-backups/`, user SSH VPS, seeder e-mail `superadmin@minetra.id` (K7).

## Kelas D — keamanan & usang
| Berkas | Tindakan |
|---|---|
| `public/rfid-panel.html` (65 baris) | dihapus di komit terpisah (K4; tak dirujuk, 3 endpoint tak ada) |
| `docker/mediamtx/mediamtx.yml:122`, `mediamtx.prod.yml:50`, `.env.example:80` | kredensial kamera plaintext — TIDAK diubah (bisa memutus kamera); rekomendasi rotasi + pindah ke ENV di WS5 |

## Allowlist sisa grep setelah rename (dengan alasan)
1. Semua Kelas C di atas — identifier infrastruktur (L5).
2. `database/migrations/2026_01_01_000001_add_minetra_fields_to_users_table.php` (nama berkas + komentar) dan komentar di `…000000_create_companies_table.php` — migrasi lama tidak boleh diedit/diganti nama (L4).
3. `database/seeders/Core/UserSeeder.php` baris 17 & 45 — e-mail akun `@minetra.id` (K7).
4. Kredensial kamera pada `.env.example:80`, `mediamtx*.yml` — username perangkat.
5. Kunci lama `minetra-theme`/`minetra-lang` pada kode migrasi preferensi (`resources/js/lib/brand.js`, skrip anti-FOUC) — kompatibilitas mundur.
6. Dokumen historis: `AUDIT_PRODUKSI_2026-07-16.md` (hanya ditambah catatan di atasnya), entri lama di RESUME, rujukan nama jaringan/jalur VPS di PRESENTASI_BRIEFING (62, 203).
7. `docs/minetrace/*` — menyebut nama lama saat menjelaskan rename.
8. `package-lock.json`/`package.json` nama paket npm (`name`) — bukan tampilan; diganti hanya bila aman.

## Dampak operasional `APP_NAME` (untuk pemilik)
`SESSION_COOKIE`, `CACHE_PREFIX`, `REDIS_PREFIX` diturunkan dari `APP_NAME` dan TIDAK di-pin di `.env*` mana pun. Mengganti `APP_NAME` produksi → semua pengguna logout, cache kosong, job Redis lama tak terlihat worker. Sebelum mengganti `APP_NAME` di `.env.production`, tambahkan:
```
SESSION_COOKIE=minetra_session
CACHE_PREFIX=minetra_cache_
REDIS_PREFIX=minetra_database_
```
atau deploy saat antrean kosong dan pengguna siap login ulang. `.env.production` tidak disentuh oleh sesi ini.
