<?php

namespace App\Services\Batch;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use Illuminate\Support\Facades\DB;

/**
 * BatchCodeService — generator kode batch yang atomic dan race-condition-safe.
 *
 * Formula: [ENT].[LOK]-[PROC].[PROD]-[DDMMYY]-[SEQ]   (tanggal, bulan, tahun, lalu nomor urut harian)
 * Contoh: TMH.SGT-LK.MIK-040526-001   (4 Mei 2026, batch ke-1 hari itu)
 *
 * Kode lama berformat [YYMMDD] (mis. 260504) TIDAK diubah; hanya kode baru yang memakai urutan tanggal-bulan-tahun.
 * Tanggal memakai zona WIB agar sama dengan kalender operator di lapangan (bukan UTC). Urutan tahun 4 digit bisa
 * dipilih lewat config('batch.code_date_format') = 'dmY'.
 *
 * Menggunakan PostgreSQL native sequence per-tanggal untuk
 * memastikan tidak ada duplicate bahkan saat concurrent requests.
 */
class BatchCodeService
{
    private const TZ = 'Asia/Jakarta';

    /** Format tanggal pada kode: 'dmy' (DDMMYY, bawaan) atau 'dmY' (DDMMYYYY). Nilai lain jatuh ke bawaan. */
    public static function dateFormat(): string
    {
        $f = (string) config('batch.code_date_format', 'dmy');

        return in_array($f, ['dmy', 'dmY'], true) ? $f : 'dmy';
    }

    /**
     * Kode perusahaan (3 huruf).
     */
    private const COMPANY_CODES = [
        'TIMAH' => 'TMH',
        'BIM'   => 'BIM',
        'VALE'  => 'VAL',
    ];

    /**
     * Kode proses per stage (2-3 huruf).
     */
    private const STAGE_CODES = [
        'lab_kimia'     => 'LK',
        'receiving'     => 'RA',
        'wet_process'   => 'WP',
        'dry_process'   => 'DP',
        'project_plant' => 'PP',
        'warehouse'     => 'WH',
        'smelter'       => 'SM',
    ];

    /**
     * Generate kode batch baru yang unik secara atomic.
     * Menggunakan PostgreSQL sequence untuk race-condition protection.
     *
     * @param  Company     $company   Perusahaan pemilik batch
     * @param  Location    $location  Lokasi pembuatan batch
     * @param  string      $stage     Tahap proses (lab_kimia, wet_process, dll.)
     * @param  ProductCode $product   Kode produk
     * @return string                 Batch code yang unik
     */
    public function generate(
        Company $company,
        Location $location,
        string $stage,
        ProductCode $product
    ): string {
        $entCode  = self::COMPANY_CODES[$company->code] ?? strtoupper(substr($company->code, 0, 3));
        $lokCode  = strtoupper($location->code);
        $procCode = self::STAGE_CODES[$stage] ?? strtoupper(substr($stage, 0, 2));
        $prodCode = strtoupper($product->code);
        $now      = now(self::TZ);
        $date     = $now->format(self::dateFormat());

        // ─── Atomic sequence menggunakan PostgreSQL SEQUENCE ───────────────
        // Setiap kombinasi company+hari punya sequence sendiri. Nama sequence tetap berbasis Ymd (bukan format
        // tampilan) agar tidak bergantung pada pilihan urutan tanggal pada kode.
        $seqName = "batch_seq_{$company->id}_" . $now->format('ymd');

        // Buat sequence jika belum ada (idempotent & atomic)
        DB::statement("CREATE SEQUENCE IF NOT EXISTS {$seqName} START 1");

        // Ambil nomor berikutnya (atomic, tidak bisa duplikat)
        $seq = DB::selectOne("SELECT nextval('{$seqName}') as seq")->seq;

        return "{$entCode}.{$lokCode}-{$procCode}.{$prodCode}-{$date}-" .
               str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate kode untuk child batch dari split.
     * Format: [PARENT_CODE]/[SUFFIX]
     * Contoh: TMH.TJU-WH.MON-260504-001/A
     *
     * @param  string $parentCode  Kode batch parent
     * @param  int    $splitIndex  Index split (0=A, 1=B, dst.)
     * @return string
     */
    public function generateSplitCode(string $parentCode, int $splitIndex): string
    {
        $suffix = chr(65 + $splitIndex); // A, B, C, D, ...
        return "{$parentCode}/{$suffix}";
    }
}