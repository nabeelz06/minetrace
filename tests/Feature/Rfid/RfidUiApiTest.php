<?php

namespace Tests\Feature\Rfid;

use App\Models\Batch;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\ProductCode;
use App\Models\RfidReader;
use App\Models\RfidTagEvent;
use App\Models\User;
use App\Services\Hardware\RfidDeviceService;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API UI RFID baru (reader, registrasi paralel, umpan scan, void, siklus hidup tag),
 * identitas perangkat (X-Device-Key), isolasi tenant, dedupe & idempotensi.
 */
class RfidUiApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private User $wet;
    private User $auditor;
    private int $productId;

    private const BASE = '/api/v1/company/rfid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        putenv('RFID_API_TOKEN=');
        putenv('RFID_DEFAULT_COMPANY_CODE=');
        config(['rfid.legacy_global_tag_fallback' => true, 'rfid.legacy_anonymous' => true]);

        $this->company   = Company::where('code', 'TIMAH')->firstOrFail();
        $this->admin     = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
        $this->wet       = User::where('email', 'wet.mtk@ptimah.co.id')->firstOrFail();
        $this->auditor   = User::where('email', 'audit@ptimah.co.id')->firstOrFail();
        $this->productId = ProductCode::where('company_id', $this->company->id)->firstOrFail()->id;
    }

    private function batch(array $attrs = []): Batch
    {
        return Batch::factory()->create($attrs + [
            'company_id' => $this->company->id, 'product_code_id' => $this->productId,
            'process_stage' => 'wet_process', 'status' => 'in_process', 'rfid_tag' => null,
        ]);
    }

    private function reader(array $attrs = []): array
    {
        [$reader, $key] = app(RfidDeviceService::class)->create($this->company->id, $attrs + [
            'code' => 'WET-IN-01', 'name' => 'Wet Masuk', 'stage' => 'wet_process', 'direction' => 'in',
        ], $this->admin->id);

        return [$reader, $key];
    }

    // ─── Otorisasi per peran ──────────────────────────────────────────────

    #[Test]
    public function auditor_hanya_melihat_tidak_bisa_mengubah(): void
    {
        $batch = $this->batch();

        $this->actingAs($this->auditor)->getJson(self::BASE . '/readers')->assertOk();
        $this->actingAs($this->auditor)->getJson(self::BASE . '/scans')->assertOk();
        $this->actingAs($this->auditor)->getJson(self::BASE . '/overview')->assertOk();

        $this->actingAs($this->auditor)->postJson(self::BASE . '/registration', ['batch_id' => $batch->id])
            ->assertStatus(403)->assertJson(['code' => 'forbidden']);
        $this->actingAs($this->auditor)->postJson(self::BASE . '/scans', ['uid' => 'A1B2C3D4', 'direction' => 'in'])->assertStatus(403);
        $this->actingAs($this->auditor)->postJson(self::BASE . '/readers', ['code' => 'X1', 'name' => 'X', 'direction' => 'in', 'device_type' => 'esp32_rc522'])->assertStatus(403);
    }

    #[Test]
    public function operator_tidak_bisa_kelola_reader_tapi_bisa_registrasi(): void
    {
        $batch = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . '/readers', ['code' => 'X1', 'name' => 'X', 'direction' => 'in', 'device_type' => 'esp32_rc522'])
            ->assertStatus(403);
        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $batch->id])
            ->assertStatus(201)->assertJson(['code' => 'registration_started']);
    }

    #[Test]
    public function pengguna_tanpa_perusahaan_ditolak_bukan_jatuh_ke_perusahaan_1(): void
    {
        $super = User::where('email', 'superadmin@minetra.id')->firstOrFail();

        $this->actingAs($super)->getJson(self::BASE . '/status')->assertStatus(403);
        $this->actingAs($super)->getJson(self::BASE . '/batches')->assertStatus(403);
        $this->actingAs($super)->getJson(self::BASE . '/readers')->assertStatus(403);
    }

    // ─── Reader & kunci perangkat ─────────────────────────────────────────

    #[Test]
    public function admin_membuat_reader_kunci_tampil_sekali_dan_hanya_hash_disimpan(): void
    {
        $res = $this->actingAs($this->admin)->postJson(self::BASE . '/readers', [
            'code' => 'wet-in-01', 'name' => 'Wet Masuk', 'stage' => 'wet_process', 'direction' => 'in', 'device_type' => 'esp32_rc522',
        ])->assertStatus(201)->assertJson(['code' => 'reader_created']);

        $key = $res->json('data.device_key');
        $this->assertStringStartsWith('mtr_', $key);

        $reader = RfidReader::firstOrFail();
        $this->assertSame('WET-IN-01', $reader->code);
        $this->assertSame(hash('sha256', $key), $reader->key_hash);
        $this->assertStringNotContainsString($key, json_encode(
            $this->actingAs($this->admin)->getJson(self::BASE . '/readers')->json()
        ));
    }

    #[Test]
    public function kode_reader_unik_per_perusahaan(): void
    {
        $this->reader();
        $this->actingAs($this->admin)->postJson(self::BASE . '/readers', [
            'code' => 'WET-IN-01', 'name' => 'Dobel', 'direction' => 'in', 'device_type' => 'esp32_rc522',
        ])->assertStatus(422);
    }

    #[Test]
    public function rotasi_kunci_mematikan_kunci_lama(): void
    {
        [$reader, $old] = $this->reader();
        $new = $this->actingAs($this->admin)->postJson(self::BASE . "/readers/{$reader->id}/rotate-key")->assertOk()->json('data.device_key');

        $this->postJson('/api/rfid/heartbeat', [], ['X-Device-Key' => $old])->assertStatus(401);
        $this->postJson('/api/rfid/heartbeat', [], ['X-Device-Key' => $new])->assertOk();
    }

    #[Test]
    public function heartbeat_memperbarui_status_dan_mengembalikan_waktu_server(): void
    {
        [$reader, $key] = $this->reader();

        $res = $this->postJson('/api/rfid/heartbeat', [
            'firmware' => '1.2.0', 'uptime_s' => 120, 'rssi' => -61, 'device_time' => now()->timestamp - 5,
        ], ['X-Device-Key' => $key])->assertOk();

        $this->assertSame('success', $res->json('status'));
        $this->assertArrayHasKey('server_time', $res->json());
        $reader->refresh();
        $this->assertSame('1.2.0', $reader->firmware_version);
        $this->assertSame('online', $reader->connectionState());
        $this->assertTrue($reader->last_status['clock_ok']);

        $this->postJson('/api/rfid/heartbeat')->assertStatus(401);   // tanpa kunci
    }

    #[Test]
    public function reader_nonaktif_403_dan_kunci_salah_401(): void
    {
        [$reader, $key] = $this->reader();
        $reader->update(['is_active' => false]);

        $this->postJson('/api/rfid/login', ['uid' => 'A1B2C3D4'], ['X-Device-Key' => $key])->assertStatus(403);
        $this->postJson('/api/rfid/login', ['uid' => 'A1B2C3D4'], ['X-Device-Key' => 'mtr_salah'])->assertStatus(401);
    }

    // ─── Registrasi ───────────────────────────────────────────────────────

    #[Test]
    public function registrasi_paralel_dua_stasiun_tidak_saling_menimpa(): void
    {
        [$ra, $ka] = $this->reader(['code' => 'A-01', 'name' => 'A']);
        [$rb, $kb] = $this->reader(['code' => 'B-01', 'name' => 'B']);
        $a = $this->batch();
        $b = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $a->id, 'reader_id' => $ra->id])->assertStatus(201);
        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $b->id, 'reader_id' => $rb->id])->assertStatus(201);

        // Tempel di urutan terbalik: tiap reader tetap mengikat batch miliknya.
        $this->postJson('/api/rfid/register', ['uid' => 'BBBB0002'], ['X-Device-Key' => $kb])->assertOk();
        $this->postJson('/api/rfid/register', ['uid' => 'AAAA0001'], ['X-Device-Key' => $ka])->assertOk();

        $this->assertSame('AAAA0001', $a->fresh()->rfid_tag);
        $this->assertSame('BBBB0002', $b->fresh()->rfid_tag);
        $this->assertDatabaseHas('rfid_tag_events', ['batch_id' => $a->id, 'event' => 'registered', 'uid' => 'AAAA0001']);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $a->id, 'reader_id' => $ra->id, 'company_id' => $this->company->id]);
    }

    #[Test]
    public function batch_tidak_bisa_didaftarkan_di_dua_reader_sekaligus(): void
    {
        [$ra] = $this->reader(['code' => 'A-01', 'name' => 'A']);
        [$rb] = $this->reader(['code' => 'B-01', 'name' => 'B']);
        $batch = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $batch->id, 'reader_id' => $ra->id])->assertStatus(201);
        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $batch->id, 'reader_id' => $rb->id])
            ->assertStatus(422)->assertJson(['code' => 'batch_has_session']);
    }

    #[Test]
    public function registrasi_manual_usb_langsung_mengikat_tag_dan_menolak_duplikat(): void
    {
        $a = $this->batch();
        $b = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $a->id, 'uid' => 'a1:b2:c3:d4'])
            ->assertOk()->assertJson(['code' => 'tag_registered', 'data' => ['uid' => 'A1B2C3D4']]);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $a->id, 'rfid_origin' => 'manual', 'recorded_by' => $this->wet->id]);

        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $b->id, 'uid' => 'A1B2C3D4'])
            ->assertStatus(422)->assertJson(['code' => 'tag_in_use']);
        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $a->id, 'uid' => 'FFFF0000'])
            ->assertStatus(422)->assertJson(['code' => 'batch_already_tagged']);
    }

    #[Test]
    public function status_registrasi_waiting_completed_expired(): void
    {
        [$reader, $key] = $this->reader();
        $batch = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $batch->id, 'reader_id' => $reader->id])->assertStatus(201);
        $this->actingAs($this->wet)->getJson(self::BASE . "/batches/{$batch->id}/registration")->assertJson(['data' => ['state' => 'waiting']]);

        $this->postJson('/api/rfid/register', ['uid' => 'CAFE0001'], ['X-Device-Key' => $key])->assertOk();
        $this->actingAs($this->wet)->getJson(self::BASE . "/batches/{$batch->id}/registration")
            ->assertJson(['data' => ['state' => 'completed', 'uid' => 'CAFE0001']]);

        $other = $this->batch();
        $this->actingAs($this->wet)->getJson(self::BASE . "/batches/{$other->id}/registration")->assertJson(['data' => ['state' => 'expired']]);
    }

    // ─── Isolasi tenant ───────────────────────────────────────────────────

    #[Test]
    public function perangkat_ber_identitas_tidak_pernah_melihat_tag_perusahaan_lain(): void
    {
        $otherCompany = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $foreign = $this->batch(['company_id' => $otherCompany->id, 'rfid_tag' => 'FEED0001']);

        // X-Company-Code milik TIMAH → tag LAIN dianggap tidak dikenal
        $this->postJson('/api/rfid/login', ['uid' => 'FEED0001', 'stage' => 'wet_process'], ['X-Company-Code' => 'TIMAH'])
            ->assertStatus(422)->assertJson(['message' => 'Tag tidak dikenal']);

        // Reader terdaftar TIMAH: sama
        [, $key] = $this->reader();
        $this->postJson('/api/rfid/login', ['uid' => 'FEED0001'], ['X-Device-Key' => $key])->assertStatus(422)->assertJson(['message' => 'Tag tidak dikenal']);

        $this->assertDatabaseMissing('checkpoints', ['batch_id' => $foreign->id, 'rfid_scan_code' => 'FEED0001']);
    }

    #[Test]
    public function perangkat_anonim_legacy_tetap_dilayani_dan_bisa_dimatikan_lewat_flag(): void
    {
        $batch = $this->batch(['rfid_tag' => 'FEED0002']);   // tanpa header: default company = id 1 (BIM) → hanya fallback global yang menemukan

        $this->postJson('/api/rfid/login', ['uid' => 'FEED0002', 'stage' => 'wet_process'])->assertOk()->assertJson(['message' => 'Scan IN OK']);

        config(['rfid.legacy_global_tag_fallback' => false]);
        Cache::flush();
        $this->postJson('/api/rfid/login', ['uid' => 'FEED0002', 'stage' => 'wet_process'])->assertStatus(422);

        config(['rfid.legacy_anonymous' => false]);
        $this->postJson('/api/rfid/login', ['uid' => 'FEED0002'])->assertStatus(401);
    }

    #[Test]
    public function sesi_registrasi_perusahaan_lain_tidak_bisa_diselesaikan_perangkat_tenant_ini(): void
    {
        $otherCompany = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $foreign = $this->batch(['company_id' => $otherCompany->id]);
        app(\App\Services\Hardware\RfidService::class)->setPendingBatch($foreign->id, $otherCompany->id, 'Admin LAIN');

        // Perangkat ber-X-Company-Code TIMAH: tidak ada sesi milik TIMAH → tidak boleh memakai sesi LAIN.
        $this->postJson('/api/rfid/register', ['uid' => 'ABCD1234'], ['X-Company-Code' => 'TIMAH'])
            ->assertStatus(422)->assertJson(['message' => 'Tidak ada sesi']);
        $this->assertNull($foreign->fresh()->rfid_tag);
    }

    // ─── Scan: arah reader, dedupe, idempotensi ───────────────────────────

    #[Test]
    public function reader_arah_tetap_menolak_arah_yang_salah_dan_memakai_tahap_reader(): void
    {
        [, $key] = $this->reader();   // direction=in, stage=wet_process
        $this->batch(['rfid_tag' => 'AAAA1111']);

        $this->postJson('/api/rfid/logout', ['uid' => 'AAAA1111'], ['X-Device-Key' => $key])
            ->assertStatus(422)->assertJson(['name' => 'Arah Salah']);

        $this->postJson('/api/rfid/login', ['uid' => 'AAAA1111'], ['X-Device-Key' => $key])
            ->assertOk()->assertJson(['scan_stage' => 'wet_process', 'message' => 'Scan IN OK']);
        $this->assertDatabaseHas('checkpoints', ['rfid_scan_code' => 'AAAA1111', 'scan_stage' => 'wet_process', 'scan_direction' => 'in']);
    }

    #[Test]
    public function bacaan_berulang_dalam_jendela_dedupe_tidak_menggandakan_checkpoint(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AAAA2222']);
        $h = ['X-Company-Code' => 'TIMAH'];

        $first  = $this->postJson('/api/rfid/login', ['uid' => 'AAAA2222', 'stage' => 'wet_process'], $h)->assertOk();
        $second = $this->postJson('/api/rfid/login', ['uid' => 'AAAA2222', 'stage' => 'wet_process'], $h)->assertOk();

        $this->assertSame($first->getContent(), $second->getContent(), 'muatan LCD harus identik');
        $second->assertHeader('X-Rfid-Duplicate', '1');
        $this->assertFalse($first->headers->has('X-Rfid-Duplicate'));
        $this->assertSame(1, Checkpoint::where('batch_id', $batch->id)->where('rfid_scan_code', 'AAAA2222')->count());
        Queue::assertPushed(\App\Jobs\CaptureCctvSnapshotJob::class, 1);

        $this->travel(config('rfid.dedupe_seconds') + 1)->seconds();
        $this->postJson('/api/rfid/login', ['uid' => 'AAAA2222', 'stage' => 'wet_process'], $h)->assertOk();
        $this->assertSame(2, Checkpoint::where('batch_id', $batch->id)->where('rfid_scan_code', 'AAAA2222')->count());
    }

    #[Test]
    public function event_id_yang_sama_idempoten_walau_di_luar_jendela_dedupe(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AAAA3333']);
        $h = ['X-Company-Code' => 'TIMAH', 'X-Event-Id' => 'evt-0001-abcdef'];

        $this->postJson('/api/rfid/login', ['uid' => 'AAAA3333', 'stage' => 'wet_process'], $h)->assertOk();
        $this->travel(60)->seconds();
        $this->postJson('/api/rfid/login', ['uid' => 'AAAA3333', 'stage' => 'wet_process'], $h)->assertOk()->assertHeader('X-Rfid-Duplicate', '1');

        $this->assertSame(1, Checkpoint::where('batch_id', $batch->id)->where('rfid_scan_code', 'AAAA3333')->count());
        $this->assertDatabaseHas('checkpoints', ['event_id' => 'evt-0001-abcdef', 'company_id' => $this->company->id]);
    }

    #[Test]
    public function scan_manual_ui_bertanda_manual_dan_simulasi_bertanda_simulation(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AAAA4444']);

        $this->actingAs($this->wet)->postJson(self::BASE . '/scans', ['uid' => 'AAAA4444', 'direction' => 'in', 'stage' => 'wet_process'])
            ->assertOk()->assertJson(['code' => 'scan_recorded']);
        $this->travel(5)->seconds();
        $this->actingAs($this->wet)->postJson(self::BASE . '/scans', ['uid' => 'AAAA4444', 'direction' => 'out', 'stage' => 'wet_process', 'simulate' => true])->assertOk();

        $this->assertDatabaseHas('checkpoints', ['batch_id' => $batch->id, 'rfid_origin' => 'manual', 'scan_direction' => 'in']);
        $this->assertDatabaseHas('checkpoints', ['batch_id' => $batch->id, 'rfid_origin' => 'simulation', 'scan_direction' => 'out']);

        config(['rfid.simulation_enabled' => false]);
        $this->actingAs($this->wet)->postJson(self::BASE . '/scans', ['uid' => 'AAAA4444', 'direction' => 'out', 'simulate' => true])
            ->assertStatus(403)->assertJson(['code' => 'simulation_disabled']);

        $this->actingAs($this->wet)->postJson(self::BASE . '/scans', ['uid' => 'ZZZZ9999', 'direction' => 'in'])
            ->assertStatus(422)->assertJson(['code' => 'tag_unknown']);
    }

    // ─── Umpan, void, siklus hidup tag ────────────────────────────────────

    #[Test]
    public function umpan_scan_memfilter_dan_mendukung_kursor(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AAAA5555']);
        $h = ['X-Company-Code' => 'TIMAH'];
        $this->postJson('/api/rfid/login', ['uid' => 'AAAA5555', 'stage' => 'wet_process'], $h + ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->travel(5)->seconds();
        $this->postJson('/api/rfid/logout', ['uid' => 'AAAA5555', 'stage' => 'wet_process'], $h)->assertOk();

        $all = $this->actingAs($this->auditor)->getJson(self::BASE . '/scans')->assertOk()->json('data');
        $this->assertCount(2, $all);
        $this->assertSame('out', $all[0]['direction']);

        $sim = $this->actingAs($this->auditor)->getJson(self::BASE . '/scans?origin=simulation')->json('data');
        $this->assertCount(1, $sim);
        $this->assertSame('in', $sim[0]['direction']);

        $newer = $this->actingAs($this->auditor)->getJson(self::BASE . '/scans?since_id=' . $all[1]['id'])->json('data');
        $this->assertCount(1, $newer);
        $this->assertSame('AAAA5555', $newer[0]['uid']);
    }

    #[Test]
    public function umpan_scan_tidak_menampilkan_data_perusahaan_lain(): void
    {
        $other = Company::factory()->create(['is_regulator' => false, 'code' => 'LAIN']);
        $foreign = $this->batch(['company_id' => $other->id, 'rfid_tag' => 'FEED0009']);
        $this->postJson('/api/rfid/login', ['uid' => 'FEED0009', 'stage' => 'wet_process'], ['X-Company-Code' => 'LAIN'])->assertOk();

        $this->assertCount(0, $this->actingAs($this->admin)->getJson(self::BASE . '/scans')->json('data'));
        $this->assertGreaterThan(0, Checkpoint::where('batch_id', $foreign->id)->count());
    }

    #[Test]
    public function void_scan_menandai_bukan_menghapus_dan_menolak_ulang(): void
    {
        $batch = $this->batch(['rfid_tag' => 'AAAA6666']);
        $this->postJson('/api/rfid/login', ['uid' => 'AAAA6666', 'stage' => 'wet_process'], ['X-Company-Code' => 'TIMAH'])->assertOk();
        $cp = Checkpoint::where('batch_id', $batch->id)->where('scan_direction', 'in')->firstOrFail();

        $this->actingAs($this->wet)->postJson(self::BASE . "/scans/{$cp->id}/void", ['reason' => 'x'])->assertStatus(422);
        $this->actingAs($this->wet)->postJson(self::BASE . "/scans/{$cp->id}/void", ['reason' => 'Karung salah jalur'])
            ->assertOk()->assertJson(['code' => 'scan_voided', 'data' => ['is_voided' => true]]);
        $this->assertDatabaseHas('checkpoints', ['id' => $cp->id, 'void_reason' => 'Karung salah jalur', 'voided_by' => $this->wet->id]);
        $this->actingAs($this->wet)->postJson(self::BASE . "/scans/{$cp->id}/void", ['reason' => 'sekali lagi'])
            ->assertStatus(422)->assertJson(['code' => 'void_already']);

        $this->actingAs($this->auditor)->postJson(self::BASE . "/scans/{$cp->id}/void", ['reason' => 'tidak boleh'])->assertStatus(403);
    }

    #[Test]
    public function lepas_tag_beralasan_membebaskan_uid_dan_tercatat(): void
    {
        $a = $this->batch(['rfid_tag' => 'DEAD0001']);
        $b = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . "/batches/{$a->id}/release-tag", ['reason' => 'bogus'])->assertStatus(422);
        $this->actingAs($this->wet)->postJson(self::BASE . "/batches/{$a->id}/release-tag", ['reason' => 'damaged', 'note' => 'Tag patah'])
            ->assertOk()->assertJson(['code' => 'tag_released', 'data' => ['released_uid' => 'DEAD0001']]);
        $this->assertNull($a->fresh()->rfid_tag);
        $this->assertDatabaseHas('rfid_tag_events', ['batch_id' => $a->id, 'event' => 'released', 'reason' => 'damaged', 'uid' => 'DEAD0001']);

        $this->actingAs($this->wet)->postJson(self::BASE . "/batches/{$a->id}/release-tag", ['reason' => 'lost'])
            ->assertStatus(422)->assertJson(['code' => 'tag_none']);

        // UID bebas dipakai batch baru setelah dilepas.
        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $b->id, 'uid' => 'DEAD0001'])->assertOk();
        $this->assertSame('DEAD0001', $b->fresh()->rfid_tag);

        $hist = $this->actingAs($this->auditor)->getJson(self::BASE . "/batches/{$a->id}/tag-history")->json('data');
        $this->assertSame('released', $hist[0]['event']);
        // Riwayat append-only: 1x released (batch lama) + 1x registered (batch baru).
        $this->assertSame(['registered', 'released'], RfidTagEvent::where('uid', 'DEAD0001')->orderBy('event')->pluck('event')->all());
    }

    #[Test]
    public function tag_batch_yang_dihapus_lunak_tidak_menyebabkan_error_500(): void
    {
        $gone = $this->batch(['rfid_tag' => 'DEAD0002']);
        $gone->delete();   // soft delete; UNIQUE DB masih memegang UID
        $new = $this->batch();

        $this->actingAs($this->wet)->postJson(self::BASE . '/registration', ['batch_id' => $new->id, 'uid' => 'DEAD0002'])
            ->assertStatus(422)->assertJson(['code' => 'tag_in_use']);
    }
}
