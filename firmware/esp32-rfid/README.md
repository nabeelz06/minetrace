# Firmware referensi reader RFID (ESP32 + RC522)

Kontrak: lihat komentar `ApiRfidController` dan `docs/INTEGRASI_PERANGKAT.md`. Belum diuji pada perangkat keras dari repo ini.

1. Arduino IDE / arduino-cli, board **ESP32 (core 3.x)**; pustaka: *MFRC522*, *LiquidCrystal I2C*, *ArduinoJson 7*.
2. `cp config.example.h config.h`, isi WiFi, `API_BASE`, `DEVICE_KEY` (dari UI: RFID → Perangkat → Tambah), sertifikat CA.
3. Unggah, buka Serial 115200. Di UI reader harus berstatus **online** dalam ±30 dtk (heartbeat).

Pengkabelan RC522: SDA→GPIO5, SCK→18, MOSI→23, MISO→19, RST→27, 3V3, GND (JANGAN 5 V). LCD I2C: SDA→21, SCL→22, 5 V.
Tombol MODE: GPIO4 ke GND. Buzzer aktif: GPIO15 (opsional).
