# Integrasi Perangkat — CCTV, Reader RFID, Tag RFID

Panduan urut untuk menghubungkan perangkat nyata ke MINETRACE. Verifikasi di tiap langkah dengan
`php artisan hardware:check` (hanya-baca; di produksi: `docker compose -f docker-compose.prod.yml exec app php artisan hardware:check`).

## 0. Topologi (baca dulu)

```
 Tag ─ Reader ESP32+RC522 ──HTTPS──▶ nginx ─▶ Laravel ─▶ Postgres/Redis ─▶ queue worker ─ffmpeg─▶ snapshot
                                                                  ▲
 Kamera Tapo (LAN pabrik) ──RTSP──▶ MediaMTX ──HLS (/hls/)──▶ browser
```

**Masalah utama CCTV di VPS:** kamera berada di jaringan lokal (mis. `192.168.18.38`). VPS (`31.97.222.70`) TIDAK dapat
menjangkau IP privat. Pilih satu:

| Opsi | Cara | Catatan |
|---|---|---|
| A. VPN (disarankan) | Tailscale/WireGuard: pasang di VPS dan satu mini-PC/router di pabrik; isi RTSP kamera dengan IP VPN/LAN yang dirutekan | Tidak membuka port kamera ke internet |
| B. MediaMTX di lokasi | Jalankan MediaMTX/ffmpeg di pabrik yang *mendorong* (publish) RTSP ke VPS | Perlu membuka port publish dengan autentikasi |
| C. Port-forward RTSP | **Jangan.** Kamera Tapo tidak boleh terekspos ke internet | — |

Kebutuhan lain: ffmpeg di image app (sudah ada), `queue:work` berjalan (service `worker`), HTTPS pada domain
(ESP32 tidak bisa mengikuti redirect 301 pada POST — selalu gunakan `https://` di `API_BASE`).

## 1. Server siap

1. `git pull` cabang yang sudah di-merge, `docker compose -f docker-compose.prod.yml up -d --build`,
   `php artisan migrate --force && php artisan optimize` (migrasi `rfid_readers`, `rfid_tag_events`, integritas scan bersifat aditif).
2. `.env.production`: `APP_URL=https://…`, `QUEUE_CONNECTION=redis`, `CCTV_MEDIAMTX_RTSP=rtsp://mediamtx:8554`,
   `CCTV_MEDIAMTX_API=http://mediamtx:9997`, `RFID_SIMULATION_ENABLED=false` (setelah pelatihan).
3. `hardware:check` harus tanpa ✘.

## 2. Kamera CCTV

1. Tapo C500: aplikasi Tapo → Pengaturan lanjutan → **Akun kamera** (buat user/sandi RTSP). Beri IP tetap (DHCP reservation).
2. Uji dari mesin yang berada di jaringan kamera: `ffprobe rtsp://USER:SANDI@IP/stream2` (sandi ber-karakter khusus di-URL-encode, mis. `!`→`%21`).
3. UI: **CCTV → Pengaturan → Tambah Kamera** (RTSP real, tahap/lokasi). Path MediaMTX dibuat otomatis; satu kamera boleh dipakai beberapa tahap.
4. Matikan flag simulasi pada kamera tersebut. Buka **CCTV Monitor**: stream harus tampil (tunggu ±15 dtk).
5. Tes snapshot: tombol snapshot di monitor, atau scan RFID — foto muncul pada checkpoint (butuh `worker`).

> `docker/mediamtx/mediamtx.prod.yml` memuat sandi kamera ter-hardcode dan ada di riwayat git. **Ganti sandi kamera**
> dan utamakan provisioning lewat UI. Port MediaMTX tidak dipublikasikan ke host (hanya via nginx `/hls/`) — pertahankan.

## 3. Reader RFID (ESP32 + RC522)

1. Rakit sesuai `firmware/esp32-rfid/README.md`; muat firmware (belum diuji pada perangkat keras dari repo ini — uji di meja).
2. UI: **RFID → Perangkat → Tambah reader**: kode (mis. `RCV-IN-01`), tahap, arah (`in`/`out`/`any`). **Salin kunci `mtr_…` — hanya tampil sekali.**
3. Isi `config.h`, unggah. Dalam ≤30 dtk status reader **online** (heartbeat); periksa juga jam perangkat (`clock_ok`).
4. Tes tanpa perangkat keras (dari laptop):

```bash
KEY=mtr_xxxxx; BASE=https://domain-anda
curl -s -X POST $BASE/api/rfid/heartbeat -H "X-Device-Key: $KEY" -H 'Content-Type: application/json' -d '{"firmware":"curl"}'
curl -s -X POST $BASE/api/rfid/login     -H "X-Device-Key: $KEY" -H 'Content-Type: application/json' -d '{"uid":"04A1B2C3","event_id":"test-0001"}'
```

5. Setelah semua reader terdaftar: `RFID_REQUIRE_DEVICE_KEY=true` (menolak perangkat anonim) dan `php artisan optimize`.
6. Rotasi kunci bila perangkat hilang: UI → reader → Rotasi kunci (kunci lama langsung mati).

## 4. Tag RFID

1. UI: **detail batch → kartu Tag RFID → Daftarkan** (sesi 90 dtk), lalu tempel tag ke reader mode **DAFTAR TAG** (tombol MODE).
2. Layar menampilkan kode batch + "Tag terdaftar!". UID yang sudah dipakai ditolak; tag hilang: **Lepas tag** beralasan.
3. Scan masuk/keluar mencatat checkpoint (audit trail) + snapshot kamera tahap tersebut. Perpindahan tahap tetap manual via web.
4. Tag MIFARE/NTAG 13,56 MHz (ISO14443A). UID dinormalisasi (huruf besar, tanpa spasi/titik dua).

