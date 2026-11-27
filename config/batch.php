<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Format tanggal pada kode batch
    |--------------------------------------------------------------------------
    | 'dmy' = DDMMYY (bawaan)  → TMH.SGT-LK.MIK-270526-001
    | 'dmY' = DDMMYYYY         → TMH.SGT-LK.MIK-27052026-001
    | Kode batch lama (YYMMDD) tidak diubah.
    */
    'code_date_format' => env('BATCH_CODE_DATE_FORMAT', 'dmy'),

    /*
    |--------------------------------------------------------------------------
    | Saran lokasi asal kiriman (mitra PT Timah)
    |--------------------------------------------------------------------------
    | Hanya saran pada kolom "Lokasi asal"; operator tetap boleh mengetik lokasi lain.
    */
    'origin_locations' => [
        'Sungailiat', 'Tempilang', 'Tubuali', 'Mentok', 'Jebus', 'Penganak', 'Belinyu',
    ],

];
