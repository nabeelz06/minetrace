# MINETRA — Project Resume & Changelog

Dokumen ini mencatat perubahan signifikan pada arsitektur dan status
operasional sistem MINETRA (traceability mineral PT Timah Tbk) yang tidak
sepenuhnya tercermin dari riwayat commit git semata — terutama insiden,
root cause, dan keputusan desain yang melatarbelakanginya.

---

## 2026-07-01 — Perbaikan Pipeline RFID → CCTV Snapshot + Konsolidasi Navigasi

### Insiden
Batch yang di-tag via simulasi RFID (dan, sebelum diverifikasi eksplisit,
diduga juga hardware ESP32 asli) tidak pernah menampilkan foto CCTV di
halaman detail batch, walaupun snapshot seharusnya terekam otomatis untuk
keperluan audit setiap kali terjadi scan.

### Root Cause (dikonfirmasi berlapis, via elimination test end-to-end)
Enam lapisan masalah independen ditemukan bertumpuk, masing-masing
diverifikasi lewat trace langsung (bukan asumsi):

1. **ffmpeg tidak terpasang di host** yang menjalankan `queue:work` — hanya
   tersedia di dalam container `mediamtx`. Setiap percobaan probe/capture
   gagal dengan exit code 127.
2. **Flag `-timeout` ffmpeg deprecated** pada ffmpeg 4.4.x untuk RTSP
   demuxer — semantiknya berubah menjadi "listen mode" (server), sehingga
   ffmpeg mencoba bind ke port yang sudah dipakai mediamtx sendiri
   ("Address already in use"). Dihapus dari kedua pemanggilan ffmpeg;
   wall-clock timeout diserahkan ke wrapper shell `timeout N`.
3. **`mediamtx.yml` path `cam_gate1` diarahkan ke kamera fisik TP-Link**
   (bukan lavfi simulation loop seperti `cam_wet`/`cam_warehouse`) —
   mode simulasi untuk stage receiving diam-diam bergantung pada hardware
   LAN yang tidak reachable di lingkungan dev.
4. **`PtTimahCctvCameraSeeder` hardcode `company_id => 1`** — padahal
   `company_id=1` adalah "Badan Industri Mineral" (BIM, regulator), BUKAN
   PT Timah Tbk (yang sesungguhnya `company_id=2`). Seluruh kamera CCTV
   ter-seed ke tenant yang salah sejak awal.
5. **`CaptureCctvSnapshotJob` di-dispatch dengan `company_id` hasil resolve
   request** (default ke 1) alih-alih `company_id` milik batch — celah
   multi-tenancy yang bisa menyebabkan resolusi kamera lintas-tenant yang
   salah.
6. **`RfidPanel.vue` `simulateScan()` tidak mengirim header
   `X-Requested-With` maupun `X-Company-Code`** — setiap scan simulasi
   salah diklasifikasikan sebagai `rfid_origin=real_esp32`, dan selalu
   jatuh ke default tenant yang salah karena endpoint `/api/rfid/*`
   berjalan tanpa session (`api` middleware group, stateless).

### Perbaikan
- ffmpeg diinstal di host (`sudo apt install ffmpeg`).
- `-timeout` dihapus dari `CaptureCctvSnapshotJob::probeRtspReady()` dan
  `::captureFrame()`; `-analyzeduration`/`-probesize` ditambahkan.
- `docker/mediamtx/mediamtx.yml`: `cam_gate1` diubah menjadi lavfi
  simulation loop (pola sama dengan `cam_wet`/`cam_warehouse`).
- `PtTimahCctvCameraSeeder` sekarang resolve `company_id` dinamis via
  `Company::where('code', 'TIMAH')`.
- `RfidService::checkpointIn()`/`checkpointOut()` mengembalikan
  `company_id` milik batch di response array; `ApiRfidController`
  menggunakan nilai ini untuk dispatch job (bukan hasil resolve request).
- `RfidPanel.vue`: `simulateScan()`/`simulateRegister()` sekarang mengirim
  `X-Requested-With` dan `X-Company-Code` (sumber: prop `company_code`
  baru dari `HardwareController`).
- `ApiRfidController::resolveCompanyId()` menambahkan fallback
  `RFID_DEFAULT_COMPANY_CODE` (.env) sebelum jatuh ke
  `DEFAULT_COMPANY_ID` hardcode — mencegah asumsi keliru bahwa
  `company_id=1` selalu perusahaan operasional.
- `RfidService::getEspBootConfig()` sekarang mengembalikan `company_code`
  (string) di samping `company_id` (integer), karena endpoint scan hanya
  menerima `X-Company-Code` string, bukan ID numerik.

Diverifikasi end-to-end untuk **kedua jalur** (simulasi browser DAN
payload bergaya ESP32 asli tanpa header apa pun): `snapshot_status:
captured`, `capture_succeeded: true`, `rfid_origin` benar, file JPEG valid
di disk dengan sha256 yang cocok, dan dapat diakses publik via URL
`/storage/...`.

### Keputusan Desain — Konsolidasi Navigasi RFID
`RfidPanel.vue` (konsol admin/supervisor) dan interface scan lapangan
sebelumnya muncul sebagai dua item navigasi sidebar yang terpisah
("Hardware RFID" dan "Mobile RFID Scan"), padahal keduanya melayani
konteks kerja yang berbeda, bukan fitur duplikat. Dikonsolidasi menjadi
satu kapabilitas adaptif:

- `MobileRfidScan.vue` di-rename menjadi `RfidFieldMode.vue`, dengan
  tambahan preview snapshot CCTV (polling `recent-snapshots`) dan feedback
  `navigator.vibrate()` pada hasil scan.
- Route direstrukturisasi: `hardware/rfid/mobile` → `hardware/rfid/field`
  (`company.hardware.rfid.field`).
- `HardwareController::rfidPanel()` sekarang redirect otomatis (302
  eksplisit, bukan swap rendering diam-diam) untuk role operator stage
  yang mengakses dari User-Agent mobile.
- Link manual dua arah: "Buka Mode Lapangan" di `RfidPanel.vue`, "Buka
  Panel Lengkap" di `RfidFieldMode.vue` (khusus company_admin/auditor).
- `AppLayout.vue`: item nav "Mobile RFID Scan" dihapus — sidebar kini
  menampilkan tepat satu entri RFID.

---

## Bagian 12 — Status Operasional Saat Ini

| Komponen | Status |
|---|---|
| RFID scan (simulasi) → checkpoint audit trail | ✅ Berfungsi |
| RFID scan (payload gaya ESP32 asli) → checkpoint audit trail | ✅ Berfungsi |
| CCTV snapshot capture (simulasi, 3 stage: receiving/wet/warehouse) | ✅ Berfungsi |
| Multi-tenancy scoping (company_id batch vs kamera) | ✅ Diperbaiki |
| Progressive Authorization (audit_only / scan_to_receive / scan_to_dispatch) | ✅ Berfungsi (dari sesi sebelumnya) |
| Navigasi RFID (satu kapabilitas adaptif) | ✅ Dikonsolidasi |
| `npm run build` | ✅ 0 error |
