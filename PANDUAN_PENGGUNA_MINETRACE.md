# Buku Panduan Pengguna — MINETRACE
### Enterprise Mineral Traceability System — PT Timah Tbk

> Dokumen ini adalah kerangka isi (poin-poin + teks prosedur siap pakai) untuk
> disusun menjadi buku panduan resmi. Setiap bagian sudah ditulis dalam
> format siap tempel: judul, deskripsi fungsi, langkah bernomor, dan
> penjelasan elemen antarmuka — mengikuti gaya buku panduan versi
> sebelumnya. Tanda **[SISIPKAN SCREENSHOT]** menandai titik yang
> sebaiknya diisi tangkapan layar saat buku disusun ke layout akhir.
>
> Disusun berdasarkan pembacaan langsung kode sumber sistem (bukan asumsi),
> sehingga nama tombol, label kolom, dan alur kerja di bawah ini
> **dijamin sesuai dengan kondisi sistem yang berjalan saat ini**.

---

## Daftar Isi

1. Memulai
2. Manajemen Batch
3. Alur Proses Produksi
   - 3.1 Ringkasan Tahapan
   - 3.2 Receiving Area
   - 3.3 Wet Process
   - 3.4 Dry Process
   - 3.5 LTJ Warehouse
   - 3.6 Lab Analisis LTJ (Project Plant)
4. RFID — Pelacakan Otomatis
5. CCTV — Pemantauan & Bukti Visual
6. Audit dan Pelaporan
7. Manajemen Akun & Pengaturan
8. Referensi Cepat

---

# 1. Memulai

## 1.1 Mengakses Sistem

**Fungsi**: Titik masuk pertama pengguna ke dalam sistem MINETRACE.

**Langkah-langkah:**
1. Buka browser (disarankan Google Chrome atau Microsoft Edge versi terbaru).
2. Kunjungi tautan: `https://mining-traceability.com/`
3. Anda akan diarahkan otomatis ke halaman **Login** jika belum masuk ke
   sistem, atau langsung ke **Dashboard** jika sesi login masih aktif.

**[SISIPKAN SCREENSHOT: Halaman Login — tampilan penuh]**

---

## 1.2 Login

**Fungsi**: Autentikasi pengguna berdasarkan email dan password yang telah
didaftarkan oleh administrator sistem. Setiap pengguna memiliki peran
(role) tertentu yang menentukan menu dan hak akses yang tersedia setelah
login.

**Langkah-langkah:**
1. Pada formulir login, isi alamat email Anda di kolom **Alamat Email**
   (kolom 1).
2. Isi kata sandi Anda di kolom **Password** (kolom 2). Gunakan ikon mata
   di sisi kanan kolom untuk menampilkan/menyembunyikan karakter password
   yang diketik.
3. Centang kotak **"Ingat saya selama 30 hari"** jika Anda ingin sesi
   login tetap tersimpan di perangkat ini (opsional — sebaiknya tidak
   dicentang jika menggunakan komputer bersama/publik).
4. Klik tombol **"Masuk ke Sistem"**.
5. Jika data yang dimasukkan benar, Anda akan diarahkan otomatis ke
   halaman dashboard sesuai peran akun Anda:
   - **Company Admin / Operator / Auditor** → Dashboard Perusahaan
   - **Super Admin** → Dashboard Administrasi Sistem
   - **BIM Admin / Viewer / Auditor Pemerintah** → Dashboard BIM (regulator)
6. Jika email atau password salah, sistem akan menampilkan pesan
   **"Email atau password tidak sesuai"** di bawah kolom yang bermasalah.
   Periksa kembali ejaan email dan pastikan Caps Lock tidak aktif.
7. Untuk alasan keamanan, sistem membatasi percobaan login hingga
   **5 kali per menit** per akun. Jika melebihi batas ini, sistem akan
   menampilkan waktu tunggu sebelum bisa mencoba lagi.

**[SISIPKAN SCREENSHOT: Formulir login dengan kolom Email dan Password ditandai nomor]**

---

## 1.3 Pemulihan Akun (Lupa Password)

**Fungsi**: Memungkinkan pengguna me-reset password secara mandiri tanpa
perlu menghubungi administrator, apabila lupa password akun.

**Langkah-langkah:**
1. Pada halaman Login, klik tautan **"Lupa password?"** di sebelah kanan
   label kolom Password.
2. Anda akan diarahkan ke halaman **"Lupa Password?"**.
3. Masukkan alamat email akun Anda pada kolom **Alamat Email**.
4. Klik tombol **"Kirim Link Reset"**.
5. Sistem akan menampilkan pesan konfirmasi: *"Jika email terdaftar di
   sistem kami, link reset password sudah dikirim. Silakan cek kotak
   masuk (dan folder spam) Anda."* — pesan ini akan selalu muncul sama
   baik email terdaftar maupun tidak, sebagai langkah keamanan standar.
6. Buka email yang masuk dengan subjek **"Reset Password — MINETRACE"**,
   lalu klik tombol **"Reset Password"** di dalam email.
7. Anda akan diarahkan ke halaman **"Buat Password Baru"** dengan kolom
   email yang sudah terisi otomatis.
8. Masukkan password baru pada kolom **Password Baru**, lalu ulangi pada
   kolom **Konfirmasi Password Baru**.
9. Perhatikan indikator kekuatan password yang muncul di bawah kolom —
   password baru wajib memenuhi seluruh syarat berikut:
   - Minimal 8 karakter
   - Mengandung huruf
   - Mengandung angka
   - Mengandung simbol (contoh: `!`, `@`, `#`, `%`)
10. Klik tombol **"Simpan Password Baru"**.
11. Anda akan diarahkan kembali ke halaman Login dengan pesan sukses.
    Login menggunakan email dan password baru Anda.

