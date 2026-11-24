<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Services\Batch\LineageService;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class LineageTest extends PoolTestCase
{
    /** Dua batch Lab Kimia → drum Wet → drum Dry MON. @return array{0:Batch,1:Batch,2:Batch,3:Batch} */
    private function chain(): array
    {
        $t = app(StageTransitionService::class);
        $p = app(PoolService::class);
        $a = $this->makeBatch('receiving', 'in_transit', ['current_weight' => 70]);
        $b = $this->makeBatch('receiving', 'in_transit', ['current_weight' => 30]);
        $t->receive($a, 'wet_process', ['user_id' => $this->admin->id]);
        $t->receive($b, 'wet_process', ['user_id' => $this->admin->id]);

        $wet = $p->createOutputDrum($this->company->id, 'wet_process', 'E200LIN00W01', $this->admin->id)['batch'];
        $wet = $p->recordDrumWeight($wet, 90, null, $this->admin->id);
        $t->receive($wet, 'dry_process', ['user_id' => $this->admin->id]);

        $dry = $p->createOutputDrum($this->company->id, 'dry_process', 'E200LIN00D01', $this->admin->id)['batch'];
        $p->recordDrumWeight($dry, 40, $this->product('MON')->id, $this->admin->id);
        $p->closeDryPool($dry->pool, $this->admin->id);

        return [$a, $b, $wet->fresh(), $dry->fresh()];
    }

    #[Test]
    public function warehouse_drum_traces_back_to_all_origin_batches(): void
    {
        [$a, $b, $wet, $dry] = $this->chain();

        $anc = app(LineageService::class)->ancestors($dry);

        $this->assertSame([$wet->id], $anc->where('depth', 1)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $anc->where('depth', 2)->pluck('id')->all());
    }

    #[Test]
    public function origin_batch_shows_descendants(): void
    {
        [$a, , $wet, $dry] = $this->chain();

        $desc = app(LineageService::class)->descendants($a);

        $this->assertSame([$wet->id, $dry->id], $desc->pluck('id')->all());
    }

    #[Test]
    public function voided_lineage_is_not_followed(): void
    {
        $t = app(StageTransitionService::class);
        $p = app(PoolService::class);
        $a = $this->makeBatch('receiving', 'in_transit');
        $t->receive($a, 'wet_process', ['user_id' => $this->admin->id]);
        $drum = $p->createOutputDrum($this->company->id, 'wet_process', 'E200LIN00X01', $this->admin->id)['batch'];

        $p->cancelDrum($drum, $this->admin->id, 'salah scan');

        $this->assertTrue(app(LineageService::class)->ancestors(Batch::withTrashed()->find($drum->id))->isEmpty());
        $this->assertTrue(app(LineageService::class)->descendants($a)->isEmpty());
    }

    #[Test]
    public function legacy_parent_batch_id_is_followed(): void
    {
        $parent = $this->makeBatch('dry_process', 'transformed');
        $child  = $this->makeBatch('warehouse', 'in_warehouse', ['parent_batch_id' => $parent->id]);

        $this->assertSame([$parent->id], app(LineageService::class)->ancestors($child)->pluck('id')->all());
    }
}
