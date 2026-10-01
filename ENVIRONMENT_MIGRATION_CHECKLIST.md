# MINETRA — Referensi Environment (Lokal vs Produksi)

> Ditulis ulang 16 Juli 2026. Versi sebelumnya adalah checklist cutover
> produksi yang isinya sudah mayoritas selesai/usang (Sanctum sudah
> terpasang, service `mediamtx` sudah ada di `docker-compose.prod.yml`,
> domain produksi sudah stabil). File ini menyimpan hanya bagian yang
> masih punya nilai referensi evergreen — bukan catatan migrasi
> database (`php artisan migrate`), murni perbedaan konfigurasi env
> antar lingkungan.

## 1. Tabel perbedaan `.env` — lokal vs produksi

| Variabel | Lokal (dev) | Produksi | Catatan |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | |
| `APP_DEBUG` | `true` | `false` | **Wajib** `false` di produksi — jangan pernah diubah untuk debugging cepat, ganti lewat log (`storage/logs/laravel.log`) saja |
| `APP_URL` | `http://localhost:8000` (atau setara) | `https://mining-traceability.com` | Lihat peringatan QR code di bagian 2 |
| `SESSION_SECURE_COOKIE` | `false` | `true` | Produksi wajib `true` karena HTTPS-only |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost` | `mining-traceability.com` | Sanctum terpasang tapi saat ini tidak dipakai frontend (auth pakai session `web`+`auth`) |
| `CCTV_USE_REAL` | `false` | `true` (nominal) | **Bukan** sumber kebenaran runtime — hanya default seed. Sumber kebenaran aktual adalah kolom `is_simulation` per baris di tabel `cctv_cameras` |
| `CCTV_RTSP_URL` | URL simulasi mediamtx lokal | URL kamera fisik Tapo C500 (LAN privat) | ⚠️ Kredensial `Tapo123!` masih tertanam di file konfigurasi terlacak per audit 14 Juli 2026 — belum dirotasi pemilik sistem |
| `CCTV_HLS_BASE_URL` | `http://localhost:8888` | `http://<server>:8888` atau via nginx proxy | |
| `CCTV_MEDIAMTX_RTSP` / `CCTV_MEDIAMTX_API` | `rtsp://mediamtx:8554` / `http://mediamtx:9997` | sama (nama service Docker internal, tidak berubah) | |
| `RFID_API_TOKEN` | token dev | token produksi terpisah | `RfidService::validateToken()` membaca `env()` langsung, bukan `config()` — perubahan token butuh restart proses PHP, bukan cuma `config:cache` |
| `RFID_DEFAULT_COMPANY_CODE` | `TIMAH` | `TIMAH` | |
| `DB_HOST` | `db` (docker) / `127.0.0.1` | host DB produksi | |
| `CACHE_STORE` | `redis` atau `file` | `redis` | |

## 2. ⚠️ Peringatan domain QR code

`QrCodeService::buildPayload()` memanggil `config('app.url')` **pada
saat batch dibuat**, lalu nilainya dicetak ke QR code fisik. Artinya
jika `APP_URL` berubah setelah batch sudah dibuat dan QR-nya sudah
dicetak/ditempel di lapangan, QR lama akan mengarah ke domain yang
salah dan **tidak bisa diperbaiki tanpa cetak ulang QR fisik**.

**Implikasi praktis**: jangan ubah `APP_URL` produksi setelah go-live
kecuali benar-benar diperlukan (mis. migrasi domain), dan jika terpaksa,
rencanakan pencetakan ulang QR untuk semua batch yang masih aktif di
lapangan sebagai bagian dari rencana migrasi — bukan langkah opsional.

## 3. Status infrastruktur (per audit terakhir)

Sudah selesai dan tidak perlu diverifikasi ulang tiap deploy:
- Sanctum 4.3 terpasang (`composer.json`), meski tidak dipakai frontend.
- Service `mediamtx` ada di `docker-compose.prod.yml`.
- ffmpeg tersedia di image Docker (`Dockerfile`).
- nginx conf produksi hardcode `mining-traceability.com` (SSL/certbot
  dikonfirmasi stabil — jangan disentuh tanpa bukti konkret ada yang
  rusak, lihat `MINETRA_PROJECT_RESUME_COMPLETE.md`).

Masih perlu perhatian (didokumentasikan lengkap di
`MINETRA_PROJECT_RESUME_COMPLETE.md` dan `PRESENTASI_BRIEFING.md`):
- `mediamtx.prod.yml` meng-alias semua jalur kamera (termasuk yang
  berlabel "simulasi") ke satu sumber RTSP fisik yang sama — beda
  struktural dari `mediamtx.yml` lokal.
- Kredensial `Tapo123!` belum dirotasi oleh pemilik sistem.
