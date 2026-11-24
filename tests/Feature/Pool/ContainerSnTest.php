<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Process\NewTagChoiceService;
use App\Services\Process\PoolService;
use PHPUnit\Framework\Attributes\Test;

/** Container hasil Wet ditimbang ulang dengan kadar Sn sisa; neraca Sn Wet = Sn masuk − Sn tersisa di container. */
class ContainerSnTest extends PoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['receiving', 'wet_process', 'dry_process'] as $s) {
            app(RfidAuthorizationService::class)->setMode($this->company->id, $s, 'scan_full', $this->admin->id);
        }
    }

    private function container(): Batch
    {
        $choice = app(NewTagChoiceService::class);
        $ctx = new DeviceContext($this->company->id, null, 'company_code');
        foreach (['E200SN0000B1' => 100.0, 'E200SN0000B2' => 50.0] as $uid => $kg) {
            app(RfidScanService::class)->scan($ctx, 'in', $uid, 'receiving');
            $choice->asNewBatch($this->company->id, $uid, $this->product('MIT')->id, $kg, 60.0, $this->admin->id);
        }
        $this->travel(30)->seconds();
        app(RfidScanService::class)->scan($ctx, 'in', 'E200SN0000C1', 'receiving');
        $choice->asWetOutput($this->company->id, 'E200SN0000C1', $this->admin->id);

        return Batch::where('rfid_tag', 'E200SN0000C1')->firstOrFail();
    }

    #[Test]
    public function residual_sn_is_stored_and_balanced(): void
    {
        $c = $this->container();

        $drum = app(PoolService::class)->recordDrumWeight($c, 80, null, $this->admin->id, 2.5);

        $this->assertEquals(2.5, (float) $drum->sn_grade_pct);
        $data = $drum->checkpoints()->latest('id')->value('checkpoint_data');
        $this->assertEquals(90.0, $data['sn_in_kg']);          // 150 kg × 60%
        $this->assertEquals(2.0, $data['sn_out_kg']);          // 80 kg × 2,5%
        $this->assertEquals(88.0, $data['sn_separated_kg']);
        $this->assertEquals(97.78, $data['sn_separated_pct']);
    }

    #[Test]
    public function web_weigh_of_wet_container_requires_residual_sn(): void
    {
        $c = $this->container();

        $this->actingAs($this->admin)->post("/company/process/pool/drums/{$c->id}/weight", ['weight' => 80])
            ->assertSessionHasErrors('sn_grade_pct');

        $this->actingAs($this->admin)->post("/company/process/pool/drums/{$c->id}/weight", ['weight' => 80, 'sn_grade_pct' => 2.5])
            ->assertSessionHas('success');
        $this->assertSame('in_transit', $c->fresh()->status);
    }
}
