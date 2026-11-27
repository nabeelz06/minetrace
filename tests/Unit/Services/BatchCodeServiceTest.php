<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Services\Batch\BatchCodeService;
use Carbon\Carbon;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BatchCodeServiceTest — generator kode batch: [ENT].[LOK]-[PROC].[PROD]-[DDMMYY]-[SEQ] (tanggal WIB, urut hari-bulan-tahun).
 */
class BatchCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BatchCodeService $service;
    protected Company $company;
    protected Location $location;
    protected ProductCode $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->service  = app(BatchCodeService::class);
        $this->company  = Company::where('code', 'TIMAH')->firstOrFail();
        $this->location = Location::where('company_id', $this->company->id)->where('code', 'SGT')->firstOrFail();
        $this->product  = ProductCode::where('company_id', $this->company->id)->where('code', 'MIT')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function generates_valid_batch_code_format(): void
    {
        $code = $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product);

        $this->assertMatchesRegularExpression('/^[A-Z]{3}\.[A-Z]+-[A-Z]+\.[A-Z]+-\d{6}-\d{3}$/', $code);
        $this->assertStringStartsWith('TMH.SGT', $code);
    }

    #[Test]
    public function date_part_is_day_month_year_in_wib(): void
    {
        // 4 Mei 2026 17:30 UTC = 5 Mei 2026 00:30 WIB → kalender operator sudah tanggal 5.
        Carbon::setTestNow(Carbon::parse('2026-05-04 17:30:00', 'UTC'));

        $code = $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product);

        $this->assertSame('TMH.SGT-LK.MIT-050526-001', $code);
    }

    #[Test]
    public function sequence_counts_up_within_a_wib_day_and_restarts_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 03:00:00', 'UTC'));
        $a = $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product);
        $b = $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product);

        Carbon::setTestNow(Carbon::parse('2026-05-05 03:00:00', 'UTC'));
        $c = $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product);

        $this->assertSame(['TMH.SGT-LK.MIT-040526-001', 'TMH.SGT-LK.MIT-040526-002', 'TMH.SGT-LK.MIT-050526-001'], [$a, $b, $c]);
    }

    #[Test]
    public function four_digit_year_can_be_selected_by_config(): void
    {
        config(['batch.code_date_format' => 'dmY']);
        Carbon::setTestNow(Carbon::parse('2026-11-27 03:00:00', 'UTC'));

        $this->assertSame('TMH.SGT-LK.MIT-27112026-001', $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product));
    }

    #[Test]
    public function unknown_date_format_falls_back_to_default(): void
    {
        config(['batch.code_date_format' => 'Y-m-d']);

        $this->assertSame('dmy', BatchCodeService::dateFormat());
    }

    #[Test]
    public function generates_unique_codes_for_concurrent_batches(): void
    {
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->service->generate($this->company, $this->location, 'lab_kimia', $this->product);
        }

        $this->assertCount(5, array_unique($codes));
    }

    #[Test]
    public function generates_correct_split_code(): void
    {
        $parentCode = 'TMH.SGT-LK.MIT-040526-001';

        $this->assertEquals("{$parentCode}/A", $this->service->generateSplitCode($parentCode, 0));
        $this->assertEquals("{$parentCode}/B", $this->service->generateSplitCode($parentCode, 1));
    }
}
