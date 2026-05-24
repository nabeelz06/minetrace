<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Location;
use App\Models\ProductCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BatchFactory — factory untuk testing batch model.
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        $company = Company::where('is_regulator', false)->inRandomOrder()->first()
            ?? Company::factory()->create();

        $productCode = ProductCode::where('company_id', $company->id)->inRandomOrder()->first();
        $location    = Location::where('company_id', $company->id)->inRandomOrder()->first();
        $date        = now()->format('ymd');
        $seq         = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $batchCode   = "TMH.SGT-LK.MIK-{$date}-{$seq}";

        return [
            'company_id'          => $company->id,
            'batch_code'          => $batchCode . '-' . uniqid(),
            'product_code_id'     => $productCode?->id ?? 1,
            'initial_weight'      => $this->faker->randomFloat(2, 100, 5000),
            'current_weight'      => $this->faker->randomFloat(2, 50, 5000),
            'process_stage'       => $this->faker->randomElement(['lab_kimia','receiving','wet_process','dry_process','warehouse']),
            'status'              => $this->faker->randomElement(['active','in_warehouse','completed']),
            'current_location_id' => $location?->id,
            'has_discrepancy'     => false,
            'export_status'       => 'not_applicable',
            'metadata'            => [],
            'created_by'          => 1,
        ];
    }

    /** State: batch in warehouse, siap ekspor */
    public function inWarehouse(): static
    {
        return $this->state([
            'status'        => 'in_warehouse',
            'process_stage' => 'warehouse',
            'export_status' => 'available',
        ]);
    }

    /** State: batch sudah diekspor */
    public function exported(): static
    {
        return $this->state([
            'status'        => 'exported',
            'process_stage' => 'exported',
            'export_status' => 'exported',
        ]);
    }

    /** State: batch dengan discrepancy */
    public function withDiscrepancy(): static
    {
        return $this->state([
            'has_discrepancy' => true,
            'mass_loss_pct'   => $this->faker->randomFloat(2, 20, 50),
        ]);
    }
}