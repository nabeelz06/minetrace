<?php

namespace App\Support;

/**
 * Batas persen susut agar muat di kolom DB.
 *
 * Berat yang salah ketik (mis. 0,01 kg untuk drum 1.000 kg, lalu container 90 kg) menghasilkan persen ekstrem
 * (−899.900%) yang melampaui kolom decimal dan membuat PostgreSQL menolak simpan ("numeric field overflow"),
 * sehingga halaman error 500. Nilai dibatasi ke rentang kolom; batch tetap ditandai alert oleh pemanggil.
 *
 *  - batches.mass_loss_pct, checkpoints.weight_loss_pct : decimal(5,2)  → ±999.99
 *  - batch_pools.loss_pct                                : decimal(7,2)  → ±99999.99
 */
final class LossPct
{
    public const MAX_5_2 = 999.99;

    public const MAX_7_2 = 99999.99;

    public static function clamp(mixed $value, float $max = self::MAX_5_2): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(-$max, min($max, round((float) $value, 2)));
    }
}
