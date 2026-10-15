# STATUS MINETRACE
- Branch: `feat/minetrace-rebrand-ux-rfid`. Keputusan pengguna: Q1 kode Fase 7 memang tidak ada (lokal=VPS=main) → registri perangkat dibangun sendiri (aditif); Q2 SKRIP_DEMO_LIVE di-commit; Q3 semantik forward/receive = skenario (b) (forward = tandai kirim `in_transit`, receive = pindah tahap); Q4 perbaiki kebocoran lintas-tenant; Q5 Playwright boleh dipasang di luar repo; K1–K9 default. Arahan tambahan: standar industri modern, siap skala industri.
- Fase 0: SELESAI (`00_LAPORAN_PEMAHAMAN.md`). Fase 1 (WS1): SELESAI (kecuali PDF/Excel manual run). T1 (QR 404) diperbaiki lebih awal.
- Berikutnya: Fase 2 (WS2 UI) → Fase 3 (WS3) → Fase 4 (WS4) → Fase 5 (WS5, CLAUDE.md).
- Verifikasi: `php artisan test` (cek DB uji dulu: `APP_ENV=testing php artisan config:show database.connections.pgsql` → `minetrace_test`); `npm run build`.
- Baseline bundel: `app.js` 1.124 kB (gzip 306 kB), CSS 102 kB.
- Cadangan `.env`: `~/.minetra-env.backup.*` (di luar repo).
- Temuan untuk WS3: Ziggy `@routes` membuka seluruh tabel rute ke pengunjung anonim.
