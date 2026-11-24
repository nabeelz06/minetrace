# Runbook rilis MINETRACE

## Rilis: penghimpunan batch & tag baru di Wet/Dry (2026-11-24)

Desain: `docs/superpowers/specs/2026-11-24-pooling-retag-design.md`.
Cadangan kode sebelum rilis: branch dan tag `backup/pre-pooling-2026-11-24` (commit `30ccf42`).

### Sebelum rilis

1. Cadangkan database produksi (`pg_dump`). Migrasi menambah tabel, mengganti CHECK status, dan menambah
   UNIQUE pada `batches.rfid_tag`.
2. Cek UID ganda. Migrasi gagal dengan daftar UID bila ada:
   ```sql
   SELECT rfid_tag, COUNT(*) FROM batches WHERE rfid_tag IS NOT NULL GROUP BY rfid_tag HAVING COUNT(*) > 1;
   ```

### Rilis

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan batches:pool-backfill --dry-run   # pratinjau
php artisan batches:pool-backfill             # wajib: tanpa ini batch lama di Dry tidak bisa dikeluarkan
php artisan optimize:clear && php artisan optimize
# restart worker queue dan daemon gate UR4
```

`batches:pool-backfill` memasukkan batch `in_process` di Wet/Dry ke himpunan dan melepas tag-nya. Batch
`in_transit` tidak disentuh. Aksi "Transformasi" lama di halaman Dry disembunyikan setelah rilis.

### Setelah rilis

- Mode otorisasi RFID Wet/Dry harus selain "Catat saja" agar UID baru di reader keluar membuat drum.
- Cek panel "Penghimpunan & drum baru" di halaman Wet dan Dry.

### Kembali ke versi sebelumnya

1. `php artisan migrate:rollback --step=1`. Status `pooled`/`awaiting_weight` menjadi `terminated`, dan
   transformasi `pooling` serta tabel himpunan dihapus. Data himpunan hilang, jadi pulihkan dari cadangan
   database bila perlu.
2. `git checkout backup/pre-pooling-2026-11-24`, lalu build dan restart seperti di atas.
