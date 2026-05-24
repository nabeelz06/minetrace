<?php

namespace Tests\Unit\Services;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Services\Batch\BatchCodeService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BatchCodeServiceTest — unit test untuk generator kode batch.
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
        $this->product  = ProductCode::where('company_id', $this->company->id)->where('code', 'MIK')->firstOrFail();
    }

    public function generates_valid_batch_code_format(): void
    {
        $code = $this->service->generate(
            $this->company,
            $this->location,
            'lab_kimia',
            $this->product
        );

        // Format: TMH.SGT-LK.MIK-YYMMDD-001
        $this->assertMatchesRegularExpression(
            '/^[A-Z]{3}\.[A-Z]+-[A-Z]+\.[A-Z]+-\d{6}-\d{3}$/',
            $code
        );
        $this->assertStringStartsWith('TMH.SGT', $code);
    }

    public function generates_unique_codes_for_concurrent_batches(): void
    {
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->service->generate(
                $this->company,
                $this->location,
                'lab_kimia',
                $this->product
            );
        }

        // Semua kode harus unik
        $this->assertCount(5, array_unique($codes));
    }

    public function generates_correct_split_code(): void
    {
        $parentCode = 'TMH.SGT-LK.MIK-260504-001';

        $splitA = $this->service->generateSplitCode($parentCode, 0);
        $splitB = $this->service->generateSplitCode($parentCode, 1);

        $this->assertEquals("{$parentCode}/A", $splitA);
        $this->assertEquals("{$parentCode}/B", $splitB);
    }
}