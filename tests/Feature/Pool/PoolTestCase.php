<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\User;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class PoolTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');

        $this->company = Company::where('code', 'TIMAH')->firstOrFail();
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
    }

    protected function product(string $code = 'MIT'): ProductCode
    {
        return ProductCode::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    protected function makeBatch(string $stage, string $status, array $attrs = []): Batch
    {
        $this->seq++;
        $weight = $attrs['current_weight'] ?? 100.0;

        return Batch::create($attrs + [
            'company_id'      => $this->company->id,
            'batch_code'      => sprintf('T-%04d-%s', $this->seq, substr(md5(uniqid('', true)), 0, 6)),
            'product_code_id' => $this->product()->id,
            'initial_weight'  => $weight,
            'current_weight'  => $weight,
            'process_stage'   => $stage,
            'status'          => $status,
            'created_by'      => $this->admin->id,
        ]);
    }
}