**Catatan penting:**
- Link reset password hanya berlaku selama **60 menit** sejak email
  dikirim, dan hanya dapat digunakan **satu kali**. Jika sudah kedaluwarsa
  atau sudah pernah dipakai, minta link baru dengan mengulangi langkah 1–5.
- Jika email tidak kunjung masuk, periksa folder **Spam/Junk**, dan pastikan
  alamat email yang dimasukkan sama persis dengan yang terdaftar di sistem.

**[SISIPKAN SCREENSHOT: Halaman "Lupa Password?" dan halaman "Buat Password Baru" dengan indikator kekuatan password]**

---

## 1.4 Mengenal Antarmuka Utama

**Fungsi**: Orientasi elemen-elemen tetap yang akan sering digunakan di
seluruh halaman sistem.

**Penjelasan Elemen:**
- **Sidebar (menu samping)**: Berisi daftar menu navigasi utama,
  menyesuaikan otomatis dengan peran (role) akun yang login. Dapat
  diciutkan/dilebarkan untuk menghemat ruang layar.
- **Header/Judul Halaman**: Menampilkan nama halaman yang sedang aktif.
- **Kartu Statistik (KPI Cards)**: Ringkasan angka penting di bagian atas
  hampir setiap halaman (contoh: jumlah batch aktif, jumlah alert).
- **Toggle Mode Gelap/Terang**: Tombol di sidebar bawah untuk mengganti
  tampilan sistem antara mode terang dan gelap sesuai preferensi mata.
- **Toggle Bahasa**: Tombol bendera 🇮🇩/🇬🇧 di sidebar bawah untuk mengganti
  bahasa antarmuka antara Indonesia dan Inggris.
- **Kartu Profil Pengguna**: Di bagian paling bawah sidebar, menampilkan
  nama, email, dan peran akun yang sedang login. Klik untuk membuka menu
  dropdown berisi tombol **"Keluar"** (logout).
- **Waktu Sistem**: Jam real-time (WIB) yang tampil di banyak halaman,
  berguna sebagai referensi waktu pencatatan data.

**[SISIPKAN SCREENSHOT: Dashboard dengan sidebar, header, dan kartu profil ditandai]**

---

# 2. Manajemen Batch

**Konsep dasar**: "Batch" adalah satu kelompok/karung hasil tambang yang
dilacak sebagai satu kesatuan sepanjang seluruh proses produksi — dari
pembuatan di Lab Kimia hingga siap ekspor. Setiap batch memiliki kode unik
dengan format `TMH.<LOKASI>-<TAHAP>.<PRODUK>-<TANGGAL>-<URUTAN>`
(contoh: `TMH.SGT-LK.MIT-260716-001`).

## 2.1 Membuat Batch Baru

**Fungsi**: Mendaftarkan batch baru ke dalam sistem, titik awal seluruh
proses pelacakan. Hanya tersedia untuk peran **Company Admin**,
**Operator Lab Kimia**, dan **Operator Wet Process**.

**Langkah-langkah:**
1. Dari sidebar, klik menu **Batch**, lalu klik tombol **"Buat Batch
   Baru"**.
2. Pada kolom **Kode Produk**, pilih jenis mineral yang akan diproses
   dari daftar dropdown (contoh: MIT — Mineral Ikutan Timah).
3. Sistem akan menampilkan kartu informasi produk yang dipilih, termasuk
   penanda ☢ **Radioaktif** atau 🔒 **Diblokir Ekspor** jika berlaku untuk
   produk tersebut.
4. Pada kolom **Lokasi Lab**, pilih lokasi fisik tempat batch dibuat.
5. Pada kolom **Berat Awal (kg)**, masukkan berat material dalam
   kilogram (contoh: `1000.500`).
6. Isi kolom **Catatan** (opsional) — misalnya kondisi material, sumber
   tambang, atau observasi kualitas awal.
7. Klik tombol **"Buat Batch (MIT)"**.
8. Sistem otomatis menghasilkan kode batch unik dan mengarahkan Anda ke
   halaman detail batch yang baru dibuat.

**Catatan penting**: Jangan menggunakan fitur input/scan kode RFID pada
formulir pembuatan batch ini untuk menautkan tag fisik — penautan tag
RFID yang benar dilakukan lewat menu **RFID** terpisah setelah batch
dibuat (lihat Bagian 4.1).

**[SISIPKAN SCREENSHOT: Formulir "Buat Batch Baru" dengan kolom Kode Produk, Lokasi, dan Berat Awal ditandai nomor]**

---

## 2.2 Melihat Daftar Batch

**Fungsi**: Menampilkan seluruh batch milik perusahaan dalam satu tabel,
dengan kemampuan pencarian dan penyaringan.

**Langkah-langkah:**
1. Dari sidebar, klik menu **Batch**.
2. Gunakan kolom **"Cari kode batch..."** untuk mencari batch tertentu
   berdasarkan kode.
3. Gunakan dropdown **Status** untuk menyaring batch berdasarkan status
   (contoh: Aktif, Dalam Transit, Diekspor).
4. Gunakan dropdown **Stage** untuk menyaring batch berdasarkan tahap
   proses saat ini.
5. Aktifkan toggle **Alert** untuk hanya menampilkan batch yang memiliki
   peringatan selisih berat (mass balance discrepancy).
6. Klik pada baris batch mana pun untuk membuka halaman detailnya.

**[SISIPKAN SCREENSHOT: Tabel daftar batch dengan filter di bagian atas]**

---

## 2.3 Detail Batch

**Fungsi**: Halaman pusat informasi satu batch — riwayat lengkap,
checkpoint, foto CCTV, dan hasil analisis lab (jika ada).

