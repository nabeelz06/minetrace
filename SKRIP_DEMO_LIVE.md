# MINETRACE — Skrip Demo untuk Interview

> Tujuan dokumen ini: panduan bicara + klik saat demo ke interviewer.
> Bahasa dibuat sesederhana mungkin — fokus ke **apa fiturnya, buat apa
> gunanya, dan apa dampaknya buat perusahaan**. Detail teknis (kode,
> endpoint, nama tabel) sengaja tidak dibahas di sini karena interviewer
> yang dituju bukan audiens teknis.

---

## Sebelum Mulai: 1 Persiapan Penting

Supaya demo CCTV **selalu mulus** dan tidak perlu menunggu, pastikan
minimal satu kamera di menu **Hardware → CCTV → Pengaturan** diset ke
mode **"Simulasi"** (bukan "Real") untuk area yang akan dipakai demo
(misalnya Receiving Area). Kamera mode Simulasi menghasilkan video/foto
dari sumber lokal di server — jadi hasilnya **selalu berhasil dan
instan**, tidak bergantung pada koneksi ke kamera fisik di lapangan yang
bisa saja sedang putus. Fiturnya tetap sama persis dengan kamera asli,
hanya sumber gambarnya yang berbeda — jadi tidak ada yang "dipalsukan",
ini memang mode resmi yang disediakan sistem untuk pelatihan/demo.

---

## Alur Demo (±10 menit)

1. Login → lihat Dashboard
2. Buat Batch baru (awal pelacakan)
3. Tempel Tag RFID ke batch
4. Scan RFID → tunjukkan pencatatan otomatis + foto CCTV otomatis
5. Lihat riwayat lengkap 1 batch (Timeline)
6. Generate 1 laporan (PDF)
7. Penutup — rangkuman dampak

---

## 1. Login & Dashboard

**Yang dilakukan**: Login sebagai admin perusahaan, tunjukkan dashboard.

**Yang dikatakan ke interviewer**:
> "Ini adalah dashboard utama. Semua data real-time — berapa batch yang
> sedang berjalan, berapa yang butuh perhatian, tren produksi. Satu layar,
> semua kelihatan, tidak perlu buka-buka laporan kertas."

---

## 2. Buat Batch Baru

**Yang dilakukan**: Buka menu Batch → Buat Batch Baru, pilih produk,
lokasi, isi berat awal, simpan.

**Yang dikatakan ke interviewer**:
> "Setiap karung hasil tambang yang masuk, kita kasih 'identitas' di
> sini — satu kode unik yang akan mengikuti karung itu dari awal sampai
> siap ekspor. Ini seperti KTP untuk setiap batch material."

**Kenapa ini penting (dampak)**:
- Tidak ada lagi batch yang "tidak jelas asal-usulnya" — setiap gram
  material bisa ditelusuri balik ke titik awal.
- Ini adalah syarat dasar untuk memenuhi standar pelacakan mineral
  (traceability) yang diminta regulator dan pembeli internasional.

---

## 3. RFID — "Sidik Jari" Elektronik untuk Setiap Batch

Ini bagian paling penting untuk ditekankan ke interviewer.

**Masalah yang diselesaikan (jelaskan dulu sebelum demo tombol)**:
> "Sebelumnya, mencatat perpindahan batch antar area itu manual — orang
> tulis di kertas atau Excel kapan karung masuk, kapan keluar. Ini rawan
> lupa dicatat, rawan salah tulis, dan sulit dibuktikan kalau ada
> sengketa data. RFID menyelesaikan ini dengan cara: tempel tag kecil ke
> batch, lalu setiap kali batch itu lewat alat pembaca (reader) di pintu
> area kerja, sistem otomatis mencatat sendiri — tanpa orang perlu
> ketik apa-apa."

**Demo — Langkah A: Tempel tag ke batch**
1. Buka **Hardware → RFID**.
2. Pilih batch yang baru dibuat dari daftar "belum ditag".
3. Klik "Aktif", lalu tempelkan tag RFID ke alat reader.
4. Tunjukkan pesan "Tag terdaftar!" muncul.

> "Sekarang batch ini sudah 'kenal' dengan tag fisiknya. Mulai detik
> ini, setiap kali tag ini lewat reader di area manapun, sistem tahu
> persis batch mana yang lewat."

**Demo — Langkah B: Scan tag (simulasikan batch masuk area)**
1. Tempelkan tag yang sama ke reader di titik "Receiving" (atau pakai
   tab **Simulasi** kalau tidak bawa alat fisik).
2. Tunjukkan di layar: catatan baru langsung muncul di riwayat batch,
   **kurang dari 1 detik** setelah discan.

**Kenapa ini penting (dampak)**:
- **Kecepatan**: pencatatan terjadi otomatis dan instan, tidak menunggu
  siapapun mengetik.
- **Akurasi**: tidak ada human error — waktu, lokasi, dan identitas batch
  tercatat persis sesuai kejadian fisik.
- **Jejak audit yang tidak bisa diubah**: begitu tercatat, data ini jadi
  bukti resmi perjalanan batch — berguna saat ada audit internal,
  pemeriksaan pemerintah, atau klaim dari pembeli ekspor.
