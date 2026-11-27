# Rilis 27 Nov 2026 — Verifikasi berat, pakai ulang tag, data mitra, laporan per detik

Branch: `feat/receiving-weight-verify-tag-reuse` · Tidak mengubah alur Receiving & Wet maupun Dry yang sudah berjalan.
Semua perubahan bersifat **aditif** (migrasi hanya menambah 2 kolom nullable; tidak ada data yang diubah).

## Ringkasan perubahan

| # | Kebutuhan | Hasil |
|---|-----------|-------|
| 1 | Berat batch disesuaikan dengan berat sebenarnya setelah tag terdaftar | Tombol **Verifikasi berat** (popup) di Detail Batch, Receiving & Wet. Berat deklarasi (`initial_weight`) tetap; berat timbang menggantikan `current_weight`; selisih % dibandingkan ambang neraca massa. Selisih besar hanya **menandai** (peringatan), tidak menahan batch. Tercatat sebagai checkpoint `receiving_verify` ("Receiving Verify"). |
| 2 | Tag mudah dibebaskan agar bisa dipakai ulang | **Bebaskan tag / Pakai ulang** di Kelola Tag dan di antrean tag baru. Riwayat lama tidak dihapus (append-only); sistem hanya memasang batas id sehingga UID dianggap baru lagi. |
| 3 | Waktu sampai detik | UI, PDF, dan Excel memakai WIB `dd/mm/yyyy HH:mm:ss`. |
| 4 | Kolom Excel | Stage Point apa adanya (mis. **Receiving Verify**), waktu sampai detik, catatan pendaftaran tag: `RFID Tag terdaftar: UID XXXX oleh Receiving Area`. Ditambah kolom mitra. |
| 5 | Kode batch urut hari-bulan-tahun | `[ENT].[LOK]-[PROC].[PROD]-DDMMYY-SEQ`, tanggal WIB. Kode lama tidak diubah. |
| 6 | Data mitra PT Timah | No. Partai / Lot (unik), Nama Mitra, Lokasi Asal, Penanda Tangan Mitra (bukti validasi). Dapat diisi saat membuat batch dan diubah di Detail Batch. |

## Opsi konfigurasi (opsional)

- `BATCH_CODE_DATE_FORMAT` di `.env`: `dmy` (bawaan, DDMMYY) atau `dmY` (DDMMYYYY).
- Saran Lokasi Asal: `config/batch.php` → `origin_locations`.

## Cara memakai (operator)

1. **Verifikasi berat**: buka batch → *Verifikasi berat* → isi berat timbangan (+ catatan) → Simpan. Bila selisih melewati ambang, muncul peringatan dan batch ditandai untuk ditinjau.
2. **Pakai ulang tag**: Kelola Tag → cari UID → *Bebaskan tag*. Atau saat tag bekas muncul di antrean tag baru, pilih *Pakai ulang*.
3. **Data mitra**: isi di form Buat Batch, atau kartu *Data Mitra* di Detail Batch.

## Deploy ke VPS

> Working tree VPS memiliki perubahan lokal yang belum di-commit. **Jangan** menjalankan
> `git checkout .`, `git stash`, atau `git clean` di sana.

Setelah PR digabung ke `main`:

```bash
cd /path/ke/minetrace            # direktori proyek di VPS
git pull --ff-only origin main   # bila menolak karena perubahan lokal, berhenti dan kabari tim

# Bangun ulang image agar app, worker, scheduler, dan rfid_gate memakai kode baru
docker compose -f docker-compose.prod.yml build app worker scheduler rfid_gate
docker compose -f docker-compose.prod.yml up -d app worker scheduler rfid_gate nginx

# Migrasi (aditif: 2 kolom nullable) dan segarkan cache
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache

# Aset frontend (public/build tidak ikut git)
npm ci && npm run build
```

Verifikasi cepat:

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml exec app php artisan migrate:status | tail -3
```

Lalu di web: buka sebuah batch → pastikan tombol *Verifikasi berat* dan kartu *Data Mitra* tampil; unduh Excel
laporan → kolom *Stage Point* dan *Waktu (WIB)* sampai detik.

## Rollback

- Kode: `git revert <merge-commit>` lalu bangun ulang seperti di atas.
- Migrasi: `php artisan migrate:rollback --step=1` hanya menghapus 2 kolom penanda pakai-ulang. Aman; bila dibiarkan pun
  tidak mengganggu kode lama (kolom nullable).

## Catatan perilaku

- Data lama tidak diubah. Kode batch lama (format YYMMDD) tetap valid; hanya kode baru yang memakai DDMMYY.
- Catatan pendaftaran tag lama yang menyebut nama operator ditampilkan sebagai "oleh <area>" **hanya di laporan/riwayat**;
  data aslinya tetap utuh.
- Tag bekas yang terbaca di reader Receiving & Wet tetap **tidak diproses otomatis** (reader menampilkan "Tag bekas - pilih
  di PC"), tetapi kini diantrekan dengan label *bekas*; operator memilih *Pakai ulang* di PC bila tag itu memang
  dipasang ke wadah baru. Sebelumnya tag bekas hanya ditolak.
- Dua tes lama (`UhfGateChainTest`, `TagLifecycleTest`) sudah gagal di `main` sebelum rilis ini (tertinggal dari aturan
  Dry 3 drum dan "tag bekas"); sudah disesuaikan.

## Pengujian

PHPUnit 409 tes lolos · `npm run test:rfid-flow` · `npm run test:weight` · `npm run i18n:check` · `npm run build`.
