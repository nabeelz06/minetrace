<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Models\BatchLineage;
use App\Models\BatchPool;
use App\Models\BatchTransformation;
use App\Models\RfidTagEvent;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class PoolOutputTest extends PoolTestCase
{
    private function pools(): PoolService
    {
        return app(PoolService::class);
    }

    /** @return list<Batch> */
    private function fillPool(string $stage, int $n, float $weight = 100.0): array
    {
        $from = $stage === 'wet_process' ? 'receiving' : 'wet_process';
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $b = $this->makeBatch($from, 'in_transit', ['current_weight' => $weight]);
            app(StageTransitionService::class)->receive($b, $stage, ['user_id' => $this->admin->id]);
            $out[] = $b->fresh();
        }

        return $out;
    }

    private function drum(string $stage, string $uid): array
    {
        return $this->pools()->createOutputDrum($this->company->id, $stage, $uid, $this->admin->id);
    }

    #[Test]
    public function wet_drum_takes_whole_pool(): void
    {
        $inputs = $this->fillPool('wet_process', 3);

        $r = $this->drum('wet_process', 'E200DRUMW001');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('pool_output_created', $r['code']);
        $drum = $r['batch']->fresh();
        $this->assertSame('E200DRUMW001', $drum->rfid_tag);
        $this->assertSame('awaiting_weight', $drum->status);
        $this->assertSame('wet_process', $drum->process_stage);
        $this->assertSame(BatchPool::STATUS_CLOSED, $r['pool']->fresh()->status);

        foreach ($inputs as $in) {
            $this->assertSame('pooled', $in->fresh()->status);
            $this->assertTrue(BatchLineage::where('parent_batch_id', $in->id)->where('child_batch_id', $drum->id)->exists());
        }
        $this->assertTrue(BatchTransformation::where('transformation_type', 'pooling')->where('input_batch_id', $inputs[0]->id)->exists());
    }

    #[Test]
    public function empty_pool_rejected(): void
    {
        $r = $this->drum('wet_process', 'E200DRUMW002');

        $this->assertFalse($r['ok']);
        $this->assertSame('pool_empty', $r['code']);
        $this->assertFalse(Batch::where('rfid_tag', 'E200DRUMW002')->exists());
    }

    #[Test]
    public function recycled_uid_rejected(): void
    {
        $this->fillPool('wet_process', 1);
        RfidTagEvent::create([
            'company_id' => $this->company->id, 'batch_id' => null, 'uid' => 'E200OLDTAG01',
            'event' => 'released', 'reason' => 'pooled', 'performed_by' => $this->admin->id,
        ]);

        $r = $this->drum('wet_process', 'E200OLDTAG01');

        $this->assertFalse($r['ok']);
        $this->assertSame('tag_recycled', $r['code']);
    }

    #[Test]
    public function released_input_tag_rejected_as_drum(): void
    {
        $b = $this->makeBatch('receiving', 'in_transit', ['rfid_tag' => 'E200SACK0001']);
        app(StageTransitionService::class)->receive($b, 'wet_process', ['user_id' => $this->admin->id]);

        $r = $this->drum('wet_process', 'E200SACK0001');

        $this->assertSame('tag_recycled', $r['code']);
        $this->assertSame('in_process', $b->fresh()->status);
    }

    #[Test]
    public function uid_in_use_rejected(): void
    {
        $this->fillPool('wet_process', 1);
        $this->makeBatch('receiving', 'in_process', ['rfid_tag' => 'E200INUSE001']);

        $r = $this->drum('wet_process', 'E200INUSE001');

        $this->assertSame('tag_in_use', $r['code']);
    }

    #[Test]
    public function dry_drum_moves_pool_to_outputting_and_new_arrivals_go_to_new_pool(): void
    {
        $this->fillPool('dry_process', 2);

        $r = $this->drum('dry_process', 'E200DRUMD001');
        $this->assertTrue($r['ok'], $r['message']);
        $pool = $r['pool']->fresh();
        $this->assertSame(BatchPool::STATUS_OUTPUTTING, $pool->status);

        [$late] = $this->fillPool('dry_process', 1);
        $this->assertNotSame($pool->id, $late->poolInput->pool_id);
        $this->assertSame(BatchPool::STATUS_COLLECTING, $late->poolInput->pool->status);

        $r2 = $this->drum('dry_process', 'E200DRUMD002');
        $this->assertSame($pool->id, $r2['pool']->id);
    }

    #[Test]
    public function fourth_dry_drum_rejected(): void
    {
        $this->fillPool('dry_process', 1);
        foreach (['E200DRUMD011', 'E200DRUMD012', 'E200DRUMD013'] as $uid) {
            $this->assertTrue($this->drum('dry_process', $uid)['ok']);
        }

        $r = $this->drum('dry_process', 'E200DRUMD014');

        $this->assertSame('pool_full', $r['code']);
    }

    #[Test]
    public function second_wet_drum_right_after_finds_empty_pool(): void
    {
        $this->fillPool('wet_process', 2);

        $first  = $this->drum('wet_process', 'E200DRUMW021');
        $second = $this->drum('wet_process', 'E200DRUMW022');

        $this->assertTrue($first['ok']);
        $this->assertSame('pool_empty', $second['code']);
        $this->assertSame(1, Batch::where('pool_id', $first['pool']->id)->count());
    }
}
