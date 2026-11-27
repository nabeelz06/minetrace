<?php

namespace Tests\Feature\Batch;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Location;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pool\PoolTestCase;

/**
 * Data mitra PT Timah pada batch: nama mitra, no. partai/kode mitra (lot_number, unik), lokasi asal kiriman,
 * penanda tangan (validator). Semua opsional; dapat dilengkapi/dikoreksi kemudian dan tercatat di audit log.
 */
class PartnerInfoTest extends PoolTestCase
{
    private function createPayload(array $extra = []): array
    {
        return $extra + [
            'product_code_id' => $this->product('MIT')->id,
            'location_id'     => Location::where('company_id', $this->company->id)->where('location_type', 'lab_kimia')->firstOrFail()->id,
            'initial_weight'  => 750.25,
        ];
    }

    #[Test]
    public function batch_can_be_created_with_partner_data(): void
    {
        $this->actingAs($this->admin)->post('/company/batches', $this->createPayload([
            'partner_name' => 'CV Bangka Jaya', 'lot_number' => 'BJ-2026-0123',
            'origin_location' => 'Tempilang', 'partner_signatory' => 'Pak Rudi',
        ]))->assertSessionHas('success');

        $b = Batch::where('lot_number', 'BJ-2026-0123')->firstOrFail();
        $this->assertSame('CV Bangka Jaya', $b->metadata['partner_name']);
        $this->assertSame('Tempilang', $b->metadata['origin_location']);
        $this->assertSame('Pak Rudi', $b->metadata['partner_signatory']);
        $cp = Checkpoint::where('batch_id', $b->id)->where('checkpoint_stage', 'lab_kimia_create')->firstOrFail();
        $this->assertSame('CV Bangka Jaya', $cp->checkpoint_data['partner_name']);
        $this->assertSame('BJ-2026-0123', $cp->checkpoint_data['lot_number']);
    }

    #[Test]
    public function partner_data_is_optional_and_blank_values_are_not_stored(): void
    {
        $this->actingAs($this->admin)->post('/company/batches', $this->createPayload([
            'partner_name' => '  ', 'lot_number' => '', 'origin_location' => null,
        ]))->assertSessionHas('success');

        $b = Batch::latest('id')->firstOrFail();
        $this->assertNull($b->lot_number);
        $this->assertArrayNotHasKey('partner_name', $b->metadata ?? []);
    }

    #[Test]
    public function two_batches_without_lot_number_are_allowed_but_duplicate_lot_is_rejected(): void
    {
        $this->actingAs($this->admin)->post('/company/batches', $this->createPayload())->assertSessionHas('success');
        $this->actingAs($this->admin)->post('/company/batches', $this->createPayload())->assertSessionHas('success');
        $this->actingAs($this->admin)->post('/company/batches', $this->createPayload(['lot_number' => 'DUP-1']))->assertSessionHas('success');

        $this->actingAs($this->admin)->post('/company/batches', $this->createPayload(['lot_number' => 'DUP-1']))
            ->assertSessionHasErrors('lot_number');
        $this->assertSame(1, Batch::where('lot_number', 'DUP-1')->count());
    }

    #[Test]
    public function partner_data_can_be_completed_later_and_is_audited(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active');

        $this->actingAs($this->admin)->patch("/company/batches/{$b->id}/partner", [
            'partner_name' => 'PT Mitra Sejahtera', 'lot_number' => 'MS-77', 'origin_location' => 'Jebus', 'partner_signatory' => 'Bu Sari',
        ])->assertSessionHas('success');

        $b->refresh();
        $this->assertSame('MS-77', $b->lot_number);
        $this->assertSame('PT Mitra Sejahtera', $b->metadata['partner_name']);
        $this->assertSame('Jebus', $b->metadata['origin_location']);
        $this->assertSame('Bu Sari', $b->metadata['partner_signatory']);
        $this->assertTrue(Activity::where('log_name', 'default')->where('description', 'partner_info_updated')->where('subject_id', $b->id)->exists());
    }

    #[Test]
    public function partner_update_can_clear_a_field_and_keeps_other_metadata(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active', ['metadata' => ['rfid_registered_at' => 'x', 'partner_name' => 'Lama', 'origin_location' => 'Belinyu']]);

        $this->actingAs($this->admin)->patch("/company/batches/{$b->id}/partner", ['partner_name' => '', 'origin_location' => 'Mentok'])
            ->assertSessionHas('success');

        $meta = $b->fresh()->metadata;
        $this->assertArrayNotHasKey('partner_name', $meta);
        $this->assertSame('Mentok', $meta['origin_location']);
        $this->assertSame('x', $meta['rfid_registered_at']);
    }

    #[Test]
    public function partner_update_cannot_steal_another_batchs_lot_number(): void
    {
        $this->makeBatch('lab_kimia', 'active', ['lot_number' => 'TAKEN-1']);
        $b = $this->makeBatch('lab_kimia', 'active');

        $this->actingAs($this->admin)->patch("/company/batches/{$b->id}/partner", ['lot_number' => 'TAKEN-1'])->assertSessionHasErrors('lot_number');

        // Menyimpan lot milik sendiri lagi tidak dianggap duplikat.
        $own = $this->makeBatch('lab_kimia', 'active', ['lot_number' => 'OWN-1']);
        $this->actingAs($this->admin)->patch("/company/batches/{$own->id}/partner", ['lot_number' => 'OWN-1', 'partner_name' => 'X'])->assertSessionHas('success');
    }

    #[Test]
    public function auditor_cannot_edit_partner_data(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active');
        $auditor = User::where('email', 'audit@ptimah.co.id')->firstOrFail();

        $this->actingAs($auditor)->patch("/company/batches/{$b->id}/partner", ['partner_name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function list_search_finds_by_partner_name_and_lot_number(): void
    {
        $this->makeBatch('lab_kimia', 'active', ['lot_number' => 'ZZ-900', 'metadata' => ['partner_name' => 'CV Cari Aku']]);
        $this->makeBatch('lab_kimia', 'active');

        foreach (['Cari Aku', 'zz-900'] as $q) {
            $this->actingAs($this->admin)->get('/company/batches?search=' . urlencode($q))
                ->assertInertia(fn ($p) => $p->has('batches.data', 1)->where('batches.data.0.lot_number', 'ZZ-900')->where('batches.data.0.partner_name', 'CV Cari Aku'));
        }
    }

    #[Test]
    public function detail_page_exposes_partner_fields(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active', ['lot_number' => 'P-1', 'metadata' => ['partner_name' => 'CV A', 'origin_location' => 'Penganak', 'partner_signatory' => 'Z']]);

        $this->actingAs($this->admin)->get("/company/batches/{$b->id}")
            ->assertInertia(fn ($p) => $p->where('batch.partner_name', 'CV A')->where('batch.origin_location', 'Penganak')
                ->where('batch.partner_signatory', 'Z')->where('batch.lot_number', 'P-1')->has('origin_locations'));
    }
}
