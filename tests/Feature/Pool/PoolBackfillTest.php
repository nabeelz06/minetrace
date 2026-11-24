<?php

namespace Tests\Feature\Pool;

use App\Models\BatchPoolInput;
use PHPUnit\Framework\Attributes\Test;

class PoolBackfillTest extends PoolTestCase
{
    #[Test]
    public function only_in_process_wet_and_dry_batches_are_pooled(): void
    {
        $wet     = $this->makeBatch('wet_process', 'in_process', ['rfid_tag' => 'E200BACK0001']);
        $dry     = $this->makeBatch('dry_process', 'in_process');
        $transit = $this->makeBatch('wet_process', 'in_transit', ['rfid_tag' => 'E200BACK0002']);
        $recv    = $this->makeBatch('receiving', 'in_process', ['rfid_tag' => 'E200BACK0003']);

        $this->artisan('batches:pool-backfill')->assertSuccessful();

        $this->assertTrue(BatchPoolInput::where('batch_id', $wet->id)->exists());
        $this->assertNull($wet->fresh()->rfid_tag);
        $this->assertTrue(BatchPoolInput::where('batch_id', $dry->id)->exists());
        $this->assertFalse(BatchPoolInput::where('batch_id', $transit->id)->exists());
        $this->assertSame('E200BACK0002', $transit->fresh()->rfid_tag);
        $this->assertFalse(BatchPoolInput::where('batch_id', $recv->id)->exists());
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $wet = $this->makeBatch('wet_process', 'in_process', ['rfid_tag' => 'E200BACK0010']);

        $this->artisan('batches:pool-backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse(BatchPoolInput::where('batch_id', $wet->id)->exists());
        $this->assertSame('E200BACK0010', $wet->fresh()->rfid_tag);
    }

    #[Test]
    public function running_twice_is_harmless(): void
    {
        $this->makeBatch('wet_process', 'in_process');

        $this->artisan('batches:pool-backfill')->assertSuccessful();
        $this->artisan('batches:pool-backfill')->assertSuccessful();

        $this->assertSame(1, BatchPoolInput::count());
    }
}
