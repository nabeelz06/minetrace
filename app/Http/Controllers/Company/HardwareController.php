<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\RfidReader;
use App\Services\Hardware\RfidAuthorizationService;
use App\Services\Hardware\RfidDeviceService;
use App\Services\Hardware\RfidService;
use App\Support\ProcessFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HardwareController — halaman RFID (satu halaman, dua presentasi).
 *
 *   /company/hardware/rfid        → tata letak penuh (desktop/tablet)
 *   /company/hardware/rfid/field  → tata letak lapangan (kontrol besar, ponsel/tablet)
 *
 * Keduanya memakai komponen Vue yang sama (Company/Rfid/Index) dengan prop `field`; logika & data identik.
 * Tidak ada lagi pengalihan otomatis berbasis User-Agent (iPadOS modern mengirim UA desktop): pilihan
 * presentasi eksplisit lewat tautan "Mode Lapangan" / "Mode Penuh", dan tata letak responsif.
 */
class HardwareController extends Controller
{
    public function __construct(
        private RfidService              $rfid,
        private RfidAuthorizationService $authorization,
        private RfidDeviceService        $devices,
    ) {}

    public function rfidPanel(Request $request): Response
    {
        return $this->render($request, field: false);
    }

    public function rfidFieldMode(Request $request): Response
    {
        return $this->render($request, field: true);
    }

    private function render(Request $request, bool $field): Response
    {
        Gate::authorize('rfid.view');

        $user      = Auth::user();
        $companyId = (int) $user->company_id;

        $readers = RfidReader::where('company_id', $companyId)->orderBy('code')->get()
            ->map(fn (RfidReader $r) => $this->devices->present($r))->all();

        $stages = [];
        foreach (ProcessFlow::rfidFlow() as $key => $cfg) {
            $stages[] = ['key' => $key, 'label' => $cfg['label'], 'label_en' => $cfg['label_en'], 'icon' => ProcessFlow::get($key)['icon']];
        }

        return Inertia::render('Company/Rfid/Index', [
            'field'   => $field,
            'initial' => [
                'view'           => $request->query('view'),                  // register | in | out | history | logs | devices
                'register_batch' => $request->integer('register_batch') ?: null,
                'uid'            => $request->query('uid'),
            ],
            'rfid' => [
                'can' => [
                    'operate' => Gate::allows('rfid.operate'),
                    'manage'  => Gate::allows('rfid.manage'),
                ],
                'company'             => ['id' => $companyId, 'code' => $user->company?->code, 'name' => $user->company?->name],
                'readers'             => $readers,
                'stages'              => $stages,
                'authorization_modes' => $this->authorization->getAllModes($companyId),
                'valid_modes'         => $this->authorization->getValidModes(),
                'simulation_enabled'  => (bool) config('rfid.simulation_enabled'),
                'uhf_desktop_baud'    => (int) config('rfid.uhf.desktop_baud', 9600),
                'registration_ttl'    => (int) config('rfid.registration_ttl'),
                'offline_after'       => (int) config('rfid.offline_after_seconds'),
                'esp32_ip'            => $this->rfid->getEsp32Ip($companyId),
            ],
        ]);
    }
}
