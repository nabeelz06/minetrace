# STATUS MINETRACE
- Fase: 0 (checkpoint, menunggu jawaban pengguna). Branch: `feat/minetrace-rebrand-ux-rfid`.
- Selesai: DB uji `minetrace_test` + `.env.testing` (di-ignore git); baseline tes 17/18 lulus; verifikasi T1–T14; `00_LAPORAN_PEMAHAMAN.md`.
- Belum: penelusuran UI + screenshot (tak ada Playwright), baseline `npm run build`.
- Perintah verifikasi: `php artisan test` (HANYA setelah cek `APP_ENV=testing php artisan config:show database.connections.pgsql` → `minetrace_test`).
- Cadangan `.env`: di luar repo (`~/.minetra-env.backup.*`). Jangan jalankan `start-local.sh` tanpa cadangan.
- Perubahan lokal pengguna `SKRIP_DEMO_LIVE.md` belum di-commit; jangan ikut dikomit tanpa persetujuan.