**Penjelasan Elemen Header:**
- **Kode Batch & Status**: Ditampilkan besar di bagian atas, dengan
  lencana warna sesuai status (Aktif = hijau berdenyut, dll).
- **Bar Progres Berat**: Menampilkan **Berat Awal**, **Berat Saat Ini**,
  persentase **Retensi**, dan **Susut** (selisih berat) secara visual.
- **Lencana Peringatan**: ☢ Material Radioaktif dan/atau 🔒 Ekspor
  Diblokir akan tampil di pojok kanan atas jika berlaku.

**Tab-Tab yang Tersedia:**
| Tab | Isi |
|---|---|
| 📊 **Ringkasan** | Informasi umum batch: produk, lokasi saat ini, riwayat singkat |
| **Timeline** | Kronologi lengkap perjalanan batch antar tahap proses |
| 📍 **Checkpoint** | Daftar seluruh pencatatan berat (checkpoint) di setiap tahap |
| 📷 **Galeri Foto** | Foto CCTV otomatis yang terekam saat scan RFID masuk/keluar |
| 🧬 **Lab Analisis** | Hasil analisis kandungan unsur LTJ (khusus batch MON yang sudah diproses di Lab LTJ) |
| **Batch Induk** | Menampilkan batch asal jika batch ini adalah hasil transformasi (contoh: hasil pemisahan Dry Process) |

**Langkah-langkah:**
1. Dari halaman daftar batch, klik kode batch yang ingin dilihat.
2. Gunakan tab di bawah header untuk berpindah antar kategori informasi.
3. Pada tab **Galeri Foto**, klik foto mana pun untuk melihat versi
   penuh/perbesar.

**[SISIPKAN SCREENSHOT: Halaman detail batch dengan tab-tab ditandai]**

---

# 3. Alur Proses Produksi

## 3.1 Ringkasan Tahapan

Batch bergerak melalui 6 tahap proses berurutan:

```
Lab Kimia → Receiving Area → Wet Process → Dry Process → [split menjadi 3]
                                                             ├─ MON → Lab Analisis LTJ → LTJ Warehouse
                                                             ├─ ZIR → LTJ Warehouse (siap ekspor)
                                                             └─ ILM → LTJ Warehouse (siap ekspor)
```

Setiap halaman tahap proses (`Menu → Proses Produksi → [pilih tahap]`)
memiliki struktur yang sama:

**Penjelasan Elemen Umum di Setiap Halaman Tahap:**
- **Tab Navigasi Tahap**: Baris tab di bagian atas untuk berpindah cepat
  antar 6 tahap, dengan badge angka menunjukkan jumlah batch menunggu di
  tahap tersebut.
- **4 Kartu Statistik**: *Masuk dari [tahap sebelumnya]*, *Di Stage Ini*,
  *Alert*, dan *Total Berat*.
- **Panel Batch Masuk (kiri)**: Daftar batch yang dikirim dari tahap
  sebelumnya dan menunggu diterima.
- **Panel Aktif di Stage Ini (kanan)**: Daftar batch yang sedang berada
  di tahap ini, lengkap dengan kolom pencarian.
- **Log Aktivitas**: Riwayat checkpoint terbaru di bagian bawah halaman.
- **Lencana Mode RFID**: Menunjukkan mode otomasi RFID yang sedang aktif
  untuk tahap ini — *RFID Audit Only* (default, hanya mencatat), *RFID
  Auto-Terima*, atau *RFID Auto-Kirim* (lihat Bagian 4).

**[SISIPKAN SCREENSHOT: Tampilan umum halaman tahap proses dengan 4 kartu statistik dan dua panel]**

### Aksi Umum yang Tersedia di Sebagian Besar Tahap

**a) Menerima Batch (Receive)**

Langkah-langkah:
1. Pada panel **Batch Masuk**, temukan batch yang ingin diterima.
2. Klik tombol **"Terima →"**.
3. Pada jendela yang muncul, pilih **Lokasi Tujuan** dari dropdown.
4. Isi **Catatan** jika diperlukan (opsional).
5. Klik tombol **"✓ Terima Batch"** untuk menyelesaikan.

**b) Mencatat Checkpoint (tanpa memindah tahap)**

Digunakan untuk mencatat berat terbaru tanpa mengirim batch ke tahap
berikutnya (contoh: pencatatan berkala selama proses berlangsung).

Langkah-langkah:
1. Pada panel **Aktif di Stage Ini**, arahkan kursor ke batch yang
   dimaksud dan klik tombol **"Checkpoint"**.
2. Masukkan **Berat Tercatat (kg)** hasil timbangan terbaru.
3. Sistem otomatis menghitung dan menampilkan **Susut** (selisih berat
   dari berat sebelumnya) dalam kg dan persentase.
4. Jika susut melebihi ambang batas (threshold), sistem menampilkan
   peringatan **⚠ Peringatan** (kuning) atau **⚠ KRITIS** (merah) dan
   meminta konfirmasi tambahan sebelum menyimpan.
5. Pilih **Lokasi** (jika relevan) dan centang **"Tandai sebagai
   terverifikasi"** apabila berat sudah dicek ulang oleh supervisor.
6. Klik tombol **"Catat Checkpoint"**.

**c) Mengirim Batch ke Tahap Berikutnya (Forward)**

Tersedia di tahap **Receiving Area** dan **Wet Process**.

Langkah-langkah:
1. Pada panel **Aktif di Stage Ini**, klik tombol **"Kirim → [nama tahap
   berikutnya]"**.
2. Masukkan **Berat Setelah Proses (kg)** — berat final sebelum
   dikirim.
3. Sistem menampilkan kalkulasi susut secara otomatis (sama seperti
   Checkpoint).