## 5. Uji penerimaan

- [ ] `hardware:check` tanpa ✘; reader online; jam OK.
- [ ] Daftar 1 tag → scan IN → scan OUT; riwayat scan menampilkan dua entri dengan foto.
- [ ] Scan ganda cepat tidak membuat checkpoint ganda (dedupe 3 dtk).
- [ ] Cabut kabel WiFi → scan 2 kali → pulihkan → entri masuk sekali masing-masing (antrean + `event_id`).
- [ ] Reader dinonaktifkan di UI → scan ditolak (403) dan layar menampilkan "Error / Perangkat dinonaktifkan".
- [ ] UHF: registrasi di Receiving (reader desktop) → lewat gate Wet → Dry → Gudang; Log Pembacaan menampilkan tiap entri + foto; tag asing di gate tercatat Ditolak + foto.
- [ ] Beban: `php artisan rfid:benchmark` (non-produksi) dan `tests/load/rfid-scan.k6.js` terhadap staging.

## 6. Reader UHF — Receiving (desktop USB) & Gate (Chainway UR4)

Alur karung: **Receiving** (registrasi tag, reader desktop) → **gate Wet** → **gate Wet→Dry** → **gate Gudang** (reader UR4).
Setiap pembacaan — diterima, ditolak (tag asing, arah salah, tag sudah dipakai), maupun duplikat — tercatat di
tabel `rfid_read_logs` dan tampil di **RFID → Log Pembacaan**. Setiap log selain duplikat memicu capture kamera
Tapo milik tahap reader (job `CaptureCctvSnapshotJob`, butuh `worker`); foto ditautkan ke log dan ke checkpoint.

```
 Reader desktop R2000 ─USB(Web Serial)─▶ browser PC Receiving ─HTTPS─▶ Laravel ─▶ checkpoint + rfid_read_logs ─▶ capture Tapo
 Reader UR4 (gate) ◀─TCP 8888─ php artisan rfid:uhf-gate (mesin di LAN reader) ─▶ RfidScanService ─▶ (sama)
```

### 6a. Reader desktop R2000 (Receiving Area)

Reader USB "UHF reader/writer" modul UCM60x (jembatan QinHeng `1a86:fe0c`, protokol R2000 `0xA0`, 9600 8N1, RTS wajib —
referensi: github.com/frebergguru/uhf_reader). Dibaca **langsung oleh browser** lewat Web Serial — tanpa aplikasi tambahan.

1. PC Receiving: Chrome/Edge desktop terbaru; halaman dibuka lewat **HTTPS** (atau `localhost`). Windows: driver CH34x/CDC bawaan.
2. UI: **RFID → Perangkat → Tambah reader** — jenis **UHF desktop USB (Receiving)**, tahap *Receiving Area*, arah *Scan Masuk*.
   Kunci perangkat tidak dipakai (browser memakai sesi login operator).
3. **RFID → Daftarkan Tag** → pilih batch → cara **Reader UHF desktop** → **Hubungkan reader** (pilih port; browser mengingatnya)
   → letakkan tag → **Baca & daftarkan**. Bila >1 tag terbaca, operator memilih tag karung tersebut.
4. Baud lain: `RFID_UHF_DESKTOP_BAUD`. Firmware tidak terbaca = baud/port salah.

### 6b. Reader gate Chainway UR4 (Wet, Wet→Dry, Gudang)

Reader jaringan UR4 (TCP, port default 8888; referensi protokol: github.com/ready2tag/chainway-rfid).

1. Beri reader IP tetap. Uji dari mesin daemon: `nc -vz 192.168.99.202 8888`.
2. UI: **RFID → Perangkat → Tambah reader** per gate — jenis **UHF gate Chainway UR4**, isi IP/port, tahap
   (`wet_process`, `dry_process`, `warehouse`), arah:
   - satu arah per reader → set *Scan Masuk* / *Scan Keluar*;
   - satu reader dua antena (masuk & keluar) → arah *Dua arah* + **Arah per antena** (mis. antena 1 = Masuk, 2 = Keluar).
   Gate Wet→Dry umumnya = antena *Keluar* tahap Wet **atau** antena *Masuk* tahap Dry — pilih satu agar tidak tercatat ganda.
3. Jalankan daemon di mesin yang menjangkau reader (satu proses melayani semua reader UR4 aktif; reader baru terpakai ≤60 dtk):

```bash
php artisan rfid:uhf-gate                          # semua reader UR4 aktif
php artisan rfid:uhf-gate --reader=WET-GATE-01     # reader tertentu
docker compose -f docker-compose.prod.yml --profile rfid-gate up -d rfid_gate   # produksi (restart otomatis)
```

   Daemon sambung ulang otomatis, mengirim heartbeat (status **online** + jumlah peristiwa/pembacaan di kartu reader).
4. Penyaringan: tag yang terus terbaca dihitung **sekali per kehadiran** (`RFID_UHF_PRESENCE_SECONDS`, default 10 dtk; per reader
   di form). Tag lemah dari lajur sebelah: isi **RSSI minimum** (mis. -65) atau kurangi daya antena di reader.
5. `php artisan hardware:check` menguji keterjangkauan TCP tiap reader UR4.

> Server di VPS tidak dapat menjangkau IP LAN pabrik — jalankan `rfid:uhf-gate` di mini-PC pabrik yang terhubung ke database
> lewat VPN (Tailscale/WireGuard), sama seperti opsi A untuk kamera.
