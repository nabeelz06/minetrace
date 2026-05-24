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
 * Formula: [ENT].[LOK]-[PROC].[PROD]-[YYMMDD]-[SEQ]
 * Contoh: TMH.SGT-LK.MIK-260504-001
 *
 * Menggunakan PostgreSQL native sequence per-tanggal untuk
 * memastikan tidak ada duplicate bahkan saat concurrent requests.
 */
class BatchCodeService
{
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
        $date     = now()->format('ymd');

        // ─── Atomic sequence menggunakan PostgreSQL SEQUENCE ───────────────
        // Setiap kombinasi company+date punya sequence sendiri
        $seqName = "batch_seq_{$company->id}_{$date}";

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