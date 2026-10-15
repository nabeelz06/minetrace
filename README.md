# MINETRACE — Mineral Traceability

Sistem Ketertelusuran Mineral · *Mineral Traceability System*

Platform web ketertelusuran Logam Tanah Jarang (LTJ) untuk BIM (Badan Industri Mineral) dan mitra produksi
(mis. PT Timah). Melacak batch dari Lab Kimia → Receiving → Wet → Dry → Warehouse (→ Project Plant → Ekspor)
dengan checkpoint, RFID (ESP32/RC522), snapshot CCTV, laporan PDF/Excel, dan QR verifikasi.
Sebelumnya bernama MINETRA.

## Stack
Laravel 11 (PHP ≥ 8.2) · Inertia + Vue 3 · Tailwind 3 · Vite 6 · PostgreSQL · Redis · MediaMTX (RTSP→HLS) · spatie/permission.

## Menjalankan lokal
```bash
cp .env.local.example .env     # atau: bash start-local.sh (menimpa DB/Redis/CCTV di .env — cadangkan dulu)
composer install && npm ci
php artisan key:generate && php artisan migrate --seed
php artisan serve & php artisan queue:work --tries=3 --timeout=60 & npm run dev
```
Docker lokal (Postgres, Redis, MediaMTX): `bash start-local.sh` / `bash stop-local.sh`.

## Tes
Tes memakai `RefreshDatabase` dan **menghapus isi database yang ditunjuk**. Gunakan database terpisah lewat
`.env.testing` (tidak di-commit), mis. `DB_DATABASE=minetrace_test`, lalu `php artisan test`.

## Dokumentasi
- `PANDUAN_PENGGUNA_MINETRACE.md` — panduan pengguna
- `MINETRACE_PROJECT_RESUME_COMPLETE.md` — ringkasan proyek & changelog
- `docs/minetrace/` — pemahaman sistem, audit, rekomendasi, kesiapan hardware
- `DEPLOYMENT.md` — runbook rilis

Produksi: https://mining-traceability.com
