# Desain: Receiving + Wet digabung, jenis wadah, kadar Sn, 2 reader

Tanggal: 2026-11-24 · Melanjutkan `2026-11-24-pooling-retag-design.md` · Status: draf untuk persetujuan

## Maksud & alasan

Tahap 1 di lapangan hanya memasang **2 reader UR4**: satu di area Receiving + Wet (satu area fisik), satu di Dry.
Ada dua jenis wadah yang dilacak, plus drum produk Dry:

1. **Drum besi**: material basah dari Lab Kimia. Ditimbang dan dicek **kadar Sn** di Receiving, lalu isinya
   **langsung dituang** ke proses Wet.
2. **Container**: sisa/hasil olahan Wet. Keluar dengan **tag baru**, ditimbang di timbangan Receiving, lalu
   dikirim ke Dry.
3. **Drum produk**: hasil Dry, satu per produk (MON/ZIR/ILM), tag baru, ditimbang di Dry (alur yang sudah ada).

Kadar Sn cukup satu angka. Dari kadar Sn, sisa komposisinya bisa diketahui.

## Keputusan pemilik

- Pendekatan **A**: Receiving dan Wet digabung di tampilan dan reader. Di data tetap dua tahap
  (`receiving`, `wet_process`) sehingga riwayat, laporan, CCTV per tahap, kode batch, dan neraca massa tidak
  dirombak.
- Drum besi langsung dituang setelah ditimbang dan dicek kadar: satu tap cukup.
- Container Wet keluar dengan ID baru (alur penghimpunan yang sudah dirilis).
- Dry mengeluarkan drum per produk MON/ZIR/ILM dengan tag baru.

## Tujuan

- Satu halaman **"Receiving & Wet"** untuk operator.
- Reader Receiving/Wet membedakan kejadian dari **status tag**, bukan arah antena:
  tag drum besi terdaftar = drum datang; UID baru = container keluar.
- Reader Dry: tag container terdaftar = container datang; UID baru = drum produk keluar.
- Jenis wadah dan kadar Sn tercatat di batch, tampil di riwayat, detail batch, halaman QR publik (jenis wadah
  saja), dan PDF.

## Bukan tujuan

- Integrasi timbangan otomatis.
- Menghapus tahap `receiving` atau `wet_process` dari data.
- Mengubah Gudang, Project Plant, ekspor.

## Anti-tujuan

- Drum besi masuk himpunan Wet sebelum berat dan kadar Sn diisi.
- Data lama (batch yang sudah berjalan di tahap Receiving/Wet terpisah) tidak bisa dibuka atau diteruskan.
- Scan UID asing di reader selain Receiving/Wet dan Dry membuat batch.

## Pendekatan (dipilih: A)

- **A. Gabung tampilan + reader, data dua tahap** — dipilih. Risiko rendah untuk sistem live.
- B. Lebur jadi satu tahap di database — ditolak: migrasi data, enum, kode batch, laporan, CCTV.
- C. Dua halaman, satu reader bergantian — ditolak: UR4 terikat ke satu tahap, rawan salah setel.

## Desain

### Data

- `batches.container_type` (string 20, nullable): `drum_besi` | `container` | `product_drum`.
  - Batch baru dari Lab Kimia: `drum_besi`.
  - Drum output himpunan Wet: `container`.
  - Drum output himpunan Dry: `product_drum`.
  - Data lama: `null`, ditampilkan "—".
- `batches.sn_grade_pct` (decimal 6,3, nullable): kadar Sn (%) drum besi, 0–100.
- Status baru `awaiting_intake`: drum besi sudah tiba (tap/terima) tetapi belum ditimbang dan dicek kadar.
  Masuk daftar guard yang sama dengan `awaiting_weight` (tidak bisa dikirim, diterima, atau dihimpun).

### Alur Receiving & Wet

1. **Drum besi tiba.** Pemicu: tap tag drum terdaftar di reader ber-`stage` `receiving` (arah apa pun),
   atau tombol "Terima" di halaman. Batch pindah ke `receiving` dengan status `awaiting_intake`
   (checkpoint `receiving_verify`). Tag belum dilepas.
2. **Timbang & kadar.** Operator mengisi berat (kg) dan kadar Sn (%) di halaman. Sistem:
   - mencatat checkpoint timbang di Receiving (neraca massa terhadap berat Lab Kimia, ambang tahap `receiving`);
   - langsung menerima batch ke `wet_process` dan memasukkannya ke himpunan Wet (tag dilepas, alur yang ada).
   - Bila susut melewati ambang (peringatan/kritis), drum **tetap masuk Wet** dan batch ditandai alert
     (`has_discrepancy`) untuk ditinjau (keputusan pemilik).