4. Pilih **Lokasi Tujuan** di tahap berikutnya.
5. Isi **Catatan** jika diperlukan.
6. Klik tombol **"→ Kirim ke [nama tahap]"**.

**[SISIPKAN SCREENSHOT: Ketiga jendela modal — Terima Batch, Catat Checkpoint, Forward Batch]**

---

## 3.2 Receiving Area

**Fungsi**: Titik penerimaan batch MIT yang dikirim dari Lab Kimia,
sebelum masuk ke proses pencucian (Wet Process). Dikelola oleh **Operator
Wet Process**.

Gunakan aksi umum **(a) Menerima Batch** dan **(c) Mengirim Batch** yang
dijelaskan di atas. Data batch yang masuk otomatis muncul di tabel
**Batch Masuk** segera setelah dibuat di Lab Kimia — tidak perlu
tindakan manual dari operator Lab Kimia untuk "mengirim" ke Receiving.

---

## 3.3 Wet Process

**Fungsi**: Tahap pencucian menggunakan *shaking table* untuk ekstraksi
awal timah (Sn) dari material MIT. Dikelola oleh **Operator Wet Process**.

Gunakan aksi umum **(a) Menerima Batch**, **(b) Checkpoint**, dan
**(c) Mengirim Batch ke Dry Process** yang dijelaskan di Bagian 3.1.

---

## 3.4 Dry Process

**Fungsi**: Tahap pemisahan menggunakan *magnetic separator* — satu batch
MIT dipisah menjadi **3 konsentrat sekaligus**: MON (Monasit, radioaktif
→ diproses lebih lanjut di Lab Analisis LTJ), ZIR (Zirkon, siap ekspor),
dan ILM (Ilmenit, siap ekspor). Dikelola oleh **Operator Dry Process**.

**a) Menerima Batch**

Gunakan langkah **(a) Menerima Batch** standar (Bagian 3.1).

**b) Transformasi (Pemisahan Menjadi 3 Konsentrat)**

Ini adalah aksi paling penting dan unik di tahap Dry Process — **tindakan
ini bersifat permanen dan tidak dapat dibatalkan** setelah dieksekusi.

Langkah-langkah:
1. Pada panel **Aktif di Stage Ini**, klik tombol **"⚡ Transformasi"**
   pada batch yang akan diproses.
2. Jendela **"Dry Process — Magnetic Separator"** akan terbuka,
   menampilkan berat batch input (MIT) di bagian atas.
3. Untuk setiap 3 konsentrat output (MON, ZIR, ILM), masukkan **Berat
   (kg)** hasil pemisahan pada kolom masing-masing. Kolom **% (auto)**
   akan terisi otomatis berdasarkan berat yang dimasukkan.
4. Perhatikan ringkasan **Mass Balance** di bagian bawah — menampilkan
   total **Input**, total **Output**, dan **Susut** secara real-time.
   Sistem akan menampilkan peringatan jika total output melebihi berat
   input.
5. Isi **Catatan** jika diperlukan.
6. Klik tombol **"⚡ Eksekusi Transformasi"**.
7. Konfirmasi ulang pada jendela peringatan yang muncul (karena tindakan
   ini permanen).
8. Sistem akan membuat **3 batch baru** (MON, ZIR, ILM), masing-masing
   dengan kode batch tersendiri yang tertaut ke batch induk (dapat
   dilihat di tab **Batch Induk** pada halaman detail batch baru).

**Ke mana masing-masing produk mengalir setelah transformasi:**
- 🔴 **MON** → wajib dikirim ke **Lab Analisis LTJ** (lihat Bagian 3.6)
- 🔵 **ZIR** → langsung ke **LTJ Warehouse**, siap ekspor
- 🟡 **ILM** → langsung ke **LTJ Warehouse**, siap ekspor

**[SISIPKAN SCREENSHOT: Jendela Transformasi Dry Process dengan 3 kolom output MON/ZIR/ILM]**

---

## 3.5 LTJ Warehouse

**Fungsi**: Gudang akhir tempat konsentrat ZIR, ILM, MON, dan produk
LTJ disimpan sebelum diekspor. Dikelola oleh **Operator Warehouse**.

**a) Dashboard Inventaris Gudang**

Khusus di halaman Warehouse, tersedia dua grafik donat tambahan di
bagian atas:

**Penjelasan Elemen:**
- **Distribusi Stok Mineral**: Grafik donat menunjukkan proporsi stok
  per jenis produk (MON/ZIR/ILM) beserta total berat dan jumlah batch.
- **Kesiapan Ekspor**: Grafik donat + tabel menunjukkan proporsi stok
  yang **Siap Ekspor** (hijau), **Diblokir Ekspor** (merah, khusus MON
  yang belum dianalisis), dan **Direservasi** (kuning).

**b) Menerima Batch dari Dry Process**

Gunakan langkah **(a) Menerima Batch** standar (Bagian 3.1).

**c) Mengirim MON ke Lab Analisis LTJ**

Langkah-langkah:
1. Cari batch dengan kode produk **MON** di panel **Batch Masuk** atau
   **Aktif di Stage Ini**.
2. Klik tombol **"→ Lab LTJ"**.
3. Pada jendela **"Kirim MON ke Lab LTJ"**, periksa kembali kode batch
   dan berat yang ditampilkan.
4. Isi **Catatan** jika diperlukan.
5. Klik tombol **"→ Kirim ke Lab LTJ"**.

**[SISIPKAN SCREENSHOT: Dashboard inventaris gudang dengan 2 grafik donat]**

---

## 3.6 Lab Analisis LTJ (Project Plant)

