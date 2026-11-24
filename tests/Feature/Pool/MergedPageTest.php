<?php

namespace Tests\Feature\Pool;

use App\Models\BatchPoolInput;
use App\Services\Process\StageTransitionService;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

class MergedPageTest extends PoolTestCase
{
    #[Test]
    public function wet_page_redirects_to_receiving(): void
    {
        $this->actingAs($this->admin)->get('/company/process/wet_process')
            ->assertRedirect('/company/process/receiving');
    }

    #[Test]
    public function receiving_page_has_intake_wet_pool_and_no_wet_nav(): void
    {
        $arrived = $this->makeBatch('lab_kimia', 'in_transit');
        app(StageTransitionService::class)->receive($arrived, 'receiving', ['user_id' => $this->admin->id]);
        $legacy = $this->makeBatch('receiving', 'in_transit');
        $pooled = $this->makeBatch('receiving', 'in_transit');
        app(StageTransitionService::class)->receive($pooled, 'wet_process', ['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->get('/company/process/receiving')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('intake.0.id', $arrived->id)
                ->where('legacy_to_wet.0.id', $legacy->id)
                ->where('pool.collecting.inputs.0.batch_id', $pooled->id)
                ->where('stage_config.label', 'Receiving & Wet')
                ->where('all_stages', fn ($s) => collect($s)->pluck('stage')->doesntContain('wet_process')));
    }

    #[Test]
    public function intake_endpoint_validates_and_joins_pool(): void
    {
        $b = $this->makeBatch('lab_kimia', 'in_transit', ['rfid_tag' => 'E200MP000001']);
        app(StageTransitionService::class)->receive($b, 'receiving', ['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->post("/company/process/receiving/intake/{$b->id}", ['weight' => 90])
            ->assertSessionHasErrors('sn_grade_pct');
        $this->actingAs($this->admin)->post("/company/process/receiving/intake/{$b->id}", ['weight' => 99.5, 'sn_grade_pct' => 1.75])
            ->assertSessionHas('success');

        $this->assertTrue(BatchPoolInput::where('batch_id', $b->id)->exists());
        $this->assertSame('wet_process', $b->fresh()->process_stage);
    }

    #[Test]
    public function legacy_in_transit_received_into_wet_via_web(): void
    {
        $legacy = $this->makeBatch('receiving', 'in_transit');

        $this->actingAs($this->admin)->post('/company/process/wet_process/receive-batch', ['batch_id' => $legacy->id])
            ->assertRedirect();

        $this->assertTrue(BatchPoolInput::where('batch_id', $legacy->id)->exists());
    }
}
