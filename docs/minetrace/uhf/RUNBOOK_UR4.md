# Runbook — Gate UHF Chainway UR4 (lokal/WSL dan pabrik)

## Aturan keselamatan
1. **UR4 melayani SATU klien.** Selama `rfid:uhf-gate` berjalan, jangan buka koneksi lain ke reader (`nc`, `telnet`, UHFAPP.exe,
   `rfid:uhf-probe --force`). Koneksi kedua merebut aliran; gate berhenti membaca (status `contested`).
2. **Daya RF:** meja uji ≤ 15 dBm (`RFID_UHF_MAX_POWER_DBM=15`). Bila daya terbaca melebihi batas, inventory TIDAK dimulai
   (`config_mismatch`). Kode tidak pernah menulis konfigurasi ke reader (hanya baca).
3. Antena wajib terpasang sebelum inventory; tanpa antena reader diam.

## Simulasi tanpa perangkat
```bash
php tools/uhf/fake-ur4-server.php --port=18888 --epc=E20000200914016323809025
# env: RFID_UHF_ALLOWED_PORTS=8888,18888 (loopback hanya di APP_ENV=local atau RFID_UHF_ALLOW_LOOPBACK=true)
php artisan rfid:uhf-probe --host=127.0.0.1 --port=18888 --listen=3
```
Daftarkan reader di UI (RFID → Perangkat) dengan host `127.0.0.1`, port `18888`, lalu `php artisan rfid:uhf-gate`.
Skenario: `--scenario=normal|silent|doa`.

## Dengan UR4 asli (WSL)
1. WSL2 harus menjangkau subnet reader (mode mirrored networking atau bridged); IP bawaan `192.168.99.202:8888`.
2. `php artisan rfid:uhf-probe --host=192.168.99.202` → harus `HASIL: OK`, firmware terbaca, daya ≤ batas.
3. Daftarkan reader di UI (jenis UHF gate Chainway UR4, antena → arah), jalankan `php artisan rfid:uhf-gate`.
4. Dekatkan tag: satu peristiwa per kehadiran (`RFID_UHF_PRESENCE_SECONDS`), tercatat di RFID → Log baca + capture CCTV.

## Alur harian di aplikasi web (RFID)
1. **Mode otorisasi — sekali, administrator** (RFID → Perangkat & Pengaturan → Mode otorisasi). Bawaan **Catat saja** hanya
   MENCATAT scan: status batch tidak berpindah (ini penyebab "tag terbaca tetapi batch tetap di Receiving"). Untuk serah-terima
   otomatis pilih **Dua arah** per tahap (Masuk = batch diterima di tahap itu, Keluar = batch dikirim ke tahap berikutnya) atau
   preset **Otomatis di semua pos**. Guard-nya identik dengan tombol di panel web (`StageTransitionService`).
2. **Daftarkan Tag:** pilih batch → tempelkan tag → hasil. Bila tag sudah dipakai batch lain, wizard menyebut batch pemiliknya dan
   menawarkan **Lepas dari batch itu & pakai di sini**. Beberapa tag di dekat reader: tidak ada yang didaftarkan (sisakan satu).
3. **Scan Masuk / Keluar:** hasil menyebut apa yang terjadi pada batch — *DITERIMA/DIKIRIM*, *sudah di tahap ini*, atau *hanya
   tercatat* (mode Catat saja) — plus posisi batch saat ini.
4. **Kelola Tag** (RFID → Kelola Tag; juga lewat tombol "Kelola tag" di hasil scan dan di detail batch):
   - **Tag terbaru dibaca** (otomatis diperbarui): tempelkan tag pada reader, pilih barisnya → status *Bebas / Terpasang / Di batch
     arsip / Perusahaan lain*, batch pemilik, posisi, jumlah scan.
   - **Reset uji** (administrator; menyembunyikan scan & serah-terima tag itu dan mengembalikan batch ke posisi saat tag didaftarkan;
     tag tetap terpasang → bisa dites ulang dengan tag yang SAMA). Ditolak otomatis bila batch sudah ditutup (ekspor/terminal), posisi
     awal tidak tercatat (tag didaftarkan sebelum fitur ini), atau setelah registrasi ada pekerjaan manual (analisis lab, split, catatan).
   - **Lepas tag** (administrator & operator; alasan baku): membebaskan UID agar bisa didaftarkan ke batch lain (juga batch arsip).
   Tidak ada riwayat yang dihapus: catatan hanya diberi tanda *dibatalkan* (siapa, kapan, mengapa) dan tampil di riwayat tag.

### Saklar lingkungan (`.env.production`, lalu `docker compose up -d --build`)
| Variabel | Bawaan | Catatan |
|---|---|---|
| `RFID_TEST_RESET_ENABLED` | `true` | **Matikan (`false`) saat go-live** — menutup tombol Reset uji. Lepas tag tetap tersedia. |
| `RFID_SIMULATION_ENABLED` | sesuai env | **Matikan saat go-live** — menutup scan simulasi. |
| `RFID_UHF_REGISTRATION_WINDOW_MS` | `400` | Jendela kumpul tap saat registrasi UR4; `0` = perilaku lama (langsung). |
| `RFID_UHF_REGISTRATION_DOMINANCE_DB` | `6` | Selisih RSSI minimum agar satu tag dianggap "yang terdekat" bila beberapa terbaca. |

## Diagnosis `link_state` (panel Perangkat)
| link_state | Arti | Tindakan |
|---|---|---|
| streaming | Inventory berjalan | — |
| unreachable | TCP gagal | cek IP/kabel/VLAN, daya reader |
| contested | Klien lain merebut slot | tutup UHFAPP/nc; tunggu backoff 60 dtk |
| stalled | Reader berhenti sendiri berulang | cek antena/daya, restart reader |
| config_mismatch | Daya > batas / mode tak didukung / tanpa antena | perbaiki di reader; gate tidak memancar |
| configuring/connecting | Transisi | tunggu |

Kode keluar `rfid:uhf-probe`: 0 OK · 2 tak terjangkau · 3 handshake/direbut/macet · 4 konfigurasi tidak aman · 5 alamat ditolak/kunci dipegang.

## Belum terbukti
- Electron HW-VX6336: protokol Chafon 57600 terbukti di perangkat nyata (pembacaan serial). Alur Web Serial di browser belum diuji di Chrome/Edge.
- Perilaku UR4 fisik (DOA, jeda sambung ulang) baru dari satu unit sumber; ukur di unit sendiri.
- Alur Kelola Tag, mode Dua arah, dan reset uji terbukti oleh tes otomatis (PHP) dan uji browser pada data uji; belum dijalankan dengan UR4 fisik pada VPS produksi — verifikasi dengan satu tag sebelum dipakai massal.