**Fungsi**: Tahap analisis kandungan kimia unsur Logam Tanah Jarang (LTJ)
pada konsentrat MON. Dikelola oleh **Operator Lab LTJ**. Ini adalah
tahap yang setara dengan "Project Plant" pada sistem.

### a) Dashboard Analisis Kualitas

Halaman `Menu → Lab Analisis LTJ` berfungsi sebagai pusat monitoring
kegiatan laboratorium, dari penerimaan sampel hingga finalisasi data.

**Penjelasan Elemen Dashboard:**
- **Total Analisis**: Jumlah total analisis yang pernah diinput.
- **Tervalidasi**: Jumlah analisis yang sudah disetujui/divalidasi.
- **Pending Validasi**: Jumlah analisis yang sudah diinput tapi belum
  divalidasi.
- **Rata-rata REO (%)**: Indikator kinerja rata-rata kandungan REO (Rare
  Earth Oxide) dari seluruh analisis tervalidasi.
- **Ringkasan Stok Unsur LTJ**: Kartu agregat menampilkan total stok
  5 unsur LTJ (La, Ce, Pr, Nd, Y) dari seluruh analisis yang sudah
  tervalidasi, lengkap dengan rata-rata persentase dan kegunaan tiap
  unsur.

**[SISIPKAN SCREENSHOT: Dashboard Lab Analisis LTJ dengan 4 kartu statistik dan ringkasan stok unsur]**

### b) Menginput Hasil Analisis Kandungan

Langkah-langkah:
1. Dari halaman tahap **Project Plant** (`Proses Produksi → Project
   Plant`), cari batch MON yang akan dianalisis pada panel **Aktif di
   Stage Ini**.
2. Klik tombol **"🔬 Input Lab"**.
3. Pada halaman **Input Analisis Lab LTJ**, isi bagian **Informasi Lab**:
   - **Lokasi Lab**
   - **Metode Analisis** (XRF / ICP-MS / AAS / Gravimetri)
   - **Peralatan Digunakan** (contoh: "XRF Analyzer X-500")
   - **Berat Sampel (gram)**
4. Pada bagian **Parameter LTJ (5 Unsur Aktif)**, masukkan persentase
   kandungan untuk setiap unsur:
   - **La** — Lantanum
   - **Ce** — Serium
   - **Pr** — Praseodimium
   - **Nd** — Neodimium
   - **Y** — Itrium
5. Perhatikan panel **Pratinjau Live** di sisi kanan — menampilkan
   **Total REO** (jumlah kelima unsur), estimasi berat LTJ dalam kg, dan
   rincian per unsur, yang ter-update otomatis saat Anda mengetik.
6. Jika batch bersifat radioaktif, isi juga bagian **Parameter
   Radioaktif** (opsional): **ThO₂** (Thorium Oxide) dan **U₃O₈**
   (Uranium Oxide).
7. Isi **Catatan Tambahan** jika ada kondisi sampel atau anomali yang
   perlu dicatat.
8. Klik tombol **"Simpan Analisis Lab"**.

**Catatan**: Formulir ini menyimpan draft secara otomatis di perangkat
Anda selagi mengetik (indikator **"Draft tersimpan"** akan muncul
sesaat) — jika halaman tertutup tidak sengaja sebelum disimpan, data
yang sudah diketik akan otomatis dipulihkan saat halaman dibuka kembali.

**Panduan Kualitas REO:**
| Rentang Total REO | Kategori |
|---|---|
| ≥ 10% | Sangat Baik |
| 5% – 10% | Baik |
| 2% – 5% | Cukup |
| < 2% | Rendah |

**[SISIPKAN SCREENSHOT: Formulir input 5 unsur LTJ dengan panel Pratinjau Live di kanan]**

### c) Validasi dan Kirim ke Warehouse

Setelah data analisis diinput, diperlukan proses validasi sebelum batch
dapat dikirim ke gudang (khusus peran **Company Admin** dan **Operator
Lab LTJ**).

Langkah-langkah:
1. Buka halaman **Lab Analisis LTJ**, tab **Daftar Analisis**.
2. Temukan analisis dengan status **⏳ Pending**.
3. Periksa kembali data (Total REO, metode, dsb) pada baris tabel.
4. Klik tombol **"✓ Validasi"** untuk menyetujui data.
5. Status berubah menjadi **✓ Tervalidasi**, dan tombol baru
   **"→ Warehouse"** akan muncul.
6. Klik tombol **"→ Warehouse"** untuk mengirim batch ke LTJ Warehouse.

**[SISIPKAN SCREENSHOT: Tabel daftar analisis dengan tombol Validasi dan Kirim ke Warehouse]**

---

# 4. RFID — Pelacakan Otomatis

**Konsep dasar**: Setiap karung/batch dapat ditempel tag RFID fisik.
Reader RFID (ESP32) yang terpasang di titik-titik strategis akan otomatis
mencatat kapan batch tersebut discan masuk (Scan IN) atau keluar
(Scan OUT) dari suatu area — tanpa perlu input manual.

**Mode Operasi (Default: Audit Only)**: Secara default, scan RFID hanya
mencatat *audit trail* (log) — **tidak** secara otomatis memindahkan
batch ke tahap berikutnya. Perpindahan tahap tetap dilakukan operator
secara manual lewat panel web (Bagian 3). Ini adalah keputusan desain
yang disengaja untuk menjaga kendali manusia atas keputusan penting.

## 4.1 Registrasi Tag RFID ke Batch

**Fungsi**: Menautkan satu tag RFID fisik ke satu batch tertentu, agar
sistem dapat mengenali batch tersebut setiap kali tag-nya discan.

**Langkah-langkah:**
1. Dari sidebar, buka menu **Hardware → RFID**.
2. Pada bagian **Register Tag**, pilih batch yang belum ditag dari
   dropdown (menampilkan kode batch, produk, dan tahap saat ini).
