<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Process\IntakeService;
use App\Services\Process\NewTagChoiceService;
use App\Services\Process\PoolService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regresi produksi 2026-11-24: drum 999,969 kg ditimbang 0,01 kg di Receiving (salah ketik), lalu container 90 kg.
 * Persen susut −899.900% melampaui kolom decimal → "numeric field overflow" → error 500 saat timbang container.
 */
class LossPctOverflowTest extends PoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['receiving', 'wet_process', 'dry_process'] as $s) {
            app(RfidAuthorizationService::class)->setMode($this->company->id, $s, 'scan_full', $this->admin->id);
        }
    }

    #[Test]
    public function extreme_weight_typo_does_not_crash_container_weighing(): void
    {
        $d = $this->makeBatch('lab_kimia', 'in_transit', ['rfid_tag' => 'E200OVF00001', 'container_type' => 'drum_besi', 'current_weight' => 999.969]);
        $ctx = new DeviceContext($this->company->id, null, 'company_code');
        app(RfidScanService::class)->scan($ctx, 'in', 'E200OVF00001', 'receiving');
        app(IntakeService::class)->record($d->fresh(), 0.01, 60, $this->admin->id);

        $this->travel(30)->seconds();
        app(RfidScanService::class)->scan($ctx, 'in', 'E200OVF000C1', 'receiving');
        app(NewTagChoiceService::class)->asWetOutput($this->company->id, 'E200OVF000C1', $this->admin->id);
        $c = Batch::where('rfid_tag', 'E200OVF000C1')->firstOrFail();

        $drum = app(PoolService::class)->recordDrumWeight($c, 90, null, $this->admin->id, 9.0);

        $this->assertSame('in_transit', $drum->status);
        $this->assertEquals(-999.99, $drum->mass_loss_pct);
        $this->assertEquals(-99999.99, (float) $drum->pool->fresh()->loss_pct);
    }

    #[Test]
    public function weighing_far_above_previous_weight_does_not_crash_intake(): void
    {
        $d = $this->makeBatch('lab_kimia', 'in_transit', ['rfid_tag' => 'E200OVF00002', 'container_type' => 'drum_besi', 'current_weight' => 1]);
        app(RfidScanService::class)->scan(new DeviceContext($this->company->id, null, 'company_code'), 'in', 'E200OVF00002', 'receiving');

        $b = app(IntakeService::class)->record($d->fresh(), 500, 60, $this->admin->id);

        $this->assertSame('wet_process', $b->process_stage);
        $this->assertEquals(-999.99, (float) $b->checkpoints()->where('checkpoint_stage', 'receiving_verify')->latest('id')->value('weight_loss_pct'));
    }
}
