<?php

namespace Tests\Feature\Export;

use App\Exports\BatchTraceabilityExport;
use App\Models\Batch;
use App\Models\Checkpoint;
use App\Support\CheckpointPresenter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pool\PoolTestCase;

/**
 * Kolom laporan Excel: Stage Point apa adanya ("Receiving Verify"), waktu WIB sampai detik, catatan pendaftaran tag
 * menyebut area ("oleh Receiving Area"), dan data mitra ikut tampil.
 */
class BatchTraceabilityExportTest extends PoolTestCase
{
    private function sheets(Batch $b): array
    {
        $b->load(['productCode', 'currentLocation', 'checkpoints.location', 'checkpoints.recordedByUser']);

        return (new BatchTraceabilityExport(collect([$b]), $this->company, Carbon::now()->subDay(), Carbon::now()->addDay()))->sheets();
    }

    private function cp(Batch $b, array $attrs): Checkpoint
    {
        return Checkpoint::create($attrs + [
            'batch_id' => $b->id, 'location_id' => $b->current_location_id, 'checkpoint_stage' => 'receiving_verify',
            'weight_recorded' => 100, 'weight_previous' => 100, 'weight_loss' => 0, 'weight_loss_pct' => 0,
            'has_discrepancy' => false, 'is_verified' => true, 'recorded_by' => $this->admin->id, 'recorded_at' => now(),
        ]);
    }

    #[Test]
    public function detail_sheet_uses_stage_point_title_case_wib_seconds_and_partner_columns(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake', ['lot_number' => 'BJ-1', 'metadata' => ['partner_name' => 'CV Bangka Jaya']]);
        $this->cp($b, ['recorded_at' => Carbon::parse('2026-11-27 02:40:12', 'UTC'), 'notes' => 'ok']);

        $detail = $this->sheets($b)[1];
        $row = $detail->collection()->first();

        $this->assertSame('Receiving Verify', $row['Stage Point']);
        $this->assertSame('27/11/2026 09:40:12', $row['Waktu (WIB)']);   // 02:40:12 UTC = 09:40:12 WIB
        $this->assertSame('BJ-1', $row['No. Partai / Lot']);
        $this->assertSame('CV Bangka Jaya', $row['Nama Mitra']);
        $this->assertSame(array_keys($row), $detail->headings(), 'judul kolom sama persis dengan kunci baris');
        $this->assertContains('Stage Point', $detail->headings());
        $this->assertContains('Waktu (WIB)', $detail->headings());
    }

    #[Test]
    public function summary_sheet_shows_partner_data_and_seconds(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active', [
            'lot_number' => 'MS-9', 'metadata' => ['partner_name' => 'PT Mitra', 'origin_location' => 'Belinyu', 'partner_signatory' => 'Bu Sari'],
        ]);
        $b->forceFill(['created_at' => Carbon::parse('2026-11-27 02:40:12', 'UTC'), 'updated_at' => Carbon::parse('2026-11-27 03:00:01', 'UTC')])->saveQuietly();

        $summary = $this->sheets($b->fresh())[0];
        $row = $summary->collection()->first();

        $this->assertSame(['MS-9', 'PT Mitra', 'Belinyu', 'Bu Sari'], [$row['No. Partai / Lot'], $row['Nama Mitra'], $row['Lokasi Asal'], $row['Penanda Tangan Mitra']]);
        $this->assertSame('27/11/2026 09:40:12', $row['Dibuat (WIB)']);
        $this->assertSame('27/11/2026 10:00:01', $row['Terakhir Update (WIB)']);
        $this->assertSame(array_keys($row), $summary->headings());
    }

    #[Test]
    public function new_registration_note_names_the_area_not_the_person(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake');

        $this->actingAs($this->admin)->postJson('/api/v1/company/rfid/registration', ['batch_id' => $b->id, 'uid' => 'E200NOTE00000001'])
            ->assertOk()->assertJson(['code' => 'tag_registered']);

        $cp = Checkpoint::where('batch_id', $b->id)->latest('id')->firstOrFail();
        $this->assertSame('RFID Tag terdaftar: UID E200NOTE00000001 oleh Receiving Area', $cp->notes);
        $this->assertSame('Receiving Area', $cp->checkpoint_data['area']);
        $this->assertSame($this->admin->name, $cp->checkpoint_data['initiated_by'], 'nama operator tetap tersimpan');
        $this->assertSame($cp->notes, CheckpointPresenter::note($cp));
    }

    #[Test]
    public function legacy_registration_note_is_normalised_to_area_in_reports(): void
    {
        $b = $this->makeBatch('lab_kimia', 'active');
        $old = $this->cp($b, [
            'checkpoint_stage' => 'lab_kimia_create', 'notes' => 'RFID Tag terdaftar: UID E200OLD000000001 oleh Budi Operator',
            'checkpoint_data' => ['action' => 'rfid_register', 'batch_snapshot' => ['process_stage' => 'lab_kimia']],
        ]);
        $recv = $this->cp($b, [
            'notes' => 'RFID Tag terdaftar: UID E200OLD000000002 oleh Budi Operator',
            'checkpoint_data' => ['action' => 'rfid_register', 'batch_snapshot' => ['process_stage' => 'receiving']],
        ]);

        $this->assertSame('RFID Tag terdaftar: UID E200OLD000000001 oleh Lab Kimia', CheckpointPresenter::note($old));
        $this->assertSame('RFID Tag terdaftar: UID E200OLD000000002 oleh Receiving Area', CheckpointPresenter::note($recv));
    }

    #[Test]
    public function ordinary_notes_and_missing_values_are_left_alone(): void
    {
        $b = $this->makeBatch('receiving', 'awaiting_intake');
        $plain = $this->cp($b, ['notes' => 'Drum ditimbang 95 kg — 27/11/2026 09:40:12 WIB']);
        $none  = $this->cp($b, ['notes' => null]);
        // Catatan mirip tetapi bukan checkpoint pendaftaran: tidak disentuh.
        $lookalike = $this->cp($b, ['notes' => 'RFID Tag terdaftar: UID E200X oleh Orang', 'checkpoint_data' => ['action' => 'other']]);

        $this->assertSame('Drum ditimbang 95 kg — 27/11/2026 09:40:12 WIB', CheckpointPresenter::note($plain));
        $this->assertSame('—', CheckpointPresenter::note($none));
        $this->assertSame('RFID Tag terdaftar: UID E200X oleh Orang', CheckpointPresenter::note($lookalike));
        $this->assertSame('—', CheckpointPresenter::time(null));
        $this->assertSame('—', CheckpointPresenter::stagePoint(null));
        $this->assertSame('Lab Kimia Create', CheckpointPresenter::stagePoint('lab_kimia_create'));
        $this->assertSame('In Transit', CheckpointPresenter::stagePoint('in_transit'));
    }
}