3. Klik tombol **"Aktif"** di samping dropdown untuk memulai sesi
   registrasi — sesi ini aktif selama **90 detik**.
4. Selagi sesi aktif, tempelkan tag RFID fisik ke reader ESP32 yang
   terhubung.
5. Setelah tag terbaca, sistem otomatis menautkan tag tersebut ke batch
   yang dipilih dan menampilkan pesan **"Tag terdaftar!"**.
6. Batch yang sudah ditag akan berpindah ke tab **Batch Ter-tag** pada
   halaman yang sama.

**[SISIPKAN SCREENSHOT: Panel Register Tag dengan dropdown batch dan preview LCD ESP32]**

## 4.2 Mengaktifkan Mode Scan (Masuk/Keluar Area)

**Fungsi**: Mengatur reader RFID agar siap mencatat event **Scan IN**
(masuk area) atau **Scan OUT** (keluar area) untuk tahap tertentu.

**Langkah-langkah:**
1. Pada halaman **Hardware → RFID**, tab **ESP32**.
2. Untuk mencatat batch masuk area, pada bagian **Scan IN — Rekam Masuk
   Area**, pilih tahap tujuan dari dropdown (contoh: Receiving Area, Wet
   Process, Dry Process, LTJ Warehouse, Lab Analisis LTJ), lalu klik
   tombol **"Aktif"**.
3. Untuk mencatat batch keluar area, gunakan bagian **Scan OUT — Rekam
   Keluar Area** dengan cara yang sama.
4. Panel **LCD ESP32** di sisi kanan menampilkan pratinjau tampilan LCD
   fisik alat, termasuk UID tag terakhir yang discan.
5. Setelah selesai, klik **"⏹ Stop / Idle"** untuk menonaktifkan mode
   scan aktif.

**Status Perangkat**: Indikator **ONLINE** / **OFFLINE** / **BLOCKED** di
bagian atas panel menunjukkan status koneksi reader ESP32 fisik.

**[SISIPKAN SCREENSHOT: Panel Mode Operasi RFID dengan tombol Scan IN/OUT dan status ONLINE]**

## 4.3 Mode Simulasi (Tanpa Hardware Fisik)

**Fungsi**: Menguji/mendemonstrasikan alur RFID tanpa memerlukan reader
fisik — berguna untuk pelatihan atau saat perangkat tidak tersedia.

**Langkah-langkah:**
1. Pada halaman **Hardware → RFID**, klik tab **🎮 Simulasi**.
2. Pilih batch dan jenis event (Scan IN/OUT) yang ingin disimulasikan.
3. Klik tombol simulasi untuk memicu event seolah-olah tag benar-benar
   discan oleh reader fisik.
4. Hasilnya akan langsung tercatat di tab **Log Aktivitas** dan (jika
   kamera CCTV terhubung) memicu snapshot otomatis seperti event nyata.

## 4.4 Mode Lapangan (Field Mode)

**Fungsi**: Tampilan RFID yang disederhanakan untuk digunakan di layar
sentuh kecil / tablet lapangan oleh operator, tanpa panel administrasi
lengkap.

**Langkah-langkah:**
1. Buka menu **Hardware → RFID → Mode Lapangan** (khusus perangkat di
   titik kerja fisik).
2. Antarmuka ini hanya menampilkan status scan terkini dan tidak
   memerlukan interaksi tambahan dari operator — dirancang untuk dipantau
   dari kejauhan di area kerja.

---

# 5. CCTV — Pemantauan & Bukti Visual

**Konsep dasar**: Kamera CCTV terhubung ke sistem melalui MediaMTX
(server relay video). Setiap kali ada event Scan RFID (masuk/keluar),
sistem otomatis mengambil foto dari kamera area terkait sebagai bukti
visual pendukung audit.

## 5.1 Pemantauan Langsung (Live View)

**Langkah-langkah:**
1. Dari sidebar, buka menu **Hardware → CCTV**.
2. Jika tersedia lebih dari satu kamera, pilih kamera dari daftar di
   sisi kiri (menampilkan nama dan lokasi tiap kamera).
3. Video akan mulai diputar otomatis. Watermark **LIVE** (merah)
   menandakan stream sedang aktif; watermark **SIM** (ungu) menandakan
   kamera sedang berjalan dalam mode simulasi.
4. Klik tombol **"Fullscreen"** untuk memperbesar tampilan video ke
   layar penuh.
5. Untuk melihat seluruh kamera sekaligus dalam tampilan grid kecil,
   gunakan bagian **"Semua Kamera"** di bawah video utama.
6. Jika stream gagal terhubung, sistem menampilkan pesan **"Stream Tidak
   Tersedia"** dengan tombol **"Coba Lagi"**.

**[SISIPKAN SCREENSHOT: Halaman CCTV Monitor dengan video live dan daftar kamera]**

## 5.2 Snapshot Otomatis dari RFID

**Fungsi**: Setiap kali reader RFID mencatat Scan IN/OUT, sistem otomatis
mengambil foto dari kamera area terkait tanpa perlu tindakan manual.

**Penjelasan Elemen:**
- **Panel Snapshot RFID**: Terletak di sisi kanan halaman CCTV Monitor,
  menampilkan foto-foto terbaru yang diambil otomatis, diperbarui setiap
  10 detik.
- **Notifikasi Toast**: Muncul sesaat di layar saat ada snapshot baru
  masuk, bertuliskan *"📸 Snapshot CCTV baru dari RFID scan"*.
- Klik salah satu foto untuk membukanya dalam tampilan penuh (lightbox).

