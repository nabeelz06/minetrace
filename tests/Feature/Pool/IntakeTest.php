<?php

namespace Tests\Feature\Pool;

use App\Models\BatchPoolInput;
use App\Models\Checkpoint;
use App\Models\MassBalanceThreshold;
use App\Services\Process\IntakeService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class IntakeTest extends PoolTestCase
{
    private function arrived(float $labWeight = 100, ?string $tag = null): \App\Models\Batch
    {
        $b = $this->makeBatch('lab_kimia', 'in_transit', ['current_weight' => $labWeight, 'rfid_tag' => $tag, 'container_type' => 'drum_besi']);
        app(StageTransitionService::class)->receive($b, 'receiving', ['user_id' => $this->admin->id]);

        return $b->fresh();
    }

    #[Test]
    public function receive_at_receiving_sets_awaiting_intake(): void
    {
        $b = $this->arrived(100, 'E200IN000001');

        $this->assertSame(['receiving', 'awaiting_intake', 'E200IN000001'], [$b->process_stage, $b->status, $b->rfid_tag]);
    }

    #[Test]
    public function intake_records_weight_sn_and_joins_wet_pool(): void
    {
        $b = $this->arrived(100, 'E200IN000002');

        $b = app(IntakeService::class)->record($b, 98.5, 1.25, $this->admin->id);

        $this->assertSame(['wet_process', 'in_process', null], [$b->process_stage, $b->status, $b->rfid_tag]);
        $this->assertEqualsWithDelta(98.5, $b->current_weight, 0.001);
        $this->assertEqualsWithDelta(1.25, $b->sn_grade_pct, 0.0001);
        $this->assertEqualsWithDelta(98.5, BatchPoolInput::where('batch_id', $b->id)->value('weight_at_join'), 0.001);
        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->get()
            ->first(fn ($c) => ($c->checkpoint_data['action'] ?? null) === 'intake');
        $this->assertNotNull($cp);
        $this->assertEqualsWithDelta(1.25, $cp->checkpoint_data['sn_grade_pct'], 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $cp->weight_loss_pct, 0.001);
    }

    #[Test]
    public function intake_over_threshold_still_joins_and_flags(): void
    {
        MassBalanceThreshold::updateOrCreate(
            ['company_id' => $this->company->id, 'process_stage' => 'receiving'],
            ['stage_label' => 'Receiving', 'warning_threshold_pct' => 5, 'critical_threshold_pct' => 10]
        );
        $b = $this->arrived(100);

        $b = app(IntakeService::class)->record($b, 80, 2.0, $this->admin->id);

        $this->assertSame('wet_process', $b->process_stage);
        $this->assertTrue($b->has_discrepancy);
    }

    #[Test]
    public function intake_rejects_sn_out_of_range(): void
    {
        $b = $this->arrived();

        $this->expectException(\DomainException::class);
        app(IntakeService::class)->record($b, 90, 120, $this->admin->id);
    }

    #[Test]
    public function legacy_receiving_in_process_can_intake(): void
    {
        $b = $this->makeBatch('receiving', 'in_process');

        $b = app(IntakeService::class)->record($b, 95, 3.1, $this->admin->id);

        $this->assertSame('wet_process', $b->process_stage);
    }

    #[Test]
    public function awaiting_intake_cannot_be_dispatched_or_received_elsewhere(): void
    {
        $b = $this->arrived();
        $t = app(StageTransitionService::class);

        $this->assertNotNull($t->dispatchError($b, 'receiving'));
        $this->assertNotNull($t->receiveError($b, 'wet_process'));
    }
}
