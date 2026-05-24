<?php

namespace Tests\Feature\Export;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\User;
use App\Services\Export\ExportValidationService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ExportValidationTest — test validasi ekspor (hard block MON).
 */
class ExportValidationTest extends TestCase
{
    use RefreshDatabase;

    protected ExportValidationService $service;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->service = app(ExportValidationService::class);
        $this->company = Company::where('code', 'TIMAH')->firstOrFail();
    }

    public function mon_batch_is_hard_blocked_from_export(): void
    {
        $monProduct = ProductCode::where('company_id', $this->company->id)
            ->where('code', 'MON')
            ->firstOrFail();

        $batch = Batch::factory()->create([
            'company_id'      => $this->company->id,
            'product_code_id' => $monProduct->id,
            'status'          => 'in_warehouse',
            'current_weight'  => 500,
        ]);

        $result = $this->service->validate([$batch->id], $this->company->id);

        $this->assertFalse($result['can_proceed']);
        $this->assertNotEmpty($result['hard_blocks']);
        $this->assertEquals('export_blocked_product', $result['hard_blocks'][0]['type']);
    }

    public function zir_batch_can_be_exported(): void
    {
        $zirProduct = ProductCode::where('company_id', $this->company->id)
            ->where('code', 'ZIR')
            ->firstOrFail();

        $batch = Batch::factory()->create([
            'company_id'      => $this->company->id,
            'product_code_id' => $zirProduct->id,
            'status'          => 'in_warehouse',
            'current_weight'  => 1000,
        ]);

        $result = $this->service->validate([$batch->id], $this->company->id);

        $this->assertTrue($result['can_proceed']);
        $this->assertEmpty($result['hard_blocks']);
    }

    public function zero_weight_batch_is_hard_blocked(): void
    {
        $zirProduct = ProductCode::where('company_id', $this->company->id)
            ->where('code', 'ZIR')
            ->firstOrFail();

        $batch = Batch::factory()->create([
            'company_id'      => $this->company->id,
            'product_code_id' => $zirProduct->id,
            'status'          => 'in_warehouse',
            'current_weight'  => 0,
        ]);

        $result = $this->service->validate([$batch->id], $this->company->id);

        $this->assertFalse($result['can_proceed']);
    }
}