<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Models\Company;
use App\Models\RfidTagEvent;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

/** Temuan review akhir cabang pooling-retag. */
class PoolReviewFixTest extends PoolTestCase
{
    private function pooled(string $stage): Batch
    {
        $b = $this->makeBatch($stage === 'wet_process' ? 'receiving' : 'wet_process', 'in_transit', ['current_weight' => 60]);
        app(StageTransitionService::class)->receive($b, $stage, ['user_id' => $this->admin->id]);

        return $b->fresh();
    }

    #[Test]
    public function open_pool_member_cannot_be_received_at_next_stage(): void
    {
        $dryInput = $this->pooled('dry_process');
        $wetInput = $this->pooled('wet_process');
        $t = app(StageTransitionService::class);

        $this->assertStringContainsString('sedang dihimpun', (string) $t->receiveError($dryInput, 'warehouse'));
        $this->assertStringContainsString('sedang dihimpun', (string) $t->receiveError($wetInput, 'dry_process'));
    }

    #[Test]
    public function open_pool_member_not_listed_as_incoming(): void
    {
        $dryInput = $this->pooled('dry_process');

        $this->actingAs($this->admin)->get('/company/process/warehouse')
            ->assertInertia(fn ($p) => $p->where('incoming_batches', fn ($rows) => collect($rows)->pluck('id')->doesntContain($dryInput->id)));
    }

    #[Test]
    public function weighed_dry_drum_cannot_be_cancelled(): void
    {
        $this->pooled('dry_process');
        $p = app(PoolService::class);
        $drum = $p->createOutputDrum($this->company->id, 'dry_process', 'E200FIX00001', $this->admin->id)['batch'];
        $p->recordDrumWeight($drum, 20, $this->product('MON')->id, $this->admin->id);

        $this->expectException(\DomainException::class);
        $p->cancelDrum($drum->fresh(), $this->admin->id, 'coba');
    }

    #[Test]
    public function dry_transform_rejected_for_open_pool_member(): void
    {
        $input = $this->pooled('dry_process');

        $this->actingAs($this->admin)->post('/company/process/dry/transform', [
            'batch_id' => $input->id,
            'outputs'  => [['product_code_id' => $this->product('MON')->id, 'weight_kg' => 10]],
        ])->assertSessionHas('error');

        $this->assertSame('in_process', $input->fresh()->status);
    }

    #[Test]
    public function checkpoint_rejected_for_open_pool_member(): void
    {
        $input = $this->pooled('wet_process');

        $this->actingAs($this->admin)->post('/company/process/wet_process/checkpoint', [
            'batch_id' => $input->id, 'weight_recorded' => 10,
        ])->assertSessionHas('error');

        $this->assertEqualsWithDelta(60, $input->fresh()->current_weight, 0.001);
    }

    #[Test]
    public function released_drum_tag_counts_as_history(): void
    {
        $this->pooled('dry_process');
        $p = app(PoolService::class);
        $drum = $p->createOutputDrum($this->company->id, 'dry_process', 'E200FIX00002', $this->admin->id)['batch'];
        $drum->update(['rfid_tag' => null]);
        RfidTagEvent::create([
            'company_id' => $this->company->id, 'batch_id' => $drum->id, 'uid' => 'E200FIX00002',
            'event' => 'released', 'reason' => 'replaced', 'performed_by' => $this->admin->id,
        ]);

        $this->assertTrue($p->uidHasHistory($this->company->id, 'E200FIX00002'));
    }

    #[Test]
    public function tag_in_use_by_other_company_does_not_leak_batch_code(): void
    {
        $this->pooled('wet_process');
        $other = Company::where('id', '!=', $this->company->id)->firstOrFail();
        $foreign = Batch::create([
            'company_id' => $other->id, 'batch_code' => 'FOREIGN-SECRET-001', 'rfid_tag' => 'E200FIX00003',
            'product_code_id' => $this->product()->id, 'initial_weight' => 1, 'current_weight' => 1,
            'process_stage' => 'receiving', 'status' => 'in_process', 'created_by' => $this->admin->id,
        ]);

        $r = app(PoolService::class)->createOutputDrum($this->company->id, 'wet_process', 'E200FIX00003', $this->admin->id);

        $this->assertSame('tag_in_use', $r['code']);
        $this->assertStringNotContainsString($foreign->batch_code, $r['message']);
    }
}
