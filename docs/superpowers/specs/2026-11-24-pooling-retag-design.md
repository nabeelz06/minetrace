# Desain: Penghimpunan batch & tag baru di Wet dan Dry

Tanggal: 2026-11-24 · Branch: `feat/pooling-retag` · Status: draf untuk persetujuan

## Maksud & alasan

Di lapangan, material dari beberapa batch Receiving tidak diproses satu per satu. Di Wet, batch-batch
dihimpun sampai limbahnya cukup banyak, lalu hasilnya dimasukkan ke drum baru. Di Dry, drum-drum dari Wet
dihimpun lagi, lalu dipisah menjadi drum MON, ZIR, dan ILM. Tag RFID lama tidak ikut ke drum baru, jadi
alur sekarang (satu tag mengikuti satu batch dari Receiving sampai Gudang) tidak sesuai kenyataan.

Sistem harus mengikuti kenyataan itu tanpa kehilangan ketertelusuran: dari drum mana pun di Gudang, kita
harus bisa menelusuri semua batch asal sampai ke Lab Kimia.

## Alur baru (keputusan pemilik)

1. **Masuk Wet.** Batch dari Receiving diterima di Wet (scan IN di UR4 atau tombol di web). Tag-nya
   otomatis dilepas. Data batch tetap tersimpan. Batch masuk ke **himpunan Wet** yang sedang terbuka.
2. **Keluar Wet.** Drum baru diberi tag baru. Drum lewat UR4 keluar Wet dengan UID yang belum dikenal.
   Sistem membuat **batch gabungan** baru dari **seluruh** isi himpunan Wet saat itu (selalu seluruhnya,
   tidak pernah sebagian). Himpunan ditutup. Operator menimbang drum dan mengisi beratnya di halaman Wet.
3. **Masuk Dry.** Drum dari Wet diterima di Dry. Tag-nya dilepas. Drum masuk ke **himpunan Dry**.
4. **Keluar Dry.** Satu himpunan Dry menghasilkan sampai 3 drum (MON, ZIR, ILM), masing-masing dengan tag
   baru sendiri (opsi A). Tiap drum lewat UR4 keluar Dry dengan UID baru. Operator memilih produk dan
   mengisi berat tiap drum di halaman Dry, lalu menekan **"Selesai himpunan"**.
5. **Gudang.** Drum produk diterima di Gudang dengan tag-nya. Alur Gudang dan Project Plant tidak berubah.

## Tujuan

- Silsilah banyak-ke-satu (Wet) dan banyak-ke-banyak (Dry) tercatat rapi dan bisa di-query.
- Gate UR4 bekerja tanpa operator memilih batch: UID asing di reader keluar Wet/Dry = drum baru.
- Neraca massa per himpunan: total berat input dibanding total berat output, memakai
  `MassBalanceThreshold` tahap tersebut.
- Halaman detail batch, halaman QR publik, dan PDF ketertelusuran menampilkan semua batch asal secara
  rekursif.
- Setiap UID hanya terikat ke satu batch pada satu waktu (dijamin database).

## Bukan tujuan

- Membagi satu himpunan ke beberapa drum di Wet.
- Integrasi timbangan otomatis (berat tetap diisi manual).
- Mengubah alur Lab Kimia, Receiving, Gudang, Project Plant, atau ekspor.

## Anti-tujuan (gagal walau "jalan")

- Data batch input hilang atau tidak bisa ditelusuri dari batch output.
- UID yang sedang dipakai batch aktif bisa terpasang ke batch lain.
- Scan UID asing di reader selain keluar Wet/Dry ikut membuat batch.
- Alur lama untuk batch yang sedang berjalan rusak saat rilis.

## Pendekatan yang dipertimbangkan

1. **Tabel silsilah baru + himpunan (dipilih pemilik).** Tabel `batch_pools` dan `batch_lineage`,
   transformasi tipe `pooling`, input berstatus `pooled`. Query silsilah rapi, alur lama tidak terganggu.
2. Batch "wadah" sejak batch pertama masuk. Ditolak: ada batch tercatat sebelum drum fisiknya ada, dan
   aturan berat/mass balance jadi kacau.
3. Daftar input sebagai JSON di `batch_transformations`. Ditolak: penelusuran rekursif lambat dan rapuh.

## Desain

### Data

- **`batch_pools`**: `id`, `company_id`, `stage` (`wet_process`/`dry_process`), `status`
  (`collecting`/`outputting`/`closed`), `opened_at`, `closed_at`, `closed_by`, `total_input_weight`, `total_output_weight`,
  `loss_pct`, `has_discrepancy`, `transformation_id`.
  Paling banyak satu `collecting` dan satu `outputting` per (company, stage). Himpunan Wet langsung
  dari `collecting` ke `closed`.