3. **Container keluar.** Pemicu: UID baru di reader `receiving` (arah apa pun), atau tombol "Container keluar".
   Container dibuat dari seluruh himpunan Wet (alur yang ada), `container_type = container`, status
   `awaiting_weight`.
4. **Timbang container** di halaman yang sama → `in_transit` ke Dry (alur yang ada).

### Alur Dry

- Tap tag container terdaftar di reader `dry_process` (arah apa pun) → diterima, masuk himpunan Dry
  (alur yang ada, bila mode tahap Dry bukan "Catat saja").
- UID baru di reader `dry_process` **arah apa pun** (sebelumnya hanya arah keluar) → drum produk
  (`product_drum`), lalu produk + berat + "Selesai himpunan" (alur yang ada).

### Gate RFID

- `RfidScanService`: UID asing di reader ber-`stage` `receiving` → `createOutputDrum(..., 'wet_process')`.
  UID asing di reader `dry_process` → `createOutputDrum(..., 'dry_process')`. Keduanya tanpa syarat arah.
  Reader lama ber-`stage` `wet_process` tetap seperti sekarang (hanya arah keluar).
- Tag terdaftar di reader `receiving`: diproses sebagai Scan IN tahap `receiving` bila batch masih di Lab Kimia
  (`in_transit`). Strategi bukan "Catat saja" → terima ke `awaiting_intake`.
- Mode otorisasi: drum/container baru dibuat bila mode tahap tujuan (`wet_process` / `dry_process`) bukan
  "Catat saja". Terima drum besi mengikuti mode tahap `receiving`.

### Tampilan

- Navigasi dan halaman: tahap `receiving` tampil sebagai **"Receiving & Wet"**. URL `/company/process/wet_process`
  dialihkan ke `/company/process/receiving`. Halaman gabungan berisi:
  - "Drum besi datang" (dari Lab Kimia) dengan tombol Terima;
  - "Menunggu timbang & kadar Sn" dengan form berat + kadar Sn;
  - panel himpunan Wet + container keluar + timbang container (komponen `PoolPanel` yang ada);
  - batch aktif Wet lama (data sebelum rilis) tetap tampil dengan aksinya.
- Detail batch, QR publik, PDF: jenis wadah; kadar Sn (detail batch & PDF, tidak di QR publik).
- Teks ID/EN.

### Data lama saat rilis

- Batch lama di `receiving` (`in_process`) tetap bisa diteruskan: tombol "Timbang & kadar" juga tersedia
  untuk mereka (kadar Sn wajib), lalu masuk himpunan Wet.
- `container_type` data lama dibiarkan `null`.

## Keputusan yang diambil Claude (bisa dikoreksi)

- Status `awaiting_intake` baru, tidak memakai ulang `awaiting_weight`, agar daftar "menunggu timbang & kadar"
  dan "container menunggu timbang" tidak tercampur.
- Kadar Sn wajib untuk drum besi sebelum masuk Wet; rentang 0–100 dengan 3 desimal.
- Reader Receiving/Wet disetel ber-`stage` `receiving`; reader Dry `dry_process`; keduanya arah `any`.
- Kadar Sn tidak ditampilkan di QR publik (data mutu internal).
- Drum produk Dry diberi `product_drum`; nama tampilan "Drum produk".
- Tag terdaftar lain yang terbaca reader Receiving/Wet (container di timbangan, drum `awaiting_intake`,
  batch lama di `receiving`) hanya **dicatat** (audit, tanpa perpindahan). Hanya drum besi Lab Kimia
  `in_transit` yang diterima otomatis.
- Batch lama `receiving`/`in_transit` (sudah dikirim ke Wet sebelum rilis) mendapat tombol "Terima ke Wet" di
  halaman gabungan (tanpa kadar Sn, berat sudah tercatat saat dikirim) dan masuk himpunan Wet.
- CCTV: kejadian di reader Receiving/Wet (termasuk container keluar & masuk himpunan Wet) memakai kamera reader
  itu (`camera_url` per-reader yang sudah ada), bukan kamera per tahap.
- Berat timbang Receiving **menggantikan** `current_weight` drum besi, sehingga `weight_at_join` dan neraca massa
  Wet memakai berat Receiving.

## Diserahkan ke pembuat

- Nama migrasi, cara menambah status di CHECK PostgreSQL.
- Struktur komponen Vue halaman gabungan.
- Penyesuaian tes lama yang mengandalkan Receiving terpisah.