**Catatan penting**: Apabila kamera fisik sedang tidak dapat dijangkau
(misalnya karena masalah jaringan), sistem akan tetap mencatat event
RFID-nya secara normal — hanya bagian foto yang akan berstatus **Gagal**
(dengan alasan tercatat), bukan seluruh proses tertunda. Foto yang gagal
tetap dapat dilihat riwayatnya di menu Riwayat Snapshot.

## 5.3 Riwayat & Galeri Snapshot

**Langkah-langkah:**
1. Dari sidebar, buka menu **CCTV → Riwayat Snapshot**.
2. Telusuri seluruh foto yang pernah terekam, disaring per batch atau
   rentang tanggal.
3. Klik tombol **"Verifikasi"** pada foto tertentu untuk menandainya
   sudah diperiksa oleh supervisor (khusus peran yang berwenang).
4. Foto yang dihapus akan dipindahkan ke **Sampah (Trash)** dan masih
   dapat dipulihkan (**"Restore"**) dalam periode tertentu sebelum
   dihapus permanen.

## 5.4 Pengaturan Kamera (Khusus Company Admin)

**Fungsi**: Menambah, mengedit, atau menghapus konfigurasi kamera CCTV
yang terhubung ke sistem.

**Langkah-langkah:**
1. Dari sidebar, buka menu **Hardware → CCTV → Pengaturan**.
2. Klik tombol **"Tambah Kamera"** untuk menambahkan kamera baru, atau
   klik ikon edit pada kamera yang sudah ada.
3. Isi **Nama Kamera** dan pilih **Checkpoint/Tahap** yang diawasi
   kamera tersebut.
4. Pilih mode kamera: **Real** (kamera fisik sungguhan, memerlukan URL
   RTSP) atau **Simulasi** (pola video uji, tanpa kamera fisik).
5. Jika mode Real, isi **RTSP URL (kamera fisik)** sesuai alamat jaringan
   kamera.
6. Simpan konfigurasi.

**Catatan**: Bagian ini bersifat teknis dan sebaiknya hanya diakses oleh
administrator sistem yang memahami konfigurasi jaringan kamera.

**[SISIPKAN SCREENSHOT: Halaman Pengaturan Kamera dengan toggle Real/Simulasi]**

---

# 6. Audit dan Pelaporan

**Fungsi**: Menghasilkan laporan resmi untuk keperluan audit internal
maupun eksternal, dalam format PDF atau Excel. Tersedia untuk peran
**Company Admin** dan **Company Auditor**.

## 6.1 Jenis-Jenis Laporan

| Jenis Laporan | Isi | Format Tersedia |
|---|---|---|
| 📦 **Laporan Traceability Batch** | Seluruh perjalanan batch dari Lab Kimia hingga ekspor/smelter, lengkap dengan checkpoint dan analisis lab | Excel, PDF |
| ⚖️ **Laporan Mass Balance** | Analisis susut material per tahap proses — untuk identifikasi kehilangan material yang tidak wajar | Excel, PDF |
| 🚢 **Laporan Ringkasan Ekspor** | Seluruh ekspor internasional dan penjualan domestik dalam periode tertentu | Excel, PDF |
| 🔬 **Laporan Analisis Lab LTJ** | Hasil analisis kimia LTJ (La, Ce, Pr, Nd, Y, ThO₂, U₃O₈) dalam periode tertentu | Excel, PDF |
| 📊 **Laporan Bulanan Lengkap** | Ringkasan komprehensif seluruh aktivitas (batch, ekspor, lab) dalam satu bulan | PDF |

## 6.2 Cara Generate & Unduh Laporan

**Langkah-langkah:**
1. Dari sidebar, buka menu **Laporan**.
2. Perhatikan 4 kartu ringkasan cepat di bagian atas: **Total Batch**,
   **Total Ekspor**, **Analisis Lab**, **Batch Bulan Ini**.
3. Pilih salah satu jenis laporan dari daftar kartu yang tersedia.
4. Pilih format keluaran: **📊 Excel** atau **📄 PDF** (sesuai yang
   tersedia untuk jenis laporan tersebut).
5. Tentukan **rentang tanggal** — tanggal mulai dan tanggal selesai data
   yang ingin dicakup dalam laporan.
6. Periksa ringkasan pilihan Anda di panel kanan.
7. Klik tombol **"Unduh Laporan"**.
8. File akan otomatis terunduh ke perangkat Anda begitu proses
   pembuatan laporan selesai.

**[SISIPKAN SCREENSHOT: Halaman Laporan dengan 5 kartu jenis laporan dan panel ringkasan unduhan]**

## 6.3 Ekspor (Export)

**Fungsi**: Mengelola dokumen dan proses ekspor batch yang siap kirim
(ZIR, ILM, dan MON yang sudah selesai dianalisis).

**Langkah-langkah:**
1. Dari sidebar, buka menu **Ekspor**.
2. Klik **"Buat Ekspor Baru"** (khusus Company Admin/Operator Warehouse).
3. Pilih batch-batch yang akan dimasukkan dalam satu pengiriman ekspor —
   sistem otomatis memvalidasi kelayakan tiap batch (contoh: menolak
   batch MON yang belum dianalisis lab).
4. Lengkapi detail pengiriman, lalu simpan.
5. Pada halaman detail ekspor, unduh dokumen pendukung yang dihasilkan
   otomatis melalui tombol unduh yang tersedia.

---

# 7. Manajemen Akun & Pengaturan

## 7.1 Profil & Sesi Pengguna (Semua Peran)

**Langkah-langkah:**
1. Klik kartu profil (nama + avatar) di bagian bawah sidebar untuk
   membuka menu dropdown.
2. Menu ini menampilkan nama lengkap, email, dan lencana peran (role)
   akun Anda, beserta nama perusahaan (jika berlaku).