- **`batch_pool_inputs`**: `pool_id`, `batch_id` (unik: satu batch hanya masuk satu himpunan),
  `weight_at_join`, `joined_at`, `released_rfid_tag` (UID yang dilepas saat masuk, untuk jejak).
- **`batch_lineage`**: `parent_batch_id`, `child_batch_id`, `pool_id`, `contributed_weight` (nullable).
  Dibuat saat himpunan ditutup: setiap input × setiap output.
  Untuk Dry, `contributed_weight` = berat input × (berat output / total output). Ini nilai kira-kira,
  ditandai begitu di UI.
- **`batches`**: kolom baru `pool_id` (himpunan yang menghasilkan batch ini, nullable). Status baru:
  `pooled` (input yang sudah digabung, masuk `ProcessFlow::CLOSED_STATUSES`) dan `awaiting_weight`
  (drum output yang belum ditimbang/diberi produk).
- **`batch_transformations`**: tipe baru `pooling`. Satu baris per himpunan yang ditutup. Detail berisi
  ID input, ID output, dan berat.
- **UNIQUE pada `batches.rfid_tag`.** Migrasi memeriksa duplikat lebih dulu dan gagal dengan pesan jelas
  bila ada. NULL boleh banyak.

### Masuk Wet / Dry

`StageTransitionService::receive()` ke `wet_process` atau `dry_process`:
1. Batch pindah tahap seperti biasa (checkpoint `receive_cp`).
2. Tag dilepas lewat `RfidTagService::release()` dengan alasan baru `pooled`. UID disimpan di
   `batch_pool_inputs.released_rfid_tag`.
3. Batch masuk himpunan `open` tahap itu (dibuat bila belum ada).

Batch tanpa tag (diterima lewat web) tetap masuk himpunan.

### Keluar Wet

Pemicu: scan UID asing di reader ber-`stage` `wet_process` dengan arah OUT, **atau** tombol
"Drum keluar" di halaman Wet (UID diisi lewat reader desktop atau diketik).
1. Bila himpunan Wet kosong, tolak dengan pesan "Tidak ada batch yang sedang dihimpun di Wet".
2. Buat batch output: tahap `wet_process`, status `awaiting_weight`, `rfid_tag` = UID baru, `pool_id`,
   produk sama dengan input (MIT), kode batch dari `BatchCodeService`.
3. Tutup himpunan: semua input menjadi `pooled`, isi `batch_lineage`, buat `batch_transformations`.
4. Operator mengisi berat di halaman Wet. Berat menjadi `initial_weight` dan `current_weight` output.
   Neraca massa himpunan dihitung. Status output menjadi `in_transit` (dikirim ke Dry).
5. Dry menolak menerima batch berstatus `awaiting_weight` (pesan: "Drum belum ditimbang").

### Keluar Dry

Pemicu: scan UID asing di reader ber-`stage` `dry_process` arah OUT, atau tombol "Drum keluar" di
halaman Dry.
1. Bila himpunan Dry kosong, tolak.
2. Buat batch output: tahap `dry_process`, status `awaiting_weight`, `rfid_tag` = UID baru, `pool_id`,
   produk belum ditentukan.
3. Himpunan **tetap terbuka**. Status himpunan: `collecting` → `outputting` → `closed`. Per
   (company, stage) paling banyak satu `collecting` dan satu `outputting`. Drum output pertama mengubah
   himpunan `collecting` menjadi `outputting`. Batch yang diterima di Dry setelah itu masuk ke himpunan
   `collecting` **baru**.
   Drum baru di reader keluar Dry selalu masuk ke himpunan `outputting` bila ada. Bila tidak ada, himpunan
   `collecting` menjadi `outputting`. Himpunan `collecting` baru tidak bisa mengeluarkan drum sebelum
   himpunan `outputting` ditutup. Drum ke-4 pada satu himpunan ditolak ("Himpunan sudah punya 3 drum").
4. Operator memilih produk (MON/ZIR/ILM) dan mengisi berat tiap drum. Satu produk hanya satu drum per
   himpunan.
5. "Selesai himpunan": wajib minimal 1 drum, semua drum sudah punya produk dan berat. Himpunan ditutup,
   input menjadi `pooled`, lineage dan transformasi dibuat, neraca massa dihitung, output menjadi
   `in_transit` (dikirim ke Gudang).
6. Gudang menolak menerima batch `awaiting_weight`.
7. Aksi lama "Transform" di halaman Dry (`ProcessController::dryTransform`) diganti alur ini.
   Kodenya dibiarkan untuk data lama tetapi tidak lagi tampil di UI.

### Gate UR4

Di `RfidScanService::process()`, sebelum mengembalikan `tag_unknown`: bila reader ber-`stage`
`wet_process`/`dry_process`, arah OUT, dan strategi perusahaan untuk tahap itu bukan `audit_only`, jalankan
"drum keluar". Di luar itu, perilaku "Tag Asing" tetap. Log baca mencatat hasilnya
(`pool_output_created` / `pool_empty`).

