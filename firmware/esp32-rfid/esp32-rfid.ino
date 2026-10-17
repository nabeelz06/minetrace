/*
 * MINETRACE — firmware referensi reader RFID (ESP32 + MFRC522 + LCD I2C 16x2)
 *
 * Mengikuti kontrak perangkat yang terkunci (lihat ApiRfidController):
 *   POST /api/rfid/login | /logout | /register   Body JSON {uid, stage?, event_id}
 *   POST /api/rfid/heartbeat                     Body JSON {firmware, uptime_s, rssi, free_heap, queued, device_time}
 *   Header: X-Device-Key, X-Event-Id, Content-Type: application/json
 * Respons: {status: success|failed, name, message} -> baris 1 & 2 LCD.
 *
 * Ketahanan: event_id unik per pembacaan (idempoten di server), antrean RAM bila jaringan putus
 * (dikirim ulang berurutan), jam dari NTP, watchdog, reconnect WiFi otomatis.
 * Pustaka: MFRC522 (miguelbalboa), LiquidCrystal_I2C, ArduinoJson 7.
 * STATUS: belum diuji pada perangkat keras dari repo ini — uji di meja dulu (lihat docs/INTEGRASI_PERANGKAT.md).
 */
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>
#include <esp_task_wdt.h>
#include <time.h>
#include "config.h"

#define FW_VERSION "1.0.0"
#define LCD_ADDR   0x27
#define QUEUE_MAX  20
#define HEARTBEAT_MS 30000UL
#define SAME_TAG_MS  2500UL   // abaikan tag yang sama di tempel-terus (server juga dedupe)

MFRC522 rfid(PIN_SS, PIN_RST);
LiquidCrystal_I2C lcd(LCD_ADDR, 16, 2);
WiFiClientSecure tls;

struct Pending { char uid[24]; char eventId[40]; uint8_t mode; bool used; };
Pending queue_[QUEUE_MAX];

uint8_t mode = DEFAULT_MODE;
String lastUid; unsigned long lastUidAt = 0, lastBeat = 0, lastBtn = 0;

const char* PATHS[] = {"/api/rfid/login", "/api/rfid/logout", "/api/rfid/register"};
const char* MODE_LABEL[] = {"SCAN MASUK", "SCAN KELUAR", "DAFTAR TAG"};

void show(const String& a, const String& b = "") {
  lcd.clear(); lcd.setCursor(0, 0); lcd.print(a.substring(0, 16));
  lcd.setCursor(0, 1); lcd.print(b.substring(0, 16));
}
void beep(int ms) { if (PIN_BUZ >= 0) { digitalWrite(PIN_BUZ, HIGH); delay(ms); digitalWrite(PIN_BUZ, LOW); } }
void idleScreen() { show(MODE_LABEL[mode], WiFi.status() == WL_CONNECTED ? "Tempel kartu" : "WiFi putus..."); }

void connectWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA); WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  show("Menghubungkan", "WiFi...");
  for (int i = 0; i < 30 && WiFi.status() != WL_CONNECTED; i++) { delay(500); esp_task_wdt_reset(); }
  if (WiFi.status() == WL_CONNECTED) configTime(0, 0, "pool.ntp.org", "time.google.com");
}

String newEventId(const char* uid) {
  // unik antar-boot: MAC + epoch + hitung mikro; 8..64 karakter [A-Za-z0-9._:-]
  char buf[48]; snprintf(buf, sizeof(buf), "%s-%lu-%lu", uid, (unsigned long)time(nullptr), (unsigned long)micros());
  return String(buf);
}

bool post(const char* path, const String& body, const String& eventId, int& http, String& resp) {
  HTTPClient h;
  String url = String(API_BASE) + path;
  if (!h.begin(tls, url)) return false;
  h.setTimeout(6000);
  h.addHeader("Content-Type", "application/json");
  h.addHeader("Accept", "application/json");
  h.addHeader("X-Device-Key", DEVICE_KEY);
  if (eventId.length()) h.addHeader("X-Event-Id", eventId);
  http = h.POST(body);
  resp = http > 0 ? h.getString() : "";
  h.end();
  return http > 0;   // 4xx/5xx tetap "terkirim" (jawaban server); < 0 = kegagalan jaringan
}

void showResult(const String& resp, int http) {
  JsonDocument d;
  if (deserializeJson(d, resp)) { show("Error server", "HTTP " + String(http)); beep(400); return; }
  bool ok = String((const char*)(d["status"] | "")) == "success";
  show(String((const char*)(d["name"] | "")), String((const char*)(d["message"] | "")));
  ok ? beep(120) : (beep(150), delay(100), beep(150));
}

