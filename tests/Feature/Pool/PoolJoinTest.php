<?php

namespace Tests\Feature\Pool;

use App\Models\BatchPool;
use App\Models\BatchPoolInput;
use App\Models\RfidTagEvent;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class PoolJoinTest extends PoolTestCase
{
    private function receive($batch, string $stage)
    {
        return app(StageTransitionService::class)->receive($batch, $stage, ['user_id' => $this->admin->id, 'source' => 'rfid']);
    }

    #[Test]
    public function receive_into_wet_releases_tag_and_joins_pool(): void
    {
        $b = $this->makeBatch('receiving', 'in_transit', ['rfid_tag' => 'E200POOL0001', 'current_weight' => 80.5]);

        $this->receive($b, 'wet_process');

        $b->refresh();
        $this->assertNull($b->rfid_tag);
        $this->assertSame('wet_process', $b->process_stage);
        $this->assertSame('in_process', $b->status);

        $input = BatchPoolInput::where('batch_id', $b->id)->firstOrFail();
        $this->assertSame('E200POOL0001', $input->released_rfid_tag);
        $this->assertEqualsWithDelta(80.5, $input->weight_at_join, 0.001);
        $this->assertSame(BatchPool::STATUS_COLLECTING, $input->pool->status);
        $this->assertSame('wet_process', $input->pool->stage);

        $this->assertTrue(RfidTagEvent::where('batch_id', $b->id)->where('event', 'released')->where('reason', RfidTagEvent::REASON_POOLED)->exists());
    }

    #[Test]
    public function untagged_batch_still_joins(): void
    {
        $b = $this->makeBatch('receiving', 'in_transit');

        $this->receive($b, 'wet_process');

        $input = BatchPoolInput::where('batch_id', $b->id)->firstOrFail();
        $this->assertNull($input->released_rfid_tag);
    }

    #[Test]
    public function second_batch_joins_same_pool(): void
    {
        $a = $this->makeBatch('receiving', 'in_transit', ['rfid_tag' => 'E200POOL0002']);
        $b = $this->makeBatch('receiving', 'in_transit', ['rfid_tag' => 'E200POOL0003']);

        $this->receive($a, 'wet_process');
        $this->receive($b, 'wet_process');

        $this->assertSame(1, BatchPool::where('company_id', $this->company->id)->where('stage', 'wet_process')->count());
        $this->assertSame(2, BatchPool::firstOrFail()->inputs()->count());
    }

    #[Test]
    public function receive_into_dry_from_legacy_in_transit_works(): void
    {
        $b = $this->makeBatch('wet_process', 'in_transit', ['rfid_tag' => 'E200LEGACY01']);

        $this->receive($b, 'dry_process');

        $b->refresh();
        $this->assertSame('dry_process', $b->process_stage);
        $this->assertNull($b->rfid_tag);
        $this->assertSame('dry_process', BatchPoolInput::where('batch_id', $b->id)->firstOrFail()->pool->stage);
    }

    #[Test]
    public function receive_into_warehouse_does_not_pool(): void
    {
        $b = $this->makeBatch('dry_process', 'in_transit', ['rfid_tag' => 'E200WH000001', 'product_code_id' => $this->product('ZIR')->id]);

        $this->receive($b, 'warehouse');

        $this->assertSame('E200WH000001', $b->fresh()->rfid_tag);
        $this->assertFalse(BatchPoolInput::where('batch_id', $b->id)->exists());
    }

    #[Test]
    public function pooled_member_cannot_be_dispatched_individually(): void
    {
        $b = $this->makeBatch('receiving', 'in_transit');
        $this->receive($b, 'wet_process');

        $error = app(StageTransitionService::class)->dispatchError($b->fresh(), 'wet_process');

        $this->assertStringContainsString('sedang dihimpun', $error);
    }
}