- **Tetap ada kendali manusia**: RFID di sini hanya *mencatat*, bukan
  otomatis memindahkan status batch ke tahap berikutnya — keputusan besar
  tetap di tangan operator. Jadi sistem membantu mencatat, bukan
  menggantikan pengawasan manusia.

---

## 4. CCTV — Bukti Visual Otomatis, Mendampingi RFID

**Masalah yang diselesaikan**:
> "Catatan RFID memberi tahu kita 'kapan dan apa' — tapi tidak memberi
> tahu 'kondisinya seperti apa'. Makanya, setiap kali RFID mencatat
> batch lewat, sistem otomatis mengambil foto dari CCTV di area itu, di
> waktu yang sama persis. Jadi setiap catatan punya bukti foto
pendukung."

**Demo**:
1. Setelah scan RFID di Langkah 3B, buka menu **Hardware → CCTV**.
2. Tunjukkan panel **Snapshot RFID** di sisi kanan — foto baru otomatis
   muncul tanpa diminta, dengan notifikasi kecil "Snapshot CCTV baru
   dari RFID scan".
3. Klik foto untuk memperbesar.
4. (Opsional) Tunjukkan juga **Live View** kamera — video langsung dari
   area kerja, bisa dipantau real-time dari satu layar untuk semua
   kamera sekaligus.

**Kenapa ini penting (dampak)**:
- **Bukti ganda**: data tercatat (RFID) + bukti visual (foto) terjadi
  otomatis bersamaan, tanpa ada yang perlu mengambil foto manual.
- **Mengurangi sengketa**: kalau suatu saat ada pertanyaan "benar tidak
  batch ini memang ada di sana saat itu?", tinggal buka foto — tidak
  perlu debat.
- **Pemantauan terpusat**: pengawas bisa memantau seluruh area kerja dari
  satu layar, tanpa harus berkeliling fisik ke lapangan.
- **Sistem tetap jujur kalau gagal**: kalau pun suatu saat kamera di
  lapangan sedang bermasalah, sistem tidak "menahan" atau menggagalkan
  pencatatan RFID-nya — catatan RFID tetap tersimpan sempurna, hanya
  bagian fotonya saja yang akan ditandai gagal dan tercatat alasannya.
  Jadi tidak ada data yang hilang.

---

## 5. Timeline Batch — Satu Halaman, Semua Riwayat

**Yang dilakukan**: Buka detail batch yang sudah dipakai demo, tunjukkan
tab **Timeline** dan tab **Galeri Foto**.

**Yang dikatakan ke interviewer**:
> "Ini hasil akhirnya — satu halaman yang merangkum seluruh perjalanan
> satu batch: kapan dibuat, kapan pindah area, berat di tiap titik, dan
> semua foto buktinya. Semua ini terkumpul otomatis dari yang baru saja
> kita lakukan — RFID dan CCTV tadi — tanpa ada yang perlu disusun
> manual."

---

## 6. Generate Laporan

**Yang dilakukan**: Buka menu **Laporan**, pilih "Batch Traceability",
format PDF, pilih rentang tanggal, klik "Unduh Laporan".

**Yang dikatakan ke interviewer**:
> "Semua data yang tadi tercatat otomatis, bisa langsung dijadikan
> laporan resmi kapan saja — dalam hitungan detik, bukan harus disusun
> manual oleh staf dari catatan-catatan terpisah."

**Kenapa ini penting (dampak)**:
- Laporan untuk audit internal, pemerintah, atau pembeli ekspor bisa
  disiapkan jauh lebih cepat, dengan data yang konsisten karena sumbernya
  satu sistem yang sama.

---

## 7. Penutup — Rangkuman untuk Interviewer

> "Jadi intinya, tiga fitur ini saling melengkapi: **Batch** memberi
> setiap material identitas yang bisa dilacak, **RFID** mencatat
> perjalanannya secara otomatis dan akurat tanpa campur tangan manual,
> dan **CCTV** memberi bukti visual di setiap titik pencatatan itu.
> Hasil akhirnya: data yang dulu tersebar di kertas dan ingatan orang,
> sekarang tercatat otomatis, akurat, dan siap diaudit kapan saja —
> dengan risiko kesalahan dan kecurangan yang jauh lebih kecil."

---

## Catatan Cepat Jika Ditanya Hal Teknis

Interviewer kemungkinan tidak akan menanyakan hal ini, tapi kalau
muncul, jawaban sederhananya:

| Pertanyaan | Jawaban singkat |
|---|---|
| "Kalau internetnya mati, gimana?" | Pencatatan di web tetap butuh koneksi ke server, tapi reader RFID di lapangan tetap bisa menyimpan data sementara dan mengirim begitu koneksi kembali. |
| "Kalau kameranya rusak?" | Pencatatan RFID tetap jalan normal — hanya foto buktinya yang akan kosong/gagal, tercatat jujur sebagai "gagal", bukan dipaksakan atau disembunyikan. |
| "Apakah RFID bisa langsung memindahkan status batch otomatis?" | Secara sengaja TIDAK — ini untuk menjaga agar keputusan perpindahan tahap produksi tetap melalui persetujuan manusia, RFID hanya mencatat jejaknya. |
| "Siapa saja yang bisa akses data ini?" | Setiap peran (admin, operator, auditor) punya hak akses berbeda — operator hanya bisa input di areanya, auditor hanya bisa lihat dan unduh laporan, tidak bisa mengubah data. |