**Tag bekas (keputusan pemilik).** Tag yang dilepas boleh dipakai lagi, tetapi hanya untuk batch baru
di awal alur (registrasi di Lab Kimia/Receiving lewat sesi registrasi biasa). "Drum keluar" di Wet/Dry,
lewat gate maupun web, hanya menerima UID yang belum pernah tercatat di `rfid_tag_events`,
`batch_pool_inputs.released_rfid_tag`, atau `checkpoints.rfid_scan_code`. UID yang punya riwayat
ditolak dengan kode `tag_recycled` ("Tag bekas — pakai untuk batch baru di Receiving"). Ini mencegah
stiker lama di karung input menutup himpunan secara tidak sengaja.

**Batalkan drum.** Selama drum output masih `awaiting_weight`, operator bisa menekan "Batalkan drum".
Batch output di-soft-delete dan tag-nya dilepas. Untuk Wet, himpunan dibuka kembali (`collecting`),
input kembali `in_process`, lineage dan transformasi ditandai batal (`voided_at`, `voided_by`, alasan).
Untuk Dry, drum dihapus dari himpunan `outputting`; bila tidak ada drum tersisa, himpunan kembali
`collecting`. Setelah berat diisi, drum tidak bisa dibatalkan dari UI.

Registrasi tag lewat sesi web (`RfidRegistrationService`) memakai checkpoint sesuai tahap batch, bukan
selalu `lab_kimia_create`.

### Tampilan

- **Halaman Wet/Dry** (`Process/Stage.vue`): panel "Sedang dihimpun" (daftar batch, total berat, sejak
  kapan), daftar drum `awaiting_weight` dengan form berat (dan produk untuk Dry), tombol "Drum keluar",
  dan tombol "Selesai himpunan" (Dry).
- **Detail batch** (`Batches/Show.vue`): bagian "Asal batch" (pohon induk rekursif via `batch_lineage`)
  dan "Digabung ke" untuk batch `pooled`. `parent_batch_id` lama tetap ditampilkan untuk data lama.
- **QR publik** (`Public/VerifyController`, `VerifyBatch.vue`): rantai tahap mencakup tahap para leluhur,
  dengan daftar batch asal. Checkpoint yang di-void tidak ditampilkan.
- **PDF ketertelusuran**: tabel batch asal.
- Teks UI dalam dua bahasa (ID/EN), mengikuti pola i18n yang ada.

### Batch yang sedang berjalan saat rilis

Perintah `php artisan batches:pool-backfill` (dijalankan sekali setelah migrasi, dengan `--dry-run`
untuk pratinjau): hanya batch berstatus `in_process` di `wet_process`/`dry_process` yang dimasukkan ke
himpunan `collecting` tahapnya (satu himpunan per tahap), dan tag-nya dilepas. Batch `in_transit`
(sudah dikirim) tidak disentuh: tag tetap, dan saat diterima di tahap berikutnya ikut alur baru. Batch
hasil transformasi Dry lama tidak diubah.

## Keputusan yang diambil Claude (bisa dikoreksi)

- Berat diisi setelah drum lewat gate (status `awaiting_weight`), karena timbangan belum terhubung.
- Produk drum Dry dipilih operator di web setelah scan, bukan saat registrasi tag.
- Himpunan Dry ditutup manual ("Selesai himpunan"). Batch yang datang setelah drum pertama keluar masuk
  himpunan baru.
- `contributed_weight` di Dry dibagi proporsional dan ditandai sebagai perkiraan.
- Alur baru berlaku untuk semua perusahaan, tanpa saklar per perusahaan.
- Tombol "Drum keluar" di web tersedia untuk lokasi tanpa UR4.
- "Batalkan drum" hanya selama `awaiting_weight`. Lineage dan transformasi punya kolom void, bukan hapus.
- Status himpunan Dry `collecting`/`outputting`/`closed`; drum ke-4 ditolak.
- Backfill hanya batch `in_process`; batch `in_transit` tetap memakai tag lama.

## Diserahkan ke pembuat

- Format kode batch output, nama tabel/kolom persis, dan cara menambah nilai enum di PostgreSQL.
- Struktur komponen Vue dan endpoint API untuk form berat.
- Kunci konkurensi saat menutup himpunan (dua scan bersamaan).
- Daftar tes. Minimal: masuk Wet melepas tag, keluar Wet membuat gabungan dari seluruh himpunan,
  himpunan kosong ditolak, Dry 3 output, "Selesai himpunan", neraca massa, penelusuran rekursif,
  UNIQUE `rfid_tag`, dan alur gate UR4 penuh (memperbarui `UhfGateChainTest`).

## Jalan di lokal & rilis

Kerja di branch `feat/pooling-retag`, lalu PR ke `main` (bukan push langsung). Pemilik mengecek di lokal
sebelum merge.
