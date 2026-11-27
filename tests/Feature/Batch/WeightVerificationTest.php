<?php

namespace Tests\Feature\Batch;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\MassBalanceThreshold;
use App\Models\User;
use App\Services\Process\IntakeService;
use App\Services\Process\WeightVerificationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pool\PoolTestCase;

/**
 * Verifikasi berat (popup "timbang ulang"): berat nyata menggantikan berat tercatat, berat deklarasi tetap,
 * setiap verifikasi menulis checkpoint `receiving_verify`, selisih besar menandai alert tanpa menahan batch.
 */
class WeightVerificationTest extends PoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->threshold(50.0);   // bawaan longgar: ambang seed bisa kecil dan mengubah pesan sukses jadi peringatan
    }

    private function url(Batch $b): string
    {
        return "/company/batches/{$b->id}/verify-weight";
    }

    private function threshold(float $warning = 5.0): void
    {
        foreach (['lab_kimia', 'receiving'] as $stage) {
            MassBalanceThreshold::updateOrCreate(
                ['company_id' => $this->company->id, 'process_stage' => $stage],
                ['warning_threshold_pct' => $warning, 'critical_threshold_pct' => max($warning + 5, 10)],
            );
        }
    }

    #[Test]
    public function verify_replaces_current_weight_keeps_declared_and_writes_checkpoint(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 1000, 'initial_weight' => 1000]);

        $this->actingAs($this->admin)->post($this->url($b), ['weight' => 980.5, 'note' => 'timbang ulang Muntok'])
            ->assertSessionHas('success');

        $b->refresh();
        $this->assertEquals(980.5, $b->current_weight);
        $this->assertEquals(1000, $b->initial_weight, 'berat deklarasi tetap sebagai pembanding');
        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->latest('id')->firstOrFail();
        $this->assertEquals(980.5, $cp->weight_recorded);
        $this->assertEquals(1000, $cp->weight_previous);
        $this->assertEquals(19.5, $cp->weight_loss);
        $this->assertSame('weight_verify', $cp->checkpoint_data['action']);
        $this->assertEquals(-19.5, $cp->checkpoint_data['diff_kg']);
        $this->assertStringContainsString('timbang ulang Muntok', $cp->notes);
        $this->assertNotNull($cp->recorded_at);
    }

    #[Test]
    public function verify_works_for_a_freshly_tagged_batch_at_lab_kimia(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active', ['current_weight' => 500, 'rfid_tag' => 'E200VW0000000001']);

        $this->actingAs($this->admin)->post($this->url($b), ['weight' => 512])->assertSessionHas('success');

        $this->assertEquals(512, $b->fresh()->current_weight);
        $this->assertSame('E200VW0000000001', $b->fresh()->rfid_tag, 'tag tidak disentuh');
        $this->assertSame('lab_kimia', $b->fresh()->process_stage, 'tahap tidak berpindah');
    }

    #[Test]
    public function same_weight_is_confirmed_without_flagging(): void
    {
        $this->threshold(1.0);
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 750]);

        $this->actingAs($this->admin)->post($this->url($b), ['weight' => 750])->assertSessionHas('success');

        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->firstOrFail();
        $this->assertEquals(0, $cp->checkpoint_data['diff_kg']);
        $this->assertFalse((bool) $cp->has_discrepancy);
        $this->assertFalse($b->fresh()->has_discrepancy);
    }

    #[Test]
    public function large_difference_in_either_direction_flags_discrepancy_but_does_not_block(): void
    {
        $this->threshold(5.0);
        $down = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 1000]);
        $up   = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 1000]);

        $this->actingAs($this->admin)->post($this->url($down), ['weight' => 900])->assertSessionHas('warning');
        $this->actingAs($this->admin)->post($this->url($up), ['weight' => 1100])->assertSessionHas('warning');

        foreach ([$down, $up] as $b) {
            $this->assertTrue($b->fresh()->has_discrepancy);
            $this->assertSame('awaiting_intake', $b->fresh()->status, 'batch tetap lanjut ke penimbangan Receiving');
        }
    }

    #[Test]
    public function small_difference_under_threshold_is_not_flagged(): void
    {
        $this->threshold(5.0);
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 1000]);

        $this->actingAs($this->admin)->post($this->url($b), ['weight' => 990])->assertSessionHas('success');

        $this->assertFalse($b->fresh()->has_discrepancy);
    }

    #[Test]
    public function partner_signatory_is_recorded_on_checkpoint_and_batch(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake');

        $this->actingAs($this->admin)->post($this->url($b), ['weight' => 99, 'partner_signatory' => 'Pak Rudi'])->assertSessionHas('success');

        $this->assertSame('Pak Rudi', $b->fresh()->metadata['partner_signatory']);
        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->firstOrFail();
        $this->assertSame('Pak Rudi', $cp->checkpoint_data['partner_signatory']);
    }

    #[Test]
    public function batches_that_are_pooled_or_past_receiving_cannot_be_verified(): void
    {
        $wet = $this->makeBatch('wet_process', 'in_process', ['current_weight' => 300]);
        $pooled = $this->makeBatch('wet_process', 'pooled', ['current_weight' => 300]);

        foreach ([$wet, $pooled] as $b) {
            $this->actingAs($this->admin)->post($this->url($b), ['weight' => 250])->assertSessionHas('error');
            $this->assertEquals(300, $b->fresh()->current_weight);
            $this->assertFalse(Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->exists());
        }
    }

    #[Test]
    public function invalid_weights_are_rejected(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 100]);

        foreach ([0, -5, 'abc', 9999999] as $bad) {
            $this->actingAs($this->admin)->post($this->url($b), ['weight' => $bad])->assertSessionHasErrors('weight');
        }
        $this->assertEquals(100, $b->fresh()->current_weight);
    }

    #[Test]
    public function other_company_batch_and_non_operator_roles_are_forbidden(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake');
        $auditor = User::where('email', 'audit@ptimah.co.id')->firstOrFail();
        $dry = User::where('email', 'dry.mtk@ptimah.co.id')->firstOrFail();

        $this->actingAs($auditor)->post($this->url($b), ['weight' => 90])->assertForbidden();
        $this->actingAs($dry)->post($this->url($b), ['weight' => 90])->assertForbidden();
        $this->assertEquals(100, $b->fresh()->current_weight);
    }

    #[Test]
    public function intake_after_verify_measures_loss_against_the_verified_weight(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 1000]);
        $this->actingAs($this->admin)->post($this->url($b), ['weight' => 950])->assertSessionHas('success');

        app(IntakeService::class)->record($b->fresh(), 940, 60, $this->admin->id);

        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->latest('id')->firstOrFail();
        $this->assertEquals(950, $cp->weight_previous);
        $this->assertSame('wet_process', $b->fresh()->process_stage);
    }

    #[Test]
    public function intake_endpoint_accepts_optional_note_and_signatory(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['current_weight' => 400]);

        $this->actingAs($this->admin)->post("/company/process/receiving/intake/{$b->id}", [
            'weight' => 395, 'sn_grade_pct' => 58.5, 'note' => 'drum penyok', 'partner_signatory' => 'Bu Sari',
        ])->assertSessionHas('success');

        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'receiving_verify')->latest('id')->firstOrFail();
        $this->assertSame('Bu Sari', $cp->checkpoint_data['partner_signatory']);
        $this->assertStringContainsString('drum penyok', $cp->notes);
        $this->assertEquals(400, $cp->checkpoint_data['previous_weight']);
        $this->assertSame('Bu Sari', $b->fresh()->metadata['partner_signatory']);
    }

    #[Test]
    public function can_verify_rules_match_the_allowed_stage_status_pairs(): void
    {
        $this->assertTrue(WeightVerificationService::canVerify($this->makeBatch('lab_kimia', 'active')));
        $this->assertTrue(WeightVerificationService::canVerify($this->makeBatch('lab_kimia', 'in_transit')));
        $this->assertTrue(WeightVerificationService::canVerify($this->makeBatch('receiving', 'awaiting_intake')));
        $this->assertTrue(WeightVerificationService::canVerify($this->makeBatch('receiving', 'in_process')));
        $this->assertFalse(WeightVerificationService::canVerify($this->makeBatch('receiving', 'in_transit')));
        $this->assertFalse(WeightVerificationService::canVerify($this->makeBatch('wet_process', 'active')));
        $this->assertFalse(WeightVerificationService::canVerify($this->makeBatch('dry_process', 'in_process')));
    }

    #[Test]
    public function detail_page_exposes_verify_flag_only_when_allowed(): void
    {
        $ok = $this->makeBatch('receiving', 'awaiting_intake');
        $no = $this->makeBatch('wet_process', 'active');

        $this->actingAs($this->admin)->get("/company/batches/{$ok->id}")
            ->assertInertia(fn ($p) => $p->where('can_actions.verify_weight', true)->where('can_actions.edit_partner', true));
        $this->actingAs($this->admin)->get("/company/batches/{$no->id}")
            ->assertInertia(fn ($p) => $p->where('can_actions.verify_weight', false));
    }
}
