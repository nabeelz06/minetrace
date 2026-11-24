<?php

namespace Tests\Feature\Pool;

use App\Models\Location;
use App\Services\Batch\BatchService;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class ContainerTypeTest extends PoolTestCase
{
    #[Test]
    public function lab_batch_is_drum_besi(): void
    {
        $this->actingAs($this->admin);
        $loc = Location::where('company_id', $this->company->id)->where('location_type', 'lab_kimia')->firstOrFail();

        $b = app(BatchService::class)->createBatch([
            'company_id' => $this->company->id, 'location_id' => $loc->id,
            'product_code_id' => $this->product()->id, 'initial_weight' => 250,
        ]);

        $this->assertSame('drum_besi', $b->fresh()->container_type);
    }

    #[Test]
    public function wet_drum_is_container_and_dry_drum_is_product_drum(): void
    {
        $t = app(StageTransitionService::class);
        $p = app(PoolService::class);
        $a = $this->makeBatch('receiving', 'in_transit');
        $t->receive($a, 'wet_process', ['user_id' => $this->admin->id]);
        $wet = $p->createOutputDrum($this->company->id, 'wet_process', 'E200CT000001', $this->admin->id)['batch'];
        $this->assertSame('container', $wet->fresh()->container_type);

        $p->recordDrumWeight($wet, 80, null, $this->admin->id);
        $t->receive($wet->fresh(), 'dry_process', ['user_id' => $this->admin->id]);
        $dry = $p->createOutputDrum($this->company->id, 'dry_process', 'E200CT000002', $this->admin->id)['batch'];
        $this->assertSame('product_drum', $dry->fresh()->container_type);
    }

    #[Test]
    public function status_accepts_awaiting_intake(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['sn_grade_pct' => 12.345]);

        $this->assertSame('awaiting_intake', $b->fresh()->status);
        $this->assertEqualsWithDelta(12.345, $b->fresh()->sn_grade_pct, 0.0001);
    }
}
