<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Models\BatchLineage;
use App\Models\BatchPool;
use App\Models\BatchTransformation;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class PoolWeighTest extends PoolTestCase
{
    private function pools(): PoolService
    {
        return app(PoolService::class);
    }

    private function fillPool(string $stage, array $weights): array
    {
        $from = $stage === 'wet_process' ? 'receiving' : 'wet_process';

        return array_map(function ($w) use ($from, $stage) {
            $b = $this->makeBatch($from, 'in_transit', ['current_weight' => $w]);
            app(StageTransitionService::class)->receive($b, $stage, ['user_id' => $this->admin->id]);

            return $b->fresh();
        }, $weights);
    }

    private function drum(string $stage, string $uid): Batch
    {
        $r = $this->pools()->createOutputDrum($this->company->id, $stage, $uid, $this->admin->id);
        $this->assertTrue($r['ok'], $r['message']);

        return $r['batch'];
    }

    #[Test]
    public function wet_weight_finalizes_and_computes_loss(): void
    {
        $this->fillPool('wet_process', [100, 100]);
        $drum = $this->drum('wet_process', 'E200WEIGH001');

        $drum = $this->pools()->recordDrumWeight($drum, 150.0, null, $this->admin->id);

        $this->assertSame('in_transit', $drum->status);
        $this->assertEqualsWithDelta(150.0, $drum->initial_weight, 0.001);
        $this->assertEqualsWithDelta(150.0, $drum->current_weight, 0.001);
        $pool = $drum->pool->fresh();
        $this->assertEqualsWithDelta(200.0, $pool->total_input_weight, 0.001);
        $this->assertEqualsWithDelta(150.0, $pool->total_output_weight, 0.001);
        $this->assertEqualsWithDelta(25.0, $pool->loss_pct, 0.001);
        $this->assertNull(app(StageTransitionService::class)->receiveError($drum, 'dry_process'));
    }

    #[Test]
    public function awaiting_weight_cannot_be_received(): void
    {
        $this->fillPool('wet_process', [100]);
        $drum = $this->drum('wet_process', 'E200WEIGH002');

        $error = app(StageTransitionService::class)->receiveError($drum->fresh(), 'dry_process');

        $this->assertSame('Drum belum ditimbang.', $error);
        $this->assertSame('Drum belum ditimbang.', app(StageTransitionService::class)->dispatchError($drum->fresh(), 'wet_process'));
    }

    #[Test]
    public function dry_close_requires_all_drums_weighed(): void
    {
        $this->fillPool('dry_process', [100]);
        $a = $this->drum('dry_process', 'E200DRYW0001');
        $this->drum('dry_process', 'E200DRYW0002');
        $this->pools()->recordDrumWeight($a, 30.0, $this->product('MON')->id, $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('belum ditimbang');
        $this->pools()->closeDryPool($a->pool->fresh(), $this->admin->id);
    }

    #[Test]
    public function dry_drum_requires_product(): void
    {
        $this->fillPool('dry_process', [100]);
        $a = $this->drum('dry_process', 'E200DRYW0010');

        $this->expectException(\DomainException::class);
        $this->pools()->recordDrumWeight($a, 30.0, null, $this->admin->id);
    }

    #[Test]
    public function dry_duplicate_product_rejected(): void
    {
        $this->fillPool('dry_process', [100]);
        $a = $this->drum('dry_process', 'E200DRYW0003');
        $b = $this->drum('dry_process', 'E200DRYW0004');
        $this->pools()->recordDrumWeight($a, 30.0, $this->product('MON')->id, $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('MON');
        $this->pools()->recordDrumWeight($b, 20.0, $this->product('MON')->id, $this->admin->id);
    }

    #[Test]
    public function dry_close_creates_proportional_lineage(): void
    {
        [$in1, $in2] = $this->fillPool('dry_process', [60, 40]);
        $mon = $this->drum('dry_process', 'E200DRYW0005');
        $zir = $this->drum('dry_process', 'E200DRYW0006');
        $this->pools()->recordDrumWeight($mon, 30.0, $this->product('MON')->id, $this->admin->id);
        $this->pools()->recordDrumWeight($zir, 50.0, $this->product('ZIR')->id, $this->admin->id);

        $pool = $this->pools()->closeDryPool($mon->pool->fresh(), $this->admin->id);

        $this->assertSame(BatchPool::STATUS_CLOSED, $pool->status);
        $this->assertEqualsWithDelta(20.0, $pool->loss_pct, 0.001);
        $this->assertSame('in_transit', $mon->fresh()->status);
        $this->assertSame('MON', $mon->fresh()->productCode->code);
        $this->assertSame('pooled', $in1->fresh()->status);

        // in1 (60) → MON (30 dari 80 output): 60 × 30/80 = 22.5
        $edge = BatchLineage::where('parent_batch_id', $in1->id)->where('child_batch_id', $mon->id)->firstOrFail();
        $this->assertEqualsWithDelta(22.5, $edge->contributed_weight, 0.001);
        $this->assertTrue($edge->weight_is_estimate);
        $this->assertSame(4, BatchLineage::where('pool_id', $pool->id)->count());
        $this->assertNull(app(StageTransitionService::class)->receiveError($zir->fresh(), 'warehouse'));
    }

    #[Test]
    public function cancel_wet_drum_reopens_pool(): void
    {
        [$in] = $this->fillPool('wet_process', [100]);
        $drum = $this->drum('wet_process', 'E200CANCEL01');

        $this->pools()->cancelDrum($drum, $this->admin->id, 'tag nyasar');

        $this->assertSoftDeleted($drum);
        $this->assertNull(Batch::withTrashed()->find($drum->id)->rfid_tag);
        $this->assertSame('in_process', $in->fresh()->status);
        $this->assertSame(BatchPool::STATUS_COLLECTING, $in->poolInput->pool->fresh()->status);
        $this->assertFalse(BatchLineage::where('child_batch_id', $drum->id)->whereNull('voided_at')->exists());
        $this->assertNotNull(BatchTransformation::where('transformation_type', 'pooling')->latest('id')->first()->voided_at);

        // UID drum yang dibatalkan boleh dipakai lagi sebagai drum (bukan stiker karung lama)
        $again = $this->pools()->createOutputDrum($this->company->id, 'wet_process', 'E200CANCEL01', $this->admin->id);
        $this->assertTrue($again['ok'], $again['message']);
    }

    #[Test]
    public function cancel_last_dry_drum_returns_pool_to_collecting(): void
    {
        $this->fillPool('dry_process', [100]);
        $drum = $this->drum('dry_process', 'E200CANCEL02');

        $this->pools()->cancelDrum($drum, $this->admin->id, 'salah');

        $this->assertSame(BatchPool::STATUS_COLLECTING, $drum->pool->fresh()->status);
    }

    #[Test]
    public function cancel_after_weight_rejected(): void
    {
        $this->fillPool('wet_process', [100]);
        $drum = $this->drum('wet_process', 'E200CANCEL03');
        $drum = $this->pools()->recordDrumWeight($drum, 90.0, null, $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->pools()->cancelDrum($drum, $this->admin->id, 'terlambat');
    }
}
