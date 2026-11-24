<?php

namespace Tests\Feature\Pool;

use App\Models\Batch;
use App\Models\RfidTagEvent;
use App\Services\Hardware\DeviceContext;
use App\Services\Hardware\NewTagQueue;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidScanService;
use App\Services\Process\IntakeService;
use App\Services\Process\NewTagChoiceService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reader gabungan Receiving & Wet: UID baru TIDAK langsung diproses. UID masuk antrean dan operator memilih di PC:
 * "Batch baru" (timah basah datang, diuji kadar Sn di awal) atau "Hasil proses Wet" (container).
 */
class NewTagChoiceTest extends PoolTestCase
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
        $this->travel(30)->seconds();

        return app(RfidScanService::class)->scan(new DeviceContext($this->company->id, null, 'company_code'), 'in', $uid, $readerStage)[0];
    }

    private function queue(): NewTagQueue
    {
        return app(NewTagQueue::class);
    }

    private function choice(): NewTagChoiceService
    {
        return app(NewTagChoiceService::class);
    }

    /** Himpunan Wet berisi satu drum besi. */
    private function wetPoolWithDrum(string $tag = 'E200NT0000D1'): Batch
    {
        $d = $this->makeBatch('lab_kimia', 'in_transit', ['rfid_tag' => $tag, 'container_type' => 'drum_besi']);
        $this->tap('receiving', $tag);
        app(IntakeService::class)->record($d->fresh(), 95, 60, $this->admin->id);

        return $d->fresh();
    }

    #[Test]
    public function unknown_uid_at_receiving_reader_is_queued_not_processed(): void
    {
        $this->wetPoolWithDrum();

        $r = $this->tap('receiving', 'E200NT000001');

        $this->assertSame('tag_pending_choice', $r['_code']);
        $this->assertNull(Batch::where('rfid_tag', 'E200NT000001')->first());
        $this->assertSame(['E200NT000001'], array_column($this->queue()->list($this->company->id), 'uid'));
    }

    #[Test]
    public function repeat_reads_keep_one_queue_entry(): void
    {
        $this->tap('receiving', 'E200NT000002');
        $this->tap('receiving', 'E200NT000002');

        $this->assertCount(1, $this->queue()->list($this->company->id));
    }

    #[Test]
    public function queue_entry_expires_after_ten_minutes(): void
    {
        $this->tap('receiving', 'E200NT000003');

        $this->travel(11)->minutes();

        $this->assertSame([], $this->queue()->list($this->company->id));
    }

    #[Test]
    public function recycled_tag_is_rejected_and_not_queued(): void
    {
        $this->wetPoolWithDrum('E200NT0000D2');   // tag drum besi dilepas saat masuk himpunan Wet

        $r = $this->tap('receiving', 'E200NT0000D2');

        $this->assertSame('tag_recycled', $r['_code']);
        $this->assertSame([], $this->queue()->list($this->company->id));
    }

    #[Test]
    public function choose_wet_output_creates_container_and_clears_queue(): void
    {
        $this->wetPoolWithDrum();
        $this->tap('receiving', 'E200NT0000C1');

        $r = $this->choice()->asWetOutput($this->company->id, 'E200NT0000C1', $this->admin->id);

        $this->assertTrue($r['ok']);
        $c = Batch::where('rfid_tag', 'E200NT0000C1')->firstOrFail();
        $this->assertSame(['wet_process', 'awaiting_weight', 'container'], [$c->process_stage, $c->status, $c->container_type]);
        $this->assertSame([], $this->queue()->list($this->company->id));
    }

    #[Test]
    public function choose_wet_output_with_empty_pool_fails_and_keeps_queue(): void
    {
        $this->tap('receiving', 'E200NT0000C2');

        $r = $this->choice()->asWetOutput($this->company->id, 'E200NT0000C2', $this->admin->id);

        $this->assertFalse($r['ok']);
        $this->assertSame('pool_empty', $r['code']);
        $this->assertCount(1, $this->queue()->list($this->company->id));
    }

    #[Test]
    public function choose_new_batch_with_sn_pours_into_wet_pool(): void
    {
        $this->tap('receiving', 'E200NT0000B1');

        $r = $this->choice()->asNewBatch($this->company->id, 'E200NT0000B1', $this->product('MIT')->id, 120.5, 60.0, $this->admin->id);

        $this->assertTrue($r['ok']);
        $b = $r['batch']->fresh();
        $this->assertSame(['wet_process', 'drum_besi'], [$b->process_stage, $b->container_type]);
        $this->assertTrue(\App\Models\BatchPoolInput::where('batch_id', $b->id)->exists());   // masuk himpunan Wet
        $this->assertEquals(60.0, (float) $b->sn_grade_pct);
        $this->assertEquals(120.5, (float) $b->initial_weight);
        $this->assertNull($b->rfid_tag);   // tag dilepas saat dituang ke Wet
        $this->assertTrue(RfidTagEvent::where('uid', 'E200NT0000B1')->where('event', 'registered')->exists());
        $this->assertSame([], $this->queue()->list($this->company->id));
    }

    #[Test]
    public function choose_new_batch_without_sn_waits_for_intake(): void
    {
        $this->tap('receiving', 'E200NT0000B2');

        $r = $this->choice()->asNewBatch($this->company->id, 'E200NT0000B2', $this->product('MIT')->id, 110, null, $this->admin->id);

        $b = $r['batch']->fresh();
        $this->assertSame(['receiving', 'awaiting_intake', 'E200NT0000B2'], [$b->process_stage, $b->status, $b->rfid_tag]);

        // Kadar Sn hasil uji diisi kemudian lewat form timbang & kadar → masuk Wet.
        app(IntakeService::class)->record($b, 110, 61.2, $this->admin->id);
        $this->assertSame('wet_process', $b->fresh()->process_stage);
    }

    #[Test]
    public function new_batch_rejects_recycled_tag(): void
    {
        $this->wetPoolWithDrum('E200NT0000D3');

        $r = $this->choice()->asNewBatch($this->company->id, 'E200NT0000D3', $this->product('MIT')->id, 100, 60, $this->admin->id);

        $this->assertFalse($r['ok']);
        $this->assertSame('tag_recycled', $r['code']);
    }

    #[Test]
    public function dismiss_removes_queue_entry(): void
    {
        $this->tap('receiving', 'E200NT000004');

        $this->queue()->remove($this->company->id, 'E200NT000004');

        $this->assertSame([], $this->queue()->list($this->company->id));
    }

    #[Test]
    public function web_endpoints_drive_the_choice(): void
    {
        $this->tap('receiving', 'E200NT0000W1');

        $this->actingAs($this->admin)
            ->post('/company/process/receiving/new-tags/E200NT0000W1/batch', [
                'product_code_id' => $this->product('MIT')->id, 'weight' => 100, 'sn_grade_pct' => 60,
            ])->assertSessionHas('success');
        $this->assertNotNull(Batch::where('container_type', 'drum_besi')->where('sn_grade_pct', 60)->first());

        $this->tap('receiving', 'E200NT0000W2');
        $this->actingAs($this->admin)->delete('/company/process/receiving/new-tags/E200NT0000W2')->assertSessionHas('success');
        $this->assertSame([], $this->queue()->list($this->company->id));
    }

    #[Test]
    public function receiving_page_exposes_new_tags(): void
    {
        $this->tap('receiving', 'E200NT0000P1');

        $this->actingAs($this->admin)->get('/company/process/receiving')
            ->assertInertia(fn ($p) => $p->where('new_tags.0.uid', 'E200NT0000P1')->has('raw_products'));
    }
}
