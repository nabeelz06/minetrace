<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Models\BatchPool;
use App\Models\User;
use App\Services\Process\StageTransitionService;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

class PoolWebTest extends PoolTestCase
{
    private function pooled(string $stage = 'wet_process'): Batch
    {
        $b = $this->makeBatch($stage === 'wet_process' ? 'receiving' : 'wet_process', 'in_transit', ['current_weight' => 50]);
        app(StageTransitionService::class)->receive($b, $stage, ['user_id' => $this->admin->id]);

        return $b->fresh();
    }

    #[Test]
    public function stage_page_has_pool_prop_and_hides_pooled_batches(): void
    {
        $in = $this->pooled();

        // Halaman Wet digabung ke Receiving & Wet: panel himpunan Wet tampil di halaman receiving.
        $this->actingAs($this->admin)->get('/company/process/receiving')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('pool.collecting.inputs.0.batch_id', $in->id)
                ->where('pool.collecting.total_weight', 50));

        $this->actingAs($this->admin)->post('/company/process/wet_process/pool/drum', ['uid' => 'E200WEB00001'])->assertRedirect();

        $this->actingAs($this->admin)->get('/company/process/receiving')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('pool.collecting', null)
                ->where('pool.drums.0.rfid_tag', 'E200WEB00001')
                ->where('intake', fn ($rows) => collect($rows)->pluck('id')->doesntContain($in->id)));
    }

    #[Test]
    public function full_web_flow_drum_weigh_close(): void
    {
        $this->pooled('dry_process');
        $this->actingAs($this->admin)->post('/company/process/dry_process/pool/drum', ['uid' => 'E200WEB00002'])
            ->assertSessionHas('success');
        $drum = Batch::where('rfid_tag', 'E200WEB00002')->firstOrFail();

        $this->actingAs($this->admin)->post("/company/process/pool/drums/{$drum->id}/weight", [
            'weight' => 45, 'product_code_id' => $this->product('ZIR')->id,
        ])->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/company/process/pool/{$drum->pool_id}/close")->assertSessionHas('success');

        $this->assertSame('in_transit', $drum->fresh()->status);
        $this->assertSame(BatchPool::STATUS_CLOSED, $drum->pool->fresh()->status);
    }

    #[Test]
    public function errors_come_back_as_flash(): void
    {
        $this->actingAs($this->admin)->post('/company/process/wet_process/pool/drum', ['uid' => 'E200WEB00003'])
            ->assertSessionHas('error');
    }

    #[Test]
    public function cancel_drum_requires_reason(): void
    {
        $this->pooled();
        $this->actingAs($this->admin)->post('/company/process/wet_process/pool/drum', ['uid' => 'E200WEB00004']);
        $drum = Batch::where('rfid_tag', 'E200WEB00004')->firstOrFail();

        $this->actingAs($this->admin)->post("/company/process/pool/drums/{$drum->id}/cancel", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/company/process/pool/drums/{$drum->id}/cancel", ['reason' => 'tag nyasar'])
            ->assertSessionHas('success');
        $this->assertSoftDeleted($drum);
    }

    #[Test]
    public function wrong_role_is_forbidden(): void
    {
        $this->pooled();
        $warehouseOp = User::role('operator_warehouse')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($warehouseOp, 'seeder menyediakan operator_warehouse');

        $this->actingAs($warehouseOp)->post('/company/process/wet_process/pool/drum', ['uid' => 'E200WEB00005'])
            ->assertForbidden();
    }
}
