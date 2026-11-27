<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Company;
use App\Models\RfidTagEvent;
use App\Models\User;
use App\Services\Hardware\RfidTagService;
use App\Services\Process\PoolService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pool\PoolTestCase;

/**
 * "Bebaskan tag (pakai ulang)": jumlah tag sedikit dan dipakai berulang, jadi satu aksi melepas tag dari batch lama
 * lalu menandainya "baru lagi". Riwayat TIDAK dihapus — pemeriksaan "tag bekas" hanya menghitung jejak sesudah penanda.
 */
class TagReuseTest extends PoolTestCase
{
    private const BASE = '/api/v1/company/rfid';

    private const UID = 'E200REUSE0000001';

    private function register(Batch $b, string $uid = self::UID): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE . '/registration', ['batch_id' => $b->id, 'uid' => $uid])
            ->assertOk()->assertJson(['code' => 'tag_registered']);
    }

    private function hasHistory(string $uid = self::UID): bool
    {
        return app(PoolService::class)->uidHasHistory($this->company->id, $uid);
    }

    #[Test]
    public function reuse_releases_attached_tag_and_marks_it_fresh(): void
    {
        $old = $this->makeBatch('lab_kimia', 'active');
        $this->register($old);
        $this->assertTrue($this->hasHistory(), 'tag terdaftar = punya riwayat');

        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', ['uid' => self::UID, 'note' => 'karung kosong'])
            ->assertOk()->assertJson(['code' => 'tag_reuse_ready']);

        $this->assertNull($old->fresh()->rfid_tag);
        $this->assertFalse($this->hasHistory(), 'setelah dibebaskan dianggap baru lagi');
        $this->assertTrue(RfidTagEvent::where('uid', self::UID)->where('event', 'released')->where('reason', 'reuse')->exists());
        $this->assertTrue(RfidTagEvent::where('uid', self::UID)->where('event', 'cleared')->where('reason', 'reuse')->exists());
        // Riwayat tidak dihapus.
        $this->assertTrue(RfidTagEvent::where('uid', self::UID)->where('event', 'registered')->exists());
    }

    #[Test]
    public function history_after_reuse_counts_again_even_within_the_same_second(): void
    {
        $this->travelTo(now()->startOfSecond());   // semua kejadian jatuh pada detik yang sama
        $a = $this->makeBatch('lab_kimia', 'active');
        $this->register($a);
        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', ['uid' => self::UID])->assertOk();
        $this->assertFalse($this->hasHistory());

        $b = $this->makeBatch('lab_kimia', 'active');
        $this->register($b);   // dipakai lagi → riwayat baru

        $this->assertTrue($this->hasHistory(), 'pemakaian sesudah penanda harus terhitung (batas berbasis id, bukan waktu)');
        $this->assertSame(self::UID, $b->fresh()->rfid_tag);
    }

    #[Test]
    public function reuse_can_be_repeated_over_many_cycles(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $b = $this->makeBatch('lab_kimia', 'active');
            $this->register($b);
            $this->assertTrue($this->hasHistory(), "siklus {$i}: terdaftar");
            $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', ['uid' => self::UID])->assertOk();
            $this->assertFalse($this->hasHistory(), "siklus {$i}: dibebaskan");
        }
    }

    #[Test]
    public function used_but_free_tag_is_reported_not_fresh_until_reused(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active');
        $this->register($b);
        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/release', ['uid' => self::UID, 'reason' => 'replaced'])->assertOk();

        $tags = app(RfidTagService::class);
        $this->assertSame('free', $tags->lookup($this->company->id, self::UID)['state']);
        $this->assertFalse($tags->lookup($this->company->id, self::UID)['fresh']);

        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', ['uid' => self::UID])->assertOk()
            ->assertJsonPath('data.was_attached', false);

        $this->assertTrue($tags->lookup($this->company->id, self::UID)['fresh']);
    }

    #[Test]
    public function brand_new_tag_is_fresh_and_reuse_is_harmless(): void
    {
        $this->assertTrue(app(RfidTagService::class)->lookup($this->company->id, 'E200NEVERUSED01')['fresh']);

        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', ['uid' => 'E200NEVERUSED01'])->assertOk();

        $this->assertFalse($this->hasHistory('E200NEVERUSED01'));
    }

    #[Test]
    public function reuse_does_not_touch_a_tag_owned_by_another_company(): void
    {
        $other = Company::factory()->create();
        $foreign = Batch::factory()->create([
            'company_id' => $other->id, 'product_code_id' => $this->product()->id, 'rfid_tag' => 'E200FOREIGN0001',
            'process_stage' => 'lab_kimia', 'status' => 'active',
        ]);

        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', ['uid' => 'E200FOREIGN0001'])
            ->assertStatus(422)->assertJson(['code' => 'tag_in_use']);

        $this->assertSame('E200FOREIGN0001', $foreign->fresh()->rfid_tag);
        $this->assertFalse(RfidTagEvent::where('uid', 'E200FOREIGN0001')->exists());
    }

    #[Test]
    public function auditor_cannot_reuse_tags(): void
    {
        $auditor = User::where('email', 'audit@ptimah.co.id')->firstOrFail();

        $this->actingAs($auditor)->postJson(self::BASE . '/tags/reuse', ['uid' => self::UID])->assertForbidden();
    }

    #[Test]
    public function reuse_requires_a_uid(): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE . '/tags/reuse', [])->assertStatus(422);
    }
}
