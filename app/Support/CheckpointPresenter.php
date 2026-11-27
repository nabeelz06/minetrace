<?php

namespace App\Support;

use App\Models\Checkpoint;
use Carbon\CarbonInterface;

/**
 * Penyaji teks checkpoint untuk laporan (Excel/PDF) — SATU sumber agar kolom konsisten di semua laporan.
 *
 *  - Stage Point : nama tahap checkpoint apa adanya ("Receiving Verify", "Lab Kimia Create", "In Transit").
 *  - Waktu       : zona WIB, sampai DETIK (d/m/Y H:i:s) — penting untuk penelusuran kronologis.
 *  - Catatan     : catatan pendaftaran tag diseragamkan menjadi
 *                  "RFID Tag terdaftar: UID XXXX oleh Receiving Area" (nama area, bukan nama orang; operator tetap
 *                  terlihat di kolom "Dicatat Oleh"). Baris lama yang masih berisi nama orang ikut diseragamkan.
 */
final class CheckpointPresenter
{
    public const TZ = 'Asia/Jakarta';

    private const REGISTER_NOTE = '/^RFID Tag terdaftar: UID (\S+) oleh .+$/u';

    public static function stagePoint(?string $stage): string
    {
        return $stage === null || $stage === '' ? '—' : ucwords(str_replace('_', ' ', $stage));
    }

    public static function time(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->timezone(self::TZ)->format('d/m/Y H:i:s') : '—';
    }

    public static function note(Checkpoint $cp): string
    {
        $notes = (string) ($cp->notes ?? '');
        if ($notes === '') {
            return '—';
        }

        $data = is_array($cp->checkpoint_data) ? $cp->checkpoint_data : [];
        if (($data['action'] ?? null) === 'rfid_register' && preg_match(self::REGISTER_NOTE, $notes, $m)) {
            $stage = $data['area_stage'] ?? ($data['batch_snapshot']['process_stage'] ?? null);

            return "RFID Tag terdaftar: UID {$m[1]} oleh " . ($data['area'] ?? ProcessFlow::areaLabel($stage));
        }

        return $notes;
    }
}