String bodyFor(const char* uid, const char* eventId) {
  JsonDocument d; d["uid"] = uid; d["event_id"] = eventId;
  if (strlen(STAGE_OVERRIDE)) d["stage"] = STAGE_OVERRIDE;
  String s; serializeJson(d, s); return s;
}

void enqueue(const char* uid, const char* eid, uint8_t m) {
  for (auto& q : queue_) if (!q.used) { strlcpy(q.uid, uid, sizeof(q.uid)); strlcpy(q.eventId, eid, sizeof(q.eventId)); q.mode = m; q.used = true; return; }
  show("Antrean penuh", "Scan ditolak"); beep(600);
}
int queued() { int n = 0; for (auto& q : queue_) n += q.used; return n; }

// Kirim ulang antrean (event_id sama => server idempoten, tidak ada checkpoint ganda).
void flushQueue() {
  for (auto& q : queue_) {
    if (!q.used) continue;
    int http = 0; String resp;
    if (!post(PATHS[q.mode], bodyFor(q.uid, q.eventId), q.eventId, http, resp)) return;  // masih offline
    q.used = false; esp_task_wdt_reset();
  }
}

void heartbeat() {
  JsonDocument d;
  d["firmware"] = FW_VERSION; d["uptime_s"] = (long)(millis() / 1000); d["rssi"] = WiFi.RSSI();
  d["free_heap"] = (long)ESP.getFreeHeap(); d["queued"] = queued(); d["device_time"] = (long)time(nullptr);
  String body; serializeJson(d, body);
  int http = 0; String resp;
  if (post("/api/rfid/heartbeat", body, "", http, resp) && http == 401) show("Kunci salah", "Cek DEVICE_KEY");
  if (http == 403) show("Perangkat mati", "Hubungi admin");
}

void handleCard(const String& uid) {
  show("Memproses...", uid.substring(0, 16));
  String eid = newEventId(uid.c_str());
  int http = 0; String resp;
  if (WiFi.status() == WL_CONNECTED && post(PATHS[mode], bodyFor(uid.c_str(), eid.c_str()), eid, http, resp)) {
    showResult(resp, http);
  } else if (mode == 2) {
    show("Offline", "Daftar butuh net"); beep(400);   // sesi registrasi hanya sah online
  } else {
    enqueue(uid.c_str(), eid.c_str(), mode); show("Offline", "Disimpan antrean"); beep(80);
  }
  delay(1500);
  idleScreen();
}

String readUid() {
  String s; for (byte i = 0; i < rfid.uid.size; i++) { if (rfid.uid.uidByte[i] < 0x10) s += "0"; s += String(rfid.uid.uidByte[i], HEX); }
  s.toUpperCase(); return s;
}

void setup() {
  Serial.begin(115200);
  pinMode(PIN_BTN, INPUT_PULLUP);
  if (PIN_BUZ >= 0) pinMode(PIN_BUZ, OUTPUT);
  Wire.begin(); lcd.init(); lcd.backlight();
  SPI.begin(); rfid.PCD_Init();
  show("MINETRACE", "FW " FW_VERSION);
#if ALLOW_INSECURE_TLS
  tls.setInsecure();
#else
  tls.setCACert(ROOT_CA);
#endif
  esp_task_wdt_config_t wdt = {.timeout_ms = 30000, .idle_core_mask = 0, .trigger_panic = true};
  esp_task_wdt_reconfigure(&wdt); esp_task_wdt_add(NULL);
  connectWifi(); heartbeat(); idleScreen();
}

void loop() {
  esp_task_wdt_reset();
  if (WiFi.status() != WL_CONNECTED) { connectWifi(); idleScreen(); }

  if (digitalRead(PIN_BTN) == LOW && millis() - lastBtn > 300) {
    lastBtn = millis(); mode = (mode + 1) % 3; idleScreen();
  }
  if (millis() - lastBeat > HEARTBEAT_MS) { lastBeat = millis(); heartbeat(); if (queued()) flushQueue(); }

  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid = readUid(); rfid.PICC_HaltA(); rfid.PCD_StopCrypto1();
    if (uid != lastUid || millis() - lastUidAt > SAME_TAG_MS) { lastUid = uid; lastUidAt = millis(); handleCard(uid); }
  }
  delay(30);
}
