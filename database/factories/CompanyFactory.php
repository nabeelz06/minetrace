<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CompanyFactory — factory untuk testing company model.
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->lexify('???'));

        return [
            'code'          => $code,
            'name'          => 'PT ' . $this->faker->company(),
            'legal_name'    => 'PT ' . $this->faker->company() . ' Tbk',
            'industry'      => 'Mining - Tin & Rare Earth',
            'primary_color' => '#3E5C74',
            'schema_name'   => strtolower($code),
            'city'          => $this->faker->city(),
            'province'      => 'Bangka Belitung',
            'is_active'     => true,
            'is_regulator'  => false,
            'onboarded_at'  => now(),
            'settings'      => json_encode(['timezone' => 'Asia/Jakarta', 'default_locale' => 'id']),
        ];
    }

    public function regulator(): static
    {
        return $this->state([
            'is_regulator'  => true,
            'primary_color' => '#CC0000',
        ]);
    }
}