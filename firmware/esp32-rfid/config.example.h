// Salin ke config.h lalu isi. config.h TIDAK di-commit (lihat .gitignore).
#pragma once

#define WIFI_SSID        "NAMA_WIFI"
#define WIFI_PASSWORD    "PASSWORD_WIFI"

// Basis URL server TANPA garis miring akhir. Produksi wajib https://
#define API_BASE         "https://mining-traceability.com"

// Kunci perangkat — tampil SEKALI saat reader dibuat di menu RFID → Perangkat (awalan mtr_)
#define DEVICE_KEY       "mtr_ISI_DARI_UI"

// Tahap (stage) hanya dikirim bila reader di UI tidak terikat tahap tertentu; kosongkan untuk memakai tahap reader.
#define STAGE_OVERRIDE   ""

// Arah awal: 0 = SCAN IN (/api/rfid/login), 1 = SCAN OUT (/api/rfid/logout), 2 = DAFTAR TAG (/api/rfid/register)
// Tombol MODE (GPIO 4) menggilir 0→1→2. Reader yang arahnya dikunci di server menolak arah yang salah.
#define DEFAULT_MODE     0

// TLS: tempel sertifikat CA akar server (PEM) di ROOT_CA. Ambil dengan:
//   openssl s_client -showcerts -connect mining-traceability.com:443 </dev/null
// (ambil sertifikat PALING AKHIR dalam rantai; untuk Let's Encrypt = ISRG Root X1).
// Hanya untuk uji lokal HTTP/self-signed: ALLOW_INSECURE_TLS 1 (JANGAN di produksi).
#define ALLOW_INSECURE_TLS 0
static const char ROOT_CA[] PROGMEM = R"EOF(
-----BEGIN CERTIFICATE-----
TEMPEL_SERTIFIKAT_CA_AKAR_DI_SINI
-----END CERTIFICATE-----
)EOF";

// Pin (ESP32 DevKit V1)
#define PIN_RST   27   // RC522 RST
#define PIN_SS     5   // RC522 SDA/SS
#define PIN_BTN    4   // tombol MODE ke GND (INPUT_PULLUP)
#define PIN_BUZ   15   // buzzer aktif (opsional, -1 = tanpa)
// SPI bawaan: SCK=18 MISO=19 MOSI=23. LCD I2C: SDA=21 SCL=22 (RST RC522 di GPIO 27 agar tidak bentrok).
