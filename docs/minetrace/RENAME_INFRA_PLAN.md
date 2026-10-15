# RENAME_INFRA_PLAN — Migrasi identifier infrastruktur `minetra_*` → `minetrace_*`

**Status: RENCANA SAJA. Tidak dieksekusi tanpa persetujuan tertulis pemilik (L5, K7).**
Mengganti nama ini tidak mengubah apa pun yang dilihat pengguna; manfaatnya hanya konsistensi. Risikonya nyata (volume baru yang kosong, downtime, putusnya `.env`). Rekomendasi: **tunda**, kecuali ada alasan lain yang mengharuskan downtime/migrasi VPS (mis. pindah VPS yang sedang direncanakan — saat itu biaya tambahan hampir nol).

## Identifier yang terdampak
| Identifier | Lokasi | Catatan |
|---|---|---|
| Container `minetra_{nginx,app,db,redis,mediamtx,worker,certbot}` | `docker-compose.prod.yml` | `container_name` |
| Network `minetra_net` (efektif `minetra_minetra_net`) | compose prod | nama proyek compose ikut menjadi prefiks |
| Volume `db_data`, `redis_data`, `app_storage`, `certbot_*` | compose prod | nama efektif = `<proyek>_<volume>`; **mengganti nama proyek/direktori = volume baru kosong** |
| DB `minetra_db`, user `minetra_user` | compose prod, `.env.production` | butuh dump-restore |
| Lokal: `minetra_postgres_local`, `minetra_redis_local`, `minetra_mediamtx_local`, volume `minetra_pg_local`/`minetra_redis_local`, network `minetra-local`, DB `minetra_local`, user `minetra` | `docker-compose.yml`, `start-local.sh`, `.env.local.example` | |
| `fastcgi_pass minetra_app:9000` | `docker/nginx/conf.d/app.conf` (lokal; produksi memakai `app:9000`) | |
| `GRANT … minetra_master` | `docker/postgres/init/01_init.sql` | nama DB usang; init hanya jalan pada volume kosong |
| Jalur/user VPS, nama repo, domain `mining-traceability.com` | di luar repo | tidak diubah (L10) |

## Langkah (produksi) — bila disetujui
Prasyarat: jendela downtime (≈15–30 mnt), akses SSH oleh pemilik, backup terverifikasi.
1. **Backup wajib**: `pg_dump -Fc` DB, salin volume `app_storage` (tar), salin `.env.production`, `docker/mediamtx`, sertifikat (`certbot_conf`). Uji restore ke DB sementara.
2. Umumkan downtime; pastikan antrean Redis kosong (`queue:work` selesai) dan tidak ada snapshot CCTV tertunda.
3. Pin `SESSION_COOKIE`, `CACHE_PREFIX`, `REDIS_PREFIX` ke nilai lama (lihat 01_RENAME_INVENTORY.md) bila `APP_NAME` ikut berubah.
4. `docker compose -f docker-compose.prod.yml down` (TANPA `-v`).
5. Buat DB/user baru (`minetrace_db`/`minetrace_user`) pada container lama, `pg_restore` ke DB baru; verifikasi jumlah baris tabel utama (batches, checkpoints, cctv_snapshots, users).
6. Ubah nama di compose + `.env.production` (`DB_DATABASE`, `DB_USERNAME`, `POSTGRES_*`); jaga **`name:` proyek compose tetap lama** (`name: minetra`) atau deklarasikan volume `external: true` dengan nama lama supaya volume tidak berganti.
7. `docker compose up -d`; `php artisan migrate --force --pretend` lalu tanpa `--pretend`; `optimize`.
8. Verifikasi: login, dashboard, buat batch uji → hapus via tandai uji, scan RFID simulasi, snapshot CCTV, generate PDF, buka QR lama (`/verify/batch/…`), sertifikat TLS, HLS.
9. Pantau 24 jam. Simpan DB/volume lama minimal 7 hari.

## Rollback
`down`; kembalikan compose + `.env.production` dari backup; `up -d` pada volume/DB lama (tidak dihapus pada langkah 9). Bila DB baru bermasalah, restore dump langkah 1.

## Lokal (aman, bisa dilakukan sendiri kapan saja)
`docker compose down` (tanpa `-v`) → ubah nama → `pg_dump`/`pg_restore` ke volume baru. Data lokal bersifat demo.
