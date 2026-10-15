<?php

use App\Jobs\Reports\GenerateMonthlyReportJob;
use App\Models\Company;
use Illuminate\Support\Facades\Schedule;

/*
|─────────────────────────────────────────────────────────────────────────────
| MINETRACE — Scheduled Tasks
|─────────────────────────────────────────────────────────────────────────────
|
| Scheduler berjalan di container `scheduler` (docker-compose.prod.yml: `php artisan schedule:work`).
| Setiap task dijalankan dalam timezone Asia/Jakarta.
|
*/

// ─── Monthly Report: Tanggal 1 setiap bulan jam 07:00 WIB ─────────────────
// Saat company baru ditambah, laporan otomatis tercakup tanpa perubahan kode
Schedule::call(function () {

    $reportMonth = now()->subMonth()->format('Y-m');

    // Ambil semua company operasional yang aktif
    $companies = Company::where('is_active', true)
        ->where('is_regulator', false)
        ->get();

    foreach ($companies as $company) {
        // Dispatch ke queue — setiap company diproses independent
        GenerateMonthlyReportJob::dispatch($company->id, $reportMonth)
            ->delay(now()->addSeconds($companies->search($company) * 30));
            // 30 detik delay antar company agar tidak overload PDF generator
    }

    logger()->info("Monthly reports dispatched for {$companies->count()} companies. Month: {$reportMonth}");

})->monthlyOn(1, '07:00')->name('monthly-reports')->timezone('Asia/Jakarta');

// ─── Daily cleanup: Hapus cache analytics yang expired ────────────────────
Schedule::command('cache:prune-stale-tags')
    ->daily()
    ->name('cache-cleanup');

// ─── Weekly: Cek kapasitas warehouse, kirim alert jika mendekati penuh ────
Schedule::call(function () {

    $companies = Company::where('is_active', true)->where('is_regulator', false)->get();

    foreach ($companies as $company) {
        $service    = app(\App\Services\Analytics\CompanyAnalyticsService::class);
        $capacities = $service->getWarehouseCapacity($company->id);

        foreach ($capacities as $wh) {
            if ($wh['is_critical']) {
                logger()->warning("CRITICAL warehouse capacity: {$company->name} - {$wh['name']}: {$wh['capacity_pct']}%");
            }
        }
    }

})->weekly()->name('warehouse-capacity-check');