<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\RfidTagEvent;
use App\Models\User;
use App\Services\Hardware\RfidAuthorizationService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Kelola Tag" + mode serah-terima penuh:
 *  - cek status tag lewat UID (bebas / terpasang / diarsipkan / milik perusahaan lain),
 *  - lepas tag lewat UID (termasuk batch yang diarsipkan) dan pelupaan jejak sementara,
 *  - reset uji (scan disembunyikan, posisi batch dikembalikan, tag tetap) beserta semua pagar pengamannya,
 *  - mode `scan_full` + preset beberapa tahap, dan hasil `transition_state` pada respons scan.
 */
class TagLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/company/rfid';

    private const UID = 'E20000200914016323809025';

    private Company $company;

    private User $admin;

    private User $wet;

    private User $auditor;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');
        config(['rfid.test_reset_enabled' => true]);

        $this->company = Company::where('code', 'TIMAH')->firstOrFail();
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
        $this->wet = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();
        $this->auditor = User::where('email', 'audit@ptimah.co.id')->firstOrFail();
        $this->productId = ProductCode::where('company_id', $this->company->id)->firstOrFail()->id;
    }

    private function batch(array $attrs = []): Batch
    {
        return Batch::factory()->create($attrs + [
            'company_id' => $this->company->id, 'product_code_id' => $this->productId,
            'process_stage' => 'receiving', 'status' => 'in_process', 'initial_weight' => 1000, 'current_weight' => 1000,
            'rfid_tag' => null, 'has_discrepancy' => false,
        ]);
    }

    /** Daftarkan tag lewat jalur registrasi sungguhan (menulis checkpoint registrasi + batch_snapshot). */
    private function register(Batch $batch, string $uid = self::UID): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE.'/registration', ['batch_id' => $batch->id, 'uid' => $uid])
            ->assertOk()->assertJson(['code' => 'tag_registered']);
    }

    private function scan(string $direction, string $stage, string $uid = self::UID): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(self::BASE.'/scans', ['uid' => $uid, 'direction' => $direction, 'stage' => $stage]);
    }

    private function mode(string $stage, string $mode): void
    {
        app(RfidAuthorizationService::class)->setMode($this->company->id, $stage, $mode, $this->admin->id);
    }

    // ─── Cek status tag ───────────────────────────────────────────────────

    #[Test]
    public function lookup_membedakan_tag_bebas_terpasang_diarsipkan_dan_milik_perusahaan_lain(): void
    {
        $bound = $this->batch(['rfid_tag' => 'AA11BB33']);
        $archived = $this->batch(['rfid_tag' => 'AA11BB44']);
        $archived->delete();
        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $this->batch(['company_id' => $other->id, 'rfid_tag' => 'AA11BB55']);

        $get = fn (string $uid) => $this->actingAs($this->auditor)->getJson(self::BASE.'/tags/lookup?uid='.$uid);

        $get('AA11BB22')->assertOk()->assertJsonPath('data.state', 'free')->assertJsonPath('data.batch', null);
        $get('aa:11:bb:33')->assertOk()   // UID dinormalkan (huruf besar, tanpa pemisah)
            ->assertJsonPath('data.uid', 'AA11BB33')
            ->assertJsonPath('data.state', 'bound')
            ->assertJsonPath('data.batch.batch_code', $bound->batch_code);
        $get('AA11BB44')->assertOk()->assertJsonPath('data.state', 'archived')->assertJsonPath('data.batch.archived', true);

        $foreign = $get('AA11BB55')->assertOk()->assertJsonPath('data.state', 'foreign');
        $this->assertNull($foreign->json('data.batch'), 'tag perusahaan lain tidak membocorkan rincian batch');
    }

    #[Test]
    public function lookup_wajib_uid_dan_tag_terbaru_hanya_memuat_pembacaan_perusahaan_sendiri(): void
    {
        $this->actingAs($this->auditor)->getJson(self::BASE.'/tags/lookup')->assertStatus(422);

        $batch = $this->batch();
        $this->register($batch);
        $this->scan('in', 'wet_process')->assertOk();
        $this->scan('in', 'wet_process', 'DEAD0001')->assertStatus(422)->assertJson(['code' => 'tag_unknown']);

        $res = $this->actingAs($this->auditor)->getJson(self::BASE.'/tags/recent')->assertOk();
        $byUid = collect($res->json('data'))->keyBy('uid');

        $this->assertSame('bound', $byUid[self::UID]['state']);
        $this->assertSame($batch->batch_code, $byUid[self::UID]['batch']['batch_code']);
        $this->assertSame('free', $byUid['DEAD0001']['state'], 'tag asing yang ditolak tetap tampil agar bisa langsung didaftarkan');
    }

    #[Test]
    public function tag_terbaru_menandai_tag_milik_perusahaan_lain_sebagai_foreign_tanpa_membocorkan_batch(): void
    {
        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $this->batch(['company_id' => $other->id, 'rfid_tag' => 'AA11BB66']);

        // Tag milik perusahaan lain terbaca di reader kita: ditolak sebagai tidak dikenal, tetapi tidak boleh tampil "bebas".
        $this->scan('in', 'wet_process', 'AA11BB66')->assertStatus(422)->assertJson(['code' => 'tag_unknown']);

        $row = collect($this->actingAs($this->auditor)->getJson(self::BASE.'/tags/recent')->assertOk()->json('data'))->firstWhere('uid', 'AA11BB66');

        $this->assertNotNull($row, 'pembacaan tetap tampil di daftar tag terbaru');
        $this->assertSame('foreign', $row['state']);
        $this->assertNull($row['batch'], 'rincian batch perusahaan lain tidak bocor');
    }

    // ─── Lepas tag ────────────────────────────────────────────────────────

    #[Test]
    public function lepas_tag_lewat_uid_mengosongkan_batch_mencatat_riwayat_dan_membebaskan_uid(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AA11BB66']);

        $this->actingAs($this->wet)->postJson(self::BASE.'/tags/release', ['uid' => 'aa11bb66', 'reason' => 'replaced', 'note' => 'ganti tag uji'])
            ->assertOk()->assertJson(['code' => 'tag_released', 'data' => ['batch_code' => $batch->batch_code, 'released_uid' => 'AA11BB66']]);

        $this->assertNull($batch->fresh()->rfid_tag);
        $this->assertDatabaseHas('rfid_tag_events', ['batch_id' => $batch->id, 'uid' => 'AA11BB66', 'event' => 'released', 'reason' => 'replaced', 'performed_by' => $this->wet->id]);
        $this->actingAs($this->wet)->getJson(self::BASE.'/tags/lookup?uid=AA11BB66')->assertJsonPath('data.state', 'free');
    }

    #[Test]
    public function lepas_tag_menjangkau_batch_yang_diarsipkan_dan_menolak_tag_bebas_atau_milik_lain(): void
    {
        $archived = $this->batch(['rfid_tag' => 'AA11BB77']);
        $archived->delete();
        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $foreign = $this->batch(['company_id' => $other->id, 'rfid_tag' => 'AA11BB88']);

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB77', 'reason' => 'other'])->assertOk();
        $this->assertNull(Batch::withTrashed()->find($archived->id)->rfid_tag, 'UNIQUE tidak lagi menahan UID batch yang diarsipkan');

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB99', 'reason' => 'other'])
            ->assertStatus(422)->assertJson(['code' => 'tag_free']);
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB88', 'reason' => 'other'])
            ->assertStatus(422)->assertJson(['code' => 'tag_free']);
        $this->assertSame('AA11BB88', $foreign->fresh()->rfid_tag, 'tag perusahaan lain tidak tersentuh');
    }

    #[Test]
    public function lepas_tag_butuh_izin_operate_dan_alasan_baku(): void
    {
        $this->batch(['rfid_tag' => 'AA11BB66']);

        $this->actingAs($this->auditor)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB66', 'reason' => 'lost'])->assertStatus(403);
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB66'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB66', 'reason' => 'bebas-isi'])->assertStatus(422);
    }

    #[Test]
    public function lepas_tag_melupakan_dedupe_sehingga_tag_yang_sama_di_batch_baru_langsung_terbaca_baru(): void
    {
        $a = $this->batch();
        $this->register($a);
        $first = $this->scan('in', 'wet_process')->assertOk();
        $this->assertFalse($first->json('data.duplicate'));
        $this->assertTrue($this->scan('in', 'wet_process')->json('data.duplicate'), 'tanpa pelepasan, bacaan ulang cepat dianggap duplikat');

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => self::UID, 'reason' => 'replaced'])->assertOk();
        $b = $this->batch();
        $this->register($b);

        $again = $this->scan('in', 'wet_process')->assertOk();
        $this->assertFalse($again->json('data.duplicate'), 'jejak dedupe dilupakan saat tag dilepas');
        $this->assertSame($b->batch_code, $again->json('data.batch_code'), 'bacaan baru memakai batch baru, bukan hasil lama batch A');
    }

    // ─── Reset uji ────────────────────────────────────────────────────────

    #[Test]
    public function reset_uji_scan_audit_menyembunyikan_scan_tetap_memasang_tag_dan_siap_dites_ulang(): void
    {
        $batch = $this->batch();
        $this->register($batch);
        $this->scan('in', 'wet_process')->assertOk();   // mode bawaan audit_only: hanya jejak, posisi tidak berubah
        $this->assertSame('receiving', $batch->fresh()->process_stage);

        $preview = $this->actingAs($this->admin)->getJson(self::BASE.'/tags/lookup?uid='.self::UID)->assertOk();
        $preview->assertJsonPath('data.reset.enabled', true)->assertJsonPath('data.reset.scans', 1)->assertJsonPath('data.reset.moves', 0)
            ->assertJsonPath('data.reset.blocked', null)->assertJsonPath('data.reset.rewind', null);

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => self::UID, 'note' => 'uji ulang'])
            ->assertOk()->assertJson(['code' => 'tag_reset', 'data' => ['voided' => 1, 'rewound' => false, 'batch_code' => $batch->batch_code]]);

        $this->assertSame(self::UID, $batch->fresh()->rfid_tag, 'tag tetap terpasang');
        $this->assertDatabaseHas('rfid_tag_events', ['batch_id' => $batch->id, 'event' => 'reset', 'reason' => 'test', 'note' => 'uji ulang']);

        $feed = collect($this->actingAs($this->admin)->getJson(self::BASE.'/scans?voided=0&batch_id='.$batch->id)->assertOk()->json('data'));
        $this->assertCount(1, $feed, 'umpan hanya menyisakan baris registrasi');
        $this->assertTrue($feed->first()['is_registration']);
        $this->assertSame(1, Checkpoint::where('batch_id', $batch->id)->whereNotNull('voided_at')->count(), 'scan di-void, bukan dihapus (audit tetap)');

        $this->assertFalse($this->scan('in', 'wet_process')->assertOk()->json('data.duplicate'), 'cache dedupe dilupakan: tes ulang langsung diproses');
    }

    #[Test]
    public function reset_uji_membalik_serah_terima_otomatis_ke_posisi_saat_registrasi(): void
    {
        $this->mode('wet_process', 'scan_to_receive');
        $batch = $this->batch();
        $this->register($batch);

        $moved = $this->scan('in', 'wet_process')->assertOk();
        $moved->assertJsonPath('data.transition_state', 'moved');
        $this->assertSame(['wet_process', 'in_process'], [$batch->fresh()->process_stage, $batch->fresh()->status]);

        $this->actingAs($this->admin)->getJson(self::BASE.'/tags/lookup?uid='.self::UID)
            ->assertJsonPath('data.reset.moves', 1)
            ->assertJsonPath('data.reset.records', 2)   // catatan audit scan + checkpoint serah-terima
            ->assertJsonPath('data.reset.rewind.process_stage', 'receiving')
            ->assertJsonPath('data.reset.rewind.status', 'in_process');

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => self::UID])
            ->assertOk()->assertJson(['data' => ['voided' => 2, 'rewound' => true]]);

        $fresh = $batch->fresh();
        $this->assertSame(['receiving', 'in_process', self::UID], [$fresh->process_stage, $fresh->status, $fresh->rfid_tag]);

        // Pengujian diulang dengan tag yang SAMA — tanpa ganti tag, tanpa menunggu jendela cache.
        $this->scan('in', 'wet_process')->assertOk()->assertJsonPath('data.transition_state', 'moved');
        $this->assertSame('wet_process', $batch->fresh()->process_stage);
    }

    #[Test]
    public function detail_batch_menyembunyikan_checkpoint_yang_dibatalkan_dan_melaporkan_jumlahnya(): void
    {
        $this->mode('wet_process', 'scan_to_receive');
        $batch = $this->batch();
        $this->register($batch);
        $this->scan('in', 'wet_process')->assertOk();   // 2 checkpoint: audit scan + serah-terima otomatis

        $stages = fn () => $this->actingAs($this->admin)->get('/company/batches/'.$batch->id)->assertOk();
        $stages()->assertInertia(fn (Assert $p) => $p->component('Company/Batches/Show')
            ->has('batch.checkpoints', 3)   // registrasi + audit scan + serah-terima
            ->where('batch.voided_checkpoints', 0));

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => self::UID])->assertOk();

        $stages()->assertInertia(fn (Assert $p) => $p->component('Company/Batches/Show')
            ->has('batch.checkpoints', 1)   // hanya registrasi: tag memang masih terpasang
            ->where('batch.voided_checkpoints', 2)
            ->has('timeline', 2));          // "Batch Dibuat" + registrasi
    }

    #[Test]
    public function reset_uji_ditolak_bila_posisi_awal_tak_tercatat_atau_ada_pekerjaan_manual(): void
    {
        $this->mode('wet_process', 'scan_to_receive');

        // (1) Tag didaftarkan sebelum fitur ini: tidak ada batch_snapshot, tetapi sudah ada serah-terima otomatis.
        $old = $this->batch();
        $this->register($old);
        $this->scan('in', 'wet_process')->assertOk();
        $reg = Checkpoint::where('batch_id', $old->id)->where('rfid_scan_code', self::UID)->orderBy('id')->firstOrFail();
        $reg->checkpoint_data = array_diff_key($reg->checkpoint_data, ['batch_snapshot' => 1]);
        $reg->save();

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => self::UID])
            ->assertStatus(422)->assertJson(['code' => 'reset_no_baseline']);
        $this->assertSame('wet_process', $old->fresh()->process_stage, 'ditolak = tidak ada yang diubah');
        $this->assertSame(0, Checkpoint::where('batch_id', $old->id)->whereNotNull('voided_at')->count());

        // (2) Setelah registrasi ada pekerjaan manual (checkpoint non-scan): jangan menimpanya.
        $manual = $this->batch();
        $this->register($manual, 'AA11BB01');
        $this->scan('in', 'wet_process', 'AA11BB01')->assertOk();
        Checkpoint::create(['batch_id' => $manual->id, 'checkpoint_stage' => 'lab_sample_taken', 'recorded_at' => now()]);

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB01'])
            ->assertStatus(422)->assertJson(['code' => 'reset_has_manual_work']);
        $this->assertSame('wet_process', $manual->fresh()->process_stage);

        // Lepas tag tetap tersedia sebagai jalur aman.
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/release', ['uid' => 'AA11BB01', 'reason' => 'other'])->assertOk();
    }

    #[Test]
    public function reset_uji_dijaga_izin_administrator_flag_batch_tertutup_dan_isolasi_tenant(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AA11BB02']);

        $this->actingAs($this->wet)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB02'])->assertStatus(403);
        $this->actingAs($this->auditor)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB02'])->assertStatus(403);

        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB03'])
            ->assertStatus(422)->assertJson(['code' => 'tag_free']);

        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $this->batch(['company_id' => $other->id, 'rfid_tag' => 'AA11BB04']);
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB04'])
            ->assertStatus(422)->assertJson(['code' => 'tag_free']);

        $batch->update(['status' => 'exported']);
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB02'])
            ->assertStatus(422)->assertJson(['code' => 'reset_batch_closed']);
        $batch->update(['status' => 'in_process']);

        config(['rfid.test_reset_enabled' => false]);
        $this->actingAs($this->admin)->postJson(self::BASE.'/tags/reset', ['uid' => 'AA11BB02'])
            ->assertStatus(403)->assertJson(['code' => 'reset_disabled']);
        $this->actingAs($this->admin)->getJson(self::BASE.'/tags/lookup?uid=AA11BB02')->assertJsonPath('data.reset.enabled', false);
        $this->assertDatabaseMissing('rfid_tag_events', ['event' => 'reset']);
    }

    // ─── Mode scan_full + transition_state ────────────────────────────────

    #[Test]
    public function preset_beberapa_tahap_dan_mode_scan_full_hanya_oleh_administrator(): void
    {
        $this->actingAs($this->wet)->postJson(self::BASE.'/authorization-modes', ['stages' => ['wet_process'], 'mode' => 'scan_full'])->assertStatus(403);
        $this->actingAs($this->admin)->postJson(self::BASE.'/authorization-modes', ['stages' => ['wet_process', 'dry_process'], 'mode' => 'bukan_mode'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson(self::BASE.'/authorization-modes', ['stages' => ['wet_process', 'tahap_aneh'], 'mode' => 'scan_full'])->assertStatus(422);
        $this->assertDatabaseCount('rfid_authorization_modes', 0);   // preset yang tidak valid tidak menulis apa pun

        $this->actingAs($this->admin)->postJson(self::BASE.'/authorization-modes', ['stages' => ['wet_process', 'dry_process'], 'mode' => 'scan_full'])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('rfid_authorization_modes', ['company_id' => $this->company->id, 'stage' => 'wet_process', 'mode' => 'scan_full']);
        $this->assertDatabaseHas('rfid_authorization_modes', ['company_id' => $this->company->id, 'stage' => 'dry_process', 'mode' => 'scan_full']);

        // Kontrak lama (satu `stage`) tetap berjalan.
        $this->actingAs($this->admin)->postJson(self::BASE.'/authorization-modes', ['stage' => 'warehouse', 'mode' => 'scan_to_receive'])->assertOk();
        $this->assertDatabaseHas('rfid_authorization_modes', ['stage' => 'warehouse', 'mode' => 'scan_to_receive']);
    }

    #[Test]
    public function scan_full_menerima_di_scan_in_dan_mengirim_di_scan_out_dengan_transition_state_jelas(): void
    {
        $this->mode('wet_process', 'scan_full');
        $this->mode('dry_process', 'scan_full');
        $batch = $this->batch(['process_stage' => 'wet_process', 'status' => 'in_process', 'rfid_tag' => self::UID]);

        $this->scan('out', 'wet_process')->assertOk()->assertJsonPath('data.transition_state', 'moved');
        $this->assertSame(['wet_process', 'in_transit'], [$batch->fresh()->process_stage, $batch->fresh()->status]);

        $this->travel(10)->seconds();   // di luar jendela dedupe
        $this->scan('out', 'wet_process')->assertOk()
            ->assertJsonPath('data.transition_success', false)
            ->assertJsonPath('data.transition_state', 'already_done');

        $this->scan('in', 'dry_process')->assertOk()->assertJsonPath('data.transition_state', 'moved');
        $this->assertSame(['dry_process', 'in_process'], [$batch->fresh()->process_stage, $batch->fresh()->status]);

        $this->travel(10)->seconds();
        $this->scan('in', 'dry_process')->assertOk()->assertJsonPath('data.transition_state', 'already_done');
    }
}
