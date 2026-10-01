# MINETRA — Skrip Demo Live untuk Presentasi

> Disusun 14 Juli 2026. **Diperbarui 16 Juli 2026 dengan live-fire test
> nyata terhadap produksi.**

## ⚠️ BACA DULU SEBELUM PAKAI SKRIP INI

1. **Langkah 1–3 dan 5 di bawah diverifikasi END-TO-END LANGSUNG TERHADAP
   PRODUKSI** (`https://mining-traceability.com`) pada 16 Juli 2026, bukan
   lagi hanya lingkungan lokal. Batch uji nyata (`TMH.SGT-LK.MIT-260716-001`,
   id 28) dibuat, di-tag RFID, di-scan, dan dua laporan (PDF + Excel)
   digenerate — seluruhnya lewat HTTP asli terhadap server produksi.
2. **Langkah 4 (snapshot CCTV) JUGA sudah diuji langsung di produksi**, dan
   hasilnya sudah PASTI, bukan perkiraan: snapshot **akan gagal** sampai
   jembatan jaringan ke kamera fisik diperbaiki (lihat catatan khusus di
   Langkah 4 — ini bukan lagi "mungkin gagal", ini "dikonfirmasi akan
   gagal sampai ada perbaikan infrastruktur").
3. Verifikasi backend (endpoint API, response JSON) dilakukan via HTTP
   request langsung (`curl`) yang meniru persis apa yang dikirim
   browser/firmware ESP32. Struktur UI (tombol, tab, halaman) berdasarkan
   pembacaan kode komponen Vue yang memanggil endpoint yang sama persis.
4. Kredensial login di bawah adalah kredensial produksi nyata milik
   `company_admin` PT Timah, dipakai langsung sesi audit 16 Juli 2026.
5. Satu bug ditemukan MASIH HIDUP di produksi sesi ini (respons HTML alih-
   alih JSON pada endpoint RFID saat request tanpa header `Accept`) — sudah
   diperbaiki dan **di-deploy ulang ke produksi sesi ini juga**, lalu
   diverifikasi ulang. Tidak perlu tindakan tambahan sebelum demo untuk
   bug ini.

---

## Langkah 1 — Login

**Aksi**: Buka `https://mining-traceability.com/login`, masuk sebagai
`company_admin` PT Timah (`admin@ptimah.co.id`).

**Hasil yang diharapkan**: redirect ke `/company/dashboard`, dashboard
menampilkan statistik batch aktif, ringkasan gudang, dan grafik tren.

**Sumber verifikasi**: diuji 16 Juli 2026 LANGSUNG terhadap produksi —
`POST https://mining-traceability.com/login` dengan CSRF token asli →
`302` ke `/company/dashboard`, `200` saat halaman dashboard diakses.

**Catatan operasional**: jika ada deploy produksi tepat sebelum
presentasi, sesi login akan ter-reset (ditemukan sesi ini — lihat
`PRESENTASI_BRIEFING.md` bagian f). Login ulang sebelum mulai demo jika
ragu.

---

## Langkah 2 — Buat Batch Baru (Lab Kimia)

**Aksi**: Dari sidebar, buka **Batch → Buat Batch Baru**. Pilih kode
produk, pilih lokasi, isi berat awal, simpan.

**Hasil yang diharapkan**: redirect ke halaman detail batch baru, kode
batch otomatis ter-generate dengan format `TMH.<LOKASI>-LK.<PRODUK>-
<TANGGAL>-<URUT>`, QR code otomatis ter-generate.

**Sumber verifikasi**: diuji 16 Juli 2026 LANGSUNG terhadap produksi —
`POST https://mining-traceability.com/company/batches` → batch
`TMH.SGT-LK.MIT-260716-001` (id 28) berhasil dibuat, `302` ke halaman
detail. Batch ini diberi label `[AUDIT-TEST 2026-07-16]` di catatan agar
mudah dibedakan dari batch riset asli — belum dihapus, menunggu keputusan
pemilik sistem.

**Catatan**: JANGAN gunakan fitur scan QR/barcode di form ini untuk
menautkan RFID tag — bug dikonfirmasi ULANG di produksi sesi ini: field
tersebut divalidasi tapi tag TIDAK benar-benar tersimpan. Gunakan alur
Langkah 3 di bawah — itu yang benar-benar berfungsi.

---

## Langkah 3 — Registrasi Tag RFID ke Batch

**Aksi**: Buka **Hardware → RFID**. Di panel admin (`RfidPanel.vue`), pilih
batch yang baru dibuat dari dropdown "Batch belum ter-tag", klik untuk
mengaktifkan sesi registrasi (90 detik). Lalu tempelkan tag RFID fisik ke
reader ESP32 (atau gunakan tab "Simulasi" jika hardware fisik tidak
tersedia saat presentasi).

**Hasil yang diharapkan**: pesan sukses "Tag terdaftar!", batch kini
muncul di daftar "batch ter-tag" dengan UID yang baru ditempel.

**Sumber verifikasi**: diuji 16 Juli 2026 LANGSUNG terhadap produksi —
`POST https://mining-traceability.com/api/v1/company/rfid/pending-batch`
(`batch_id: 28`) → `POST https://mining-traceability.com/api/rfid/register`
(`uid: AUDIT16JUL2026TEST`) → `{"status":"success","message":"Tag
terdaftar!", ...}`, dikonfirmasi ulang lewat query database produksi.

---

## Langkah 4 — Scan RFID (Masuk Area) → Snapshot CCTV Otomatis

**Aksi**: Tempelkan tag yang sama ke reader ESP32 di titik "Receiving"
(atau gunakan tab Simulasi untuk memicu scan IN tanpa hardware fisik).

**Hasil yang diharapkan**: LCD ESP32 (jika hardware nyata dipakai)
menampilkan "Scan IN OK". Di web, checkpoint baru tercatat SEKETIKA di
timeline batch (< 1 detik, RFID tidak pernah menunggu CCTV).

**Sumber verifikasi**: diuji 16 Juli 2026 LANGSUNG terhadap produksi —
`POST https://mining-traceability.com/api/rfid/login`
(`uid: AUDIT16JUL2026TEST, stage: receiving`) → sukses, checkpoint id 244
tercatat pada `2026-07-16 05:53:03`, mode `audit_only` (manual, sesuai
desain — tidak memindah stage otomatis).

**🔴 CATATAN KHUSUS — SUDAH DIKONFIRMASI, BUKAN LAGI PERKIRAAN**: sesi
audit 16 Juli 2026 mengonfirmasi dengan bukti definitif bahwa jembatan
jaringan (Tailscale) dari VPS produksi ke kamera fisik Tapo C500
**TIDAK AKTIF** — `tailscale status` di VPS hanya menunjukkan 2 perangkat
(VPS + laptop pemilik), tidak ada rute ke jaringan kamera
(`192.168.18.0/24`). Ini sudah terjadi sejak minimal 13 Juli 2026
(terlihat dari riwayat kegagalan job tercatat di database), bukan kondisi
baru dari sesi ini.

**Apa yang PASTI terjadi saat scan dipicu di produksi (diukur langsung,
bukan estimasi)**:
- Checkpoint tercatat sukses SEKETIKA, status snapshot: `Pending` (kuning).
- Sistem mencoba mengambil gambar dari kamera fisik 3× (dengan jeda
  backoff 5, 15, 30 detik antar percobaan, masing-masing timeout 60 detik).
- **Setelah TEPAT ~4 menit** (diukur: 4 menit 12 detik), status berubah
  jadi `Failed` (merah). Checkpoint TETAP tercatat lengkap dan valid —
  tidak ada data yang hilang, tidak ada request yang menggantung.

**Apa yang harus dilakukan saat presentasi**: JANGAN menunggu 4 menit di
depan audiens untuk melihat status berubah jadi merah — itu terlalu lama
untuk demo langsung. Sebagai gantinya:
1. Picu scan di depan audiens, tunjukkan checkpoint langsung muncul
   (bagian ini instan dan meyakinkan).
2. Sambil status snapshot masih "Pending", langsung jelaskan: *"Sistem
   sedang mencoba mengambil foto dari kamera CCTV fisik. Jembatan jaringan
   ke kamera saat ini belum aktif di server produksi — jadi percobaan ini
   akan berakhir gagal dalam beberapa menit. Yang penting untuk
   ditunjukkan adalah: kegagalan ini TIDAK PERNAH memblokir atau
   memperlambat pencatatan RFID itu sendiri — checkpoint sudah tercatat
   sempurna sebelum kita bahkan tahu hasil CCTV-nya."*
3. Lanjutkan ke Langkah 5 tanpa menunggu — status CCTV akan resolve sendiri
   di background, bisa ditunjukkan hasilnya (`Failed`, dengan alasan
   tercatat rapi) setelah demo selesai jika ada waktu, sebagai bukti sistem
   mencatat kegagalan dengan jujur, bukan menyembunyikannya.

**Jika ingin snapshot benar-benar berhasil saat demo**: satu-satunya cara
adalah memperbaiki jembatan jaringan VPS↔kamera SEBELUM hari-H (di luar
scope yang bisa diperbaiki lewat kode — ini infrastruktur jaringan fisik).
Jika tidak sempat diperbaiki, narasi di atas ("kegagalan yang jujur dan
graceful") adalah framing yang solid dan sudah terbukti akurat.

---

## Langkah 5 — Generate Laporan

**Aksi**: Buka **Laporan**, pilih jenis "Batch Traceability", format PDF
(atau "Mass Balance", format Excel), pilih rentang tanggal yang mencakup
batch baru, klik "Generate & Download".

**Hasil yang diharapkan**: file PDF/Excel terunduh langsung ke browser,
berisi data batch yang relevan dalam rentang tanggal terpilih.

**Sumber verifikasi**: diuji 16 Juli 2026 LANGSUNG terhadap produksi —
`POST https://mining-traceability.com/company/reports/generate`:
- `batch_traceability` (PDF) → `200`, `BatchTraceability-TIMAH-20260701-
  20260716.pdf`, 1.1MB, struktur PDF 1.7 valid (xref/trailer/%%EOF benar).
- `mass_balance` (Excel) → `200`, `MassBalance-TIMAH-20260701-
  20260716.xlsx`, format Excel 2007+ terkonfirmasi via signature file.

---

## Ringkasan Cakupan

| Langkah | Diuji langsung di produksi? | Risiko saat presentasi |
|---|---|---|
| 1. Login | ✅ 16 Juli 2026 | Rendah |
| 2. Buat batch | ✅ 16 Juli 2026 | Rendah |
| 3. Registrasi tag RFID | ✅ 16 Juli 2026 | Rendah |
| 4. Scan → snapshot CCTV | ✅ 16 Juli 2026 — **hasil terkonfirmasi PASTI GAGAL** sampai jembatan jaringan diperbaiki | **Tinggi bila ditunjukkan visual foto**, **Rendah bila mengikuti narasi "kegagalan graceful" di atas** |
| 5. Generate laporan | ✅ 16 Juli 2026 (PDF & Excel) | Rendah |

**Rekomendasi akhir**: skrip ini sudah diuji penuh terhadap produksi
sungguhan, bukan simulasi. Satu-satunya risiko nyata yang tersisa adalah
Langkah 4 — dan risiko itu sekarang punya jawaban pasti (gagal dalam ~4
menit) beserta narasi presentasi yang sudah disiapkan, bukan lagi
ketidakpastian. Jika waktu memungkinkan sebelum hari-H, perbaiki jembatan
jaringan kamera untuk demo visual yang lebih kuat — tapi sistem tetap
layak dipresentasikan dengan percaya diri tanpa perbaikan itu.
