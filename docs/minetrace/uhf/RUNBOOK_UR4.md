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

### Saklar lingkungan (`.env.production`)
Setelah mengubah `.env.production`, buat ulang kontainer (`restart` saja TIDAK memuat ulang `env_file`; image `worker`/`scheduler`/
`rfid_gate` membekukan konfigurasi saat build, jadi ikut dibangun ulang). Jalankan di luar jam scan aktif — `optimize:clear`
mengosongkan cache RFID (mode aktif, sesi registrasi, dedupe):
```bash
cd ~/minetrace && export COMPOSE_FILE=docker-compose.prod.yml
docker compose up -d --force-recreate app
docker compose --profile rfid-gate up -d --build worker scheduler rfid_gate
docker compose exec -T app php artisan optimize:clear && docker compose exec -T app php artisan optimize
```
| Variabel | Bawaan | Catatan |
|---|---|---|
| `RFID_TEST_RESET_ENABLED` | `true` | **Matikan (`false`) saat go-live** — menutup tombol Reset uji. Lepas tag tetap tersedia. |
| `RFID_SIMULATION_ENABLED` | sesuai env | **Matikan saat go-live** — menutup scan simulasi. |
| `RFID_UHF_REGISTRATION_WINDOW_MS` | `400` | Jendela kumpul tap saat registrasi UR4; `0` = perilaku lama (langsung). |
| `RFID_UHF_REGISTRATION_DOMINANCE_DB` | `6` | Selisih RSSI minimum agar satu tag dianggap "yang terdekat" bila beberapa terbaca. |
| `RFID_MODE_CACHE_SECONDS` | `60` | Lama mode otorisasi per tahap di-cache. Pendek agar perubahan mode di UI sampai ke daemon gate dalam hitungan detik; `0` = tanpa cache. |

### Setelah `git pull`: apa yang ikut berubah
Tidak semua kontainer memakai kode dari folder hasil `git pull` (lihat `docker-compose.prod.yml` dan `docker/php/Dockerfile`):

| Bagian | Sumber kode / tampilan | Setelah `git pull` |
|---|---|---|
| `app` (web) | folder `~/minetrace` (bind mount `./:/var/www/html`) | kode PHP langsung terbaca; cache perlu `optimize` |
| Tampilan web (`public/build`) | hasil `npm run build`; folder ini **di-ignore Git** | **tetap tampilan lama** sampai `npm run build` dijalankan |
| `worker`, `scheduler`, `rfid_gate` | salinan kode di dalam **image** (`COPY . .` + `config:cache` saat build) | **tetap menjalankan kode lama** sampai dibangun ulang |

Karena itu `git pull` saja tidak cukup: tampilan bisa tampak tidak berubah, dan daemon gate yang memproses tap reader bisa masih memakai
logika lama (mis. belum mengenal mode `scan_full`). Urutan deploy lengkap — di luar jam scan aktif, karena `optimize:clear` mengosongkan cache RFID:
```bash
cd ~/minetrace && export COMPOSE_FILE=docker-compose.prod.yml
git pull --ff-only origin main            # "Already up to date" = tidak ada kode baru di main (cek PR sudah di-merge?)
docker compose run --rm --no-deps app sh -c "npm ci && npm run build"
docker compose exec -T app php artisan optimize:clear && docker compose exec -T app php artisan optimize
docker compose --profile rfid-gate up -d --build worker scheduler rfid_gate
```
Perubahan serah-terima ini tidak menambah migrasi. Pastikan `docker/mediamtx/mediamtx.prod.yml` dan `.env.production` tidak ikut ter-commit.
Cek hanya-baca bahwa gate memakai kode baru: `docker exec minetra_rfid_gate grep -c scan_full app/Services/Hardware/RfidAuthorizationService.php`
→ `0` berarti image `rfid_gate` masih lama (belum dibangun ulang).

## Diagnosis: tag terbaca, tetapi batch tidak berpindah
Telusuri dari yang paling murah; tiap langkah menunjuk satu penyebab:
1. **RFID → Perangkat → "Kesiapan alur"** (tiap tahap × Masuk/Keluar):
   - *"Terbaca, tetapi hanya dicatat — batch TIDAK berpindah"* → mode tahap itu **Catat saja** (bawaan) atau tidak mencakup arah itu.
     Atur di "Mode otorisasi" (Dua arah / preset Otomatis).
   - *"Tidak ada reader yang membaca Scan Keluar di tahap ini"* → tidak ada reader aktif yang bisa menghasilkan arah itu. Reader "Scan Masuk saja"
     tidak pernah menghasilkan Scan Keluar; ubah jadi "Dua arah" + petakan antena kedua = Keluar (antena harus terpasang), atau tambah reader
     "Scan Keluar saja". Rantai yang hanya menerima (Receiving → Wet → Dry → Gudang, mode *Scan Masuk = terima* per tahap) tidak memerlukan Scan Keluar.
   - *"Reader offline"* → mode sudah benar tetapi tidak ada reader online (kabel/IP/daemon gate).
2. **RFID → Log baca**: baris *accepted* menampilkan hasil serah-terima — *"Batch berpindah"*, *"Batch sudah di tahap ini"*,
   *"Batch TIDAK berpindah"* (disertai alasan guard, mis. batch belum berada di tahap sebelumnya) atau *"Hanya tercatat — batch tidak berpindah"* —
   dan **"Mode gate: …"**, yaitu mode yang benar-benar dipakai daemon saat tap itu. Bila "Mode gate" berbeda dari yang diatur di UI,
   daemon memakai kode/konfigurasi lama → bangun ulang kontainer (bagian di atas).
   Perhatian: *accepted* hanya berarti tag dikenali; status batch ditentukan oleh hasil serah-terima, bukan oleh kata *accepted*.
3. **Hanya-baca di VPS** bila langkah 1–2 belum menjelaskan (jangan `SELECT *` dari `rfid_readers`: ada kolom URL kamera dan kunci):
```bash
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
SELECT stage, mode, updated_at FROM rfid_authorization_modes ORDER BY stage;   -- tidak ada baris = Catat saja
SELECT code, stage, direction, is_active, last_seen_at, connection->'antennas' AS antena FROM rfid_readers ORDER BY stage, code;
SELECT read_at, action, stage, result, result_code, meta->>'mode' AS mode, meta->>'transition' AS serah_terima
  FROM rfid_read_logs ORDER BY id DESC LIMIT 10;
SQL
```

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
- Alur Kelola Tag, mode Dua arah, reset uji, panel "Kesiapan alur", dan info "Mode gate"/serah-terima di Log baca terbukti oleh tes otomatis (PHP, JS) dan uji browser pada data uji; belum dijalankan dengan UR4 fisik pada VPS produksi — verifikasi dengan satu tag sebelum dipakai massal.
- Kemungkinan cache mode berbeda antara `app` dan `rfid_gate` (konfigurasi `rfid_gate` dibekukan saat build) hanya dibatasi oleh `RFID_MODE_CACHE_SECONDS` dan terlihat lewat "Mode gate"; belum diamati di VPS nyata.
