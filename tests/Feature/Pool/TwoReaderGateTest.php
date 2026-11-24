<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Process\IntakeService;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

/** Tahap 1: reader gabungan Receiving & Wet (stage receiving) dan reader Dry, keduanya arah "any" (selalu Masuk). */
class TwoReaderGateTest extends PoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['receiving', 'wet_process', 'dry_process'] as $s) {
            app(RfidAuthorizationService::class)->setMode($this->company->id, $s, 'scan_full', $this->admin->id);
        }
    }

    private function tap(string $readerStage, string $uid): array
    {
        $this->travel(30)->seconds();   // di luar jendela dedupe

        return app(RfidScanService::class)->scan(new DeviceContext($this->company->id, null, 'company_code'), 'in', $uid, $readerStage)[0];
    }

    private function drumBesi(string $tag): Batch
    {
        return $this->makeBatch('lab_kimia', 'in_transit', ['rfid_tag' => $tag, 'container_type' => 'drum_besi']);
    }

    #[Test]
    public function drum_besi_tap_goes_to_awaiting_intake(): void
    {
        $d = $this->drumBesi('E200TR000001');

        $r = $this->tap('receiving', 'E200TR000001');

        $this->assertSame('success', $r['status']);
        $this->assertSame(['receiving', 'awaiting_intake', 'E200TR000001'], [$d->fresh()->process_stage, $d->fresh()->status, $d->fresh()->rfid_tag]);
    }

    #[Test]
    public function repeat_tap_of_awaiting_intake_is_audit_only(): void
    {
        $d = $this->drumBesi('E200TR000002');
        $this->tap('receiving', 'E200TR000002');

        $r = $this->tap('receiving', 'E200TR000002');

        $this->assertSame('audit_only', $r['_mode'] ?? null);
        $this->assertSame('awaiting_intake', $d->fresh()->status);
    }

    #[Test]
    public function unknown_uid_at_receiving_reader_creates_wet_container(): void
    {
        $d = $this->drumBesi('E200TR000003');
        $this->tap('receiving', 'E200TR000003');
        app(IntakeService::class)->record($d->fresh(), 95, 1.5, $this->admin->id);

        $r = $this->tap('receiving', 'E200TR0000C1');

        $this->assertSame('success', $r['status']);
        $c = Batch::where('rfid_tag', 'E200TR0000C1')->firstOrFail();
        $this->assertSame(['wet_process', 'awaiting_weight', 'container'], [$c->process_stage, $c->status, $c->container_type]);
    }

    #[Test]
    public function container_on_receiving_scale_is_audit_only(): void
    {
        $d = $this->drumBesi('E200TR000004');
        $this->tap('receiving', 'E200TR000004');
        app(IntakeService::class)->record($d->fresh(), 95, 1.5, $this->admin->id);
        $this->tap('receiving', 'E200TR0000C2');

        $r = $this->tap('receiving', 'E200TR0000C2');

        $this->assertSame('audit_only', $r['_mode'] ?? null);
        $this->assertSame(1, Batch::whereNotNull('pool_id')->count());
    }

    #[Test]
    public function unknown_uid_at_dry_reader_in_direction_creates_product_drum(): void
    {
        $c = $this->makeBatch('wet_process', 'in_transit', ['rfid_tag' => 'E200TR0000C3', 'container_type' => 'container']);
        $this->tap('dry_process', 'E200TR0000C3');
        $this->assertSame(['dry_process', 'in_process'], [$c->fresh()->process_stage, $c->fresh()->status]);

        $r = $this->tap('dry_process', 'E200TR0000P1');

        $this->assertSame('success', $r['status']);
        $this->assertSame('product_drum', Batch::where('rfid_tag', 'E200TR0000P1')->value('container_type'));
    }

    #[Test]
    public function receiving_audit_only_mode_does_not_move(): void
    {
        app(RfidAuthorizationService::class)->setMode($this->company->id, 'receiving', 'audit_only', $this->admin->id);
        $d = $this->drumBesi('E200TR000005');

        $this->tap('receiving', 'E200TR000005');

        $this->assertSame(['lab_kimia', 'in_transit'], [$d->fresh()->process_stage, $d->fresh()->status]);
    }

    #[Test]
    public function full_two_reader_chain_to_warehouse(): void
    {
        $d = $this->drumBesi('E200TR000006');
        $this->tap('receiving', 'E200TR000006');
        app(IntakeService::class)->record($d->fresh(), 100, 2.0, $this->admin->id);
        $this->tap('receiving', 'E200TR0000C4');
        $c = Batch::where('rfid_tag', 'E200TR0000C4')->firstOrFail();
        app(PoolService::class)->recordDrumWeight($c, 60, null, $this->admin->id);

        $this->tap('dry_process', 'E200TR0000C4');
        $this->tap('dry_process', 'E200TR0000P2');
        $p = Batch::where('rfid_tag', 'E200TR0000P2')->firstOrFail();
        app(PoolService::class)->recordDrumWeight($p, 30, $this->product('ILM')->id, $this->admin->id);
        app(PoolService::class)->closeDryPool($p->pool, $this->admin->id);
        app(StageTransitionService::class)->receive($p->fresh(), 'warehouse', ['user_id' => $this->admin->id]);

        $this->assertSame(['warehouse', 'ILM'], [$p->fresh()->process_stage, $p->fresh()->productCode->code]);
        $this->assertSame(['pooled', 'pooled'], [$d->fresh()->status, $c->fresh()->status]);
    }
}