3. Klik tombol **"Keluar"** untuk logout dari sistem dengan aman.
4. Gunakan tombol **Mode Gelap/Terang** dan **Bahasa** di sidebar untuk
   mengatur preferensi tampilan pribadi — pengaturan ini tersimpan di
   akun Anda dan akan tetap berlaku di sesi login berikutnya.
5. Untuk mengganti password, gunakan alur **Lupa Password** di halaman
   Login (Bagian 1.3) — cukup logout terlebih dahulu, lalu ikuti langkah
   pemulihan akun seperti biasa.

## 7.2 Manajemen Pengguna (Khusus Super Admin)

**Fungsi**: Membuat, mengedit, menonaktifkan, atau memulihkan akun
pengguna di seluruh perusahaan yang terdaftar di sistem. Fitur ini
**hanya dapat diakses oleh peran Super Admin** — Company Admin tidak
dapat membuat akun baru untuk stafnya sendiri dan harus menghubungi
Super Admin.

**Langkah-langkah membuat akun baru:**
1. Dari sidebar (tampilan Super Admin), buka menu **Manajemen Pengguna**.
2. Klik tombol **"Tambah Pengguna"**.
3. Pilih **Role** (peran) dari kartu-kartu peran yang tersedia (contoh:
   Company Admin, Operator Wet Process, Operator Dry Process, dst).
4. Isi **Nama Lengkap**, **Email**, dan **Password** awal akun.
5. Isi data tambahan (opsional): **NIP/Employee ID**, **Departemen**,
   **Nomor HP**, dan **Bahasa Default**.
6. Atur status **User Aktif/Nonaktif** — akun nonaktif tidak dapat
   digunakan untuk login.
7. Klik tombol simpan untuk membuat akun.

**Mengelola akun yang sudah ada:**
- Klik ikon edit pada baris pengguna untuk mengubah data.
- Gunakan tombol **nonaktifkan/aktifkan** untuk menangguhkan akses akun
  tanpa menghapus datanya secara permanen.
- Akun yang dihapus (soft-delete) dapat dipulihkan melalui tombol
  **"Restore"** pada tampilan filter khusus.

**[SISIPKAN SCREENSHOT: Halaman Manajemen Pengguna Super Admin dengan formulir Tambah Pengguna]**

## 7.3 Pengaturan Perusahaan (Khusus Company Admin)

**Fungsi**: Mengatur parameter operasional khusus untuk perusahaan Anda.

**Tab yang Tersedia:**
| Tab | Fungsi |
|---|---|
| ⚙️ **Umum** | Informasi dasar perusahaan (nama, kode, warna tema, dll) |
| ⚖️ **Mass Balance** | Ambang batas (threshold) peringatan dan kritis untuk selisih berat, dapat diatur per tahap proses |
| 🏭 **Warehouse** | Kapasitas dan parameter operasional gudang |
| 📊 **Laporan** | Konfigurasi terkait pembuatan laporan |

**Langkah-langkah mengubah threshold Mass Balance:**
1. Dari sidebar, buka menu **Pengaturan**, lalu tab **Mass Balance**.
2. Untuk setiap tahap proses, atur persentase **Peringatan** (warning)
   dan **Kritis** (critical) — batas susut berat yang akan memicu
   peringatan otomatis saat checkpoint dicatat.
3. Klik tombol **"Simpan Threshold"**.

**[SISIPKAN SCREENSHOT: Halaman Pengaturan tab Mass Balance dengan input threshold per tahap]**

---

# 8. Referensi Cepat

## 8.1 Matriks Peran & Akses

| Peran | Akses Utama |
|---|---|
| **Super Admin** | Mengelola seluruh pengguna & perusahaan di sistem |
| **Company Admin** | Akses penuh ke seluruh fitur perusahaannya: batch, proses, RFID, CCTV, laporan, pengaturan |
| **Operator Lab Kimia** | Membuat batch baru (MIT) |
| **Operator Wet Process** | Menerima & memproses batch di Receiving Area dan Wet Process |
| **Operator Dry Process** | Menerima & mentransformasi batch di Dry Process |
| **Operator Warehouse** | Mengelola inventaris gudang, mengirim MON ke Lab LTJ |
| **Operator Lab LTJ** | Menginput & memvalidasi analisis kandungan LTJ |
| **Company Auditor** | Akses lihat (read) + unduh laporan, tanpa hak ubah data |
| **BIM Admin/Viewer** | Dashboard regulator lintas-perusahaan |
| **Auditor Pemerintah** | Akses audit regulator (ESDM) |

## 8.2 Glosarium Istilah

| Istilah | Arti |
|---|---|
| **Batch** | Satu kelompok material yang dilacak sebagai satu kesatuan |
| **Checkpoint** | Pencatatan berat & lokasi pada satu titik waktu tertentu |
| **Mass Balance** | Prinsip kesetimbangan massa — memantau susut berat wajar vs tidak wajar |
| **MIT** | Mineral Ikutan Timah — bahan baku awal dari Lab Kimia |
| **MON / ZIR / ILM** | Monasit / Zirkon / Ilmenit — 3 konsentrat hasil Dry Process |
| **LTJ / REO** | Logam Tanah Jarang / *Rare Earth Oxide* — unsur langka bernilai tinggi (La, Ce, Pr, Nd, Y) |
| **RFID** | Teknologi identifikasi otomatis via tag & reader nirkabel |
| **Scan IN / OUT** | Event pencatatan otomatis saat batch masuk/keluar suatu area |
| **CCTV Snapshot** | Foto otomatis yang diambil sistem saat event RFID terjadi |
| **Audit Trail** | Jejak pencatatan yang tidak dapat diubah, untuk kebutuhan audit |
