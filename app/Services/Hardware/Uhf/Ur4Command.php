<?php

namespace App\Services\Hardware\Uhf;

/**
 * Daftar izin (allowlist) perintah UR4 yang boleh dibangun oleh kode ini.
 *
 * Sengaja HANYA berisi perintah yang aman: perintah di luar daftar tidak punya builder sama sekali, jadi
 * tidak bisa terkirim walau ada bug di tempat lain. Yang DILARANG MUTLAK (tanpa builder, bahkan di balik flag):
 * 0x1C (kode 0x01 memutus modul), 0xC0-0xC6 (firmware), 0x74 (reset pabrik, mengubah IP), 0x68 (reset
 * perangkat lunak), seluruh 0xA1 (IP/mode kerja/buzzer/GPIO), 0x24 (gelombang pembawa terus-menerus), dan
 * semua perintah tulis/kunci/hapus memori tag. Perlu salah satunya? Berhenti dan tanya pemilik proyek.
 */
enum Ur4Command: int
{
    // ─── Baca-saja ────────────────────────────────────────────────────────
    case Version = 0x02;
    case Power = 0x12;
    case Region = 0x2E;
    case AntennaMask = 0x2A;
    case AntennaConnection = 0x4E;
    case InventoryMode = 0x72;
    case StartInventory = 0x82;
    case StopInventory = 0x8C;
    /** Return loss per port: memancar sesaat, hanya lewat opt-in eksplisit. */
    case ReturnLoss = 0x26;

    // ─── Menulis konfigurasi (hanya --apply-config setelah persetujuan; save selalu 0) ───
    case SetPower = 0x10;
    case SetRegion = 0x2C;
    case SetInventoryMode = 0x70;
    case SetAntennaMask = 0x28;

    public function isConfigWrite(): bool
    {
        return match ($this) {
            self::SetPower, self::SetRegion, self::SetInventoryMode, self::SetAntennaMask => true,
            default => false,
        };
    }

    /** Memancar RF (inventory atau pengukuran): butuh antena terpasang. */
    public function isRadiating(): bool
    {
        return $this === self::StartInventory || $this === self::ReturnLoss;
    }

    /** Cmd balasan = Cmd permintaan + 1; start inventory tidak punya ack (langsung aliran 0x83). */
    public function replyCmd(): ?int
    {
        return $this === self::StartInventory ? null : $this->value + 1;
    }
}
