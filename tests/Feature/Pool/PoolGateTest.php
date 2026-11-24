<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Process\PoolService;
use App\Services\Process\StageTransitionService;
use PHPUnit\Framework\Attributes\Test;

class PoolGateTest extends PoolTestCase
{
    private function mode(string $stage, string $mode): void
    {
        app(RfidAuthorizationService::class)->setMode($this->company->id, $stage, $mode, $this->admin->id);
    }

    private function scan(string $direction, string $stage, string $uid): array
    {
        $ctx = new DeviceContext($this->company->id, null, 'company_code');

        return app(RfidScanService::class)->scan($ctx, $direction, $uid, $stage)[0];
    }

    private function poolOne(string $stage = 'wet_process'): Batch
    {
        $b = $this->makeBatch($stage === 'wet_process' ? 'receiving' : 'wet_process', 'in_transit');
        app(StageTransitionService::class)->receive($b, $stage, ['user_id' => $this->admin->id]);

        return $b->fresh();
    }

    #[Test]
    public function unknown_uid_at_wet_out_creates_drum(): void
    {
        $this->mode('wet_process', 'scan_full');
        $this->poolOne();

        $r = $this->scan('out', 'wet_process', 'E200GATE0001');

        $this->assertSame('success', $r['status']);
        $this->assertSame('awaiting_weight', Batch::where('rfid_tag', 'E200GATE0001')->value('status'));
    }

    #[Test]
    public function unknown_uid_at_wet_in_stays_tag_unknown(): void
    {
        $this->mode('wet_process', 'scan_full');
        $this->poolOne();

        $r = $this->scan('in', 'wet_process', 'E200GATE0002');

        $this->assertSame('failed', $r['status']);
        $this->assertSame('tag_unknown', $r['_code'] ?? null);
        $this->assertFalse(Batch::where('rfid_tag', 'E200GATE0002')->exists());
    }

    #[Test]
    public function audit_only_mode_does_not_create_drum(): void
    {
        $this->mode('wet_process', 'audit_only');
        $this->poolOne();

        $r = $this->scan('out', 'wet_process', 'E200GATE0003');

        $this->assertSame('failed', $r['status']);
        $this->assertFalse(Batch::where('rfid_tag', 'E200GATE0003')->exists());
    }

    #[Test]
    public function empty_pool_at_gate_is_rejected(): void
    {
        $this->mode('wet_process', 'scan_full');

        $r = $this->scan('out', 'wet_process', 'E200GATE0004');

        $this->assertSame('failed', $r['status']);
        $this->assertSame('pool_empty', $r['_code']);
    }

    #[Test]
    public function rescan_of_new_drum_uid_does_not_create_second_drum(): void
    {
        $this->mode('dry_process', 'scan_full');
        $this->poolOne('dry_process');

        $this->scan('out', 'dry_process', 'E200GATE0005');
        $this->travel(30)->seconds();
        $this->scan('out', 'dry_process', 'E200GATE0005');

        $this->assertSame(1, Batch::where('rfid_tag', 'E200GATE0005')->count());
        $this->assertSame(1, Batch::whereNotNull('pool_id')->count());
    }

    #[Test]
    public function full_chain_receiving_to_warehouse_with_new_tags(): void
    {
        foreach (['receiving', 'wet_process', 'dry_process', 'warehouse'] as $s) {
            $this->mode($s, 'scan_full');
        }
        $a = $this->makeBatch('receiving', 'in_process', ['rfid_tag' => 'E200CHAIN0A1', 'current_weight' => 100]);
        $b = $this->makeBatch('receiving', 'in_process', ['rfid_tag' => 'E200CHAIN0B1', 'current_weight' => 50]);
        $pools = app(PoolService::class);

        foreach (['E200CHAIN0A1', 'E200CHAIN0B1'] as $uid) {
            $this->assertSame('success', $this->scan('out', 'receiving', $uid)['status']);
            $this->assertSame('success', $this->scan('in', 'wet_process', $uid)['status']);
        }
        $this->assertNull($a->fresh()->rfid_tag);

        $this->assertSame('success', $this->scan('out', 'wet_process', 'E200CHAIN0W1')['status']);
        $wet = Batch::where('rfid_tag', 'E200CHAIN0W1')->firstOrFail();
        $pools->recordDrumWeight($wet, 120, null, $this->admin->id);

        $this->assertSame('success', $this->scan('in', 'dry_process', 'E200CHAIN0W1')['status']);
        $this->assertSame('success', $this->scan('out', 'dry_process', 'E200CHAIN0D1')['status']);
        $dry = Batch::where('rfid_tag', 'E200CHAIN0D1')->firstOrFail();
        $pools->recordDrumWeight($dry, 40, $this->product('MON')->id, $this->admin->id);
        $this->completeDryPool($dry->pool);
        $pools->closeDryPool($dry->pool->fresh(), $this->admin->id);

        $this->assertSame('success', $this->scan('in', 'warehouse', 'E200CHAIN0D1')['status']);
        $dry->refresh();
        $this->assertSame(['warehouse', 'in_warehouse', 'MON'], [$dry->process_stage, $dry->status, $dry->productCode->code]);
        $this->assertSame(['pooled', 'pooled'], [$a->fresh()->status, $wet->fresh()->status]);
    }
}
