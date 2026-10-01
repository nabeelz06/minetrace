<?php

namespace Tests\Feature\Batch;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Models\User;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BatchCreationTest — test pembuatan batch dan validasi business rules.
 */
class BatchCreationTest extends TestCase
{
    use RefreshDatabase;

    protected User    $operator;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->operator = User::where('email', 'lab.sgt@ptimah.co.id')->firstOrFail();
        $this->company  = Company::where('code', 'TIMAH')->firstOrFail();
    }

    #[Test]
    public function authenticated_operator_can_create_batch(): void
    {
        $productCode = ProductCode::where('company_id', $this->company->id)
            ->where('code', 'MIT')
            ->firstOrFail();

        $location = Location::where('company_id', $this->company->id)
            ->where('location_type', 'lab_kimia')
            ->firstOrFail();

        $response = $this->actingAs($this->operator)
            ->post('/company/batches', [
                'product_code_id' => $productCode->id,
                'location_id'     => $location->id,
                'initial_weight'  => 1000.500,
                'notes'           => 'Test batch',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch = Batch::where('company_id', $this->company->id)->latest()->first();

        $this->assertNotNull($batch);
        $this->assertEquals(1000.500, $batch->initial_weight);
        $this->assertEquals('lab_kimia', $batch->process_stage);
        $this->assertEquals('active', $batch->status);
        $this->assertMatchesRegularExpression('/^TMH\./', $batch->batch_code);
    }

    #[Test]
    public function batch_code_is_unique_and_sequential(): void
    {
        $productCode = ProductCode::where('company_id', $this->company->id)->where('code', 'MIT')->firstOrFail();
        $location    = Location::where('company_id', $this->company->id)->where('location_type', 'lab_kimia')->firstOrFail();

        $this->actingAs($this->operator)->post('/company/batches', [
            'product_code_id' => $productCode->id,
            'location_id'     => $location->id,
            'initial_weight'  => 500,
        ]);

        $this->actingAs($this->operator)->post('/company/batches', [
            'product_code_id' => $productCode->id,
            'location_id'     => $location->id,
            'initial_weight'  => 750,
        ]);

        $batches = Batch::where('company_id', $this->company->id)->latest()->limit(2)->get();

        $this->assertCount(2, $batches);
        $this->assertNotEquals($batches[0]->batch_code, $batches[1]->batch_code);
    }

    #[Test]
    public function batch_requires_valid_product_code(): void
    {
        $location = Location::where('company_id', $this->company->id)->where('location_type', 'lab_kimia')->firstOrFail();

        $response = $this->actingAs($this->operator)
            ->post('/company/batches', [
                'product_code_id' => 99999, // ID tidak ada
                'location_id'     => $location->id,
                'initial_weight'  => 1000,
            ]);

        $response->assertSessionHasErrors('product_code_id');
    }

    #[Test]
    public function batch_requires_positive_weight(): void
    {
        $productCode = ProductCode::where('company_id', $this->company->id)->where('code', 'MIT')->firstOrFail();
        $location    = Location::where('company_id', $this->company->id)->where('location_type', 'lab_kimia')->firstOrFail();

        $response = $this->actingAs($this->operator)
            ->post('/company/batches', [
                'product_code_id' => $productCode->id,
                'location_id'     => $location->id,
                'initial_weight'  => -100, // Negatif
            ]);

        $response->assertSessionHasErrors('initial_weight');
    }

    #[Test]
    public function unauthenticated_user_cannot_create_batch(): void
    {
        $response = $this->post('/company/batches', [
            'product_code_id' => 1,
            'location_id'     => 1,
            'initial_weight'  => 1000,
        ]);

        $response->assertRedirect('/login');
    }

    #[Test]
    public function batch_from_different_company_is_unauthorized(): void
    {
        // Buat company dan batch lain
        $otherCompany = Company::factory()->create(['is_regulator' => false]);
        $otherBatch   = Batch::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->actingAs($this->operator)
            ->get("/company/batches/{$otherBatch->id}");

        $response->assertStatus(403);
    }
}