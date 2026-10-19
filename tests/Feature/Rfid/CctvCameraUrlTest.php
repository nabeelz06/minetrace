<?php

namespace Tests\Feature\Rfid;

use App\Jobs\CaptureCctvSnapshotJob;
use App\Models\Company;
use App\Models\RfidReader;
use App\Models\User;
use Database\Seeders\Core\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * camera_url: override kamera per-reader di atas CctvCamera (stage-based) — lihat migrasi
 * add_camera_url_to_rfid_readers_table dan CaptureCctvSnapshotJob::resolveCamera().
 */
class CctvCameraUrlTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/company/rfid';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@ptimah.co.id')->firstOrFail();
        Company::where('code', 'TIMAH')->firstOrFail();
    }

    #[Test]
    public function camera_url_tersimpan_saat_buat_dan_ubah_reader(): void
    {
        $res = $this->actingAs($this->admin)->postJson(self::BASE.'/readers', [
            'code' => 'GATE-CAM', 'name' => 'Gate Cam', 'direction' => 'in', 'device_type' => 'other',
            'camera_url' => 'rtsp://192.168.1.50:554/stream1',
        ])->assertStatus(201);

        $id = $res->json('data.reader.id');
        $this->assertSame('rtsp://192.168.1.50:554/stream1', $res->json('data.reader.camera_url'));
        $this->assertDatabaseHas('rfid_readers', ['id' => $id, 'camera_url' => 'rtsp://192.168.1.50:554/stream1']);

        $res2 = $this->actingAs($this->admin)->patchJson(self::BASE."/readers/{$id}", ['camera_url' => null])->assertOk();
        $this->assertNull($res2->json('data.reader.camera_url'));
    }

    #[Test]
    public function camera_url_ditolak_tanpa_skema_rtsp_atau_http(): void
    {
        $this->actingAs($this->admin)->postJson(self::BASE.'/readers', [
            'code' => 'GATE-BAD', 'name' => 'Gate Bad', 'direction' => 'in', 'device_type' => 'other',
            'camera_url' => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors('camera_url');
    }

    #[Test]
    public function job_capture_memakai_camera_url_reader_sebelum_cctvcamera_stage(): void
    {
        $reader = RfidReader::create([
            'company_id' => $this->admin->company_id, 'code' => 'GATE-OVR', 'name' => 'Gate Override',
            'direction' => 'in', 'device_type' => 'other', 'camera_url' => 'rtsp://10.0.0.9:554/cam',
            'key_prefix' => 'ovr', 'key_hash' => 'x',
        ]);

        $job = new CaptureCctvSnapshotJob(checkpointId: null, companyId: $this->admin->company_id, readLogId: null, readerId: $reader->id);
        $camera = (new \ReflectionMethod($job, 'resolveCamera'))->invoke($job);

        $this->assertNotNull($camera);
        $this->assertSame('rtsp://10.0.0.9:554/cam', $camera->effective_rtsp_url);
    }
}
