<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchPool;
use App\Services\Process\PoolService;
use App\Support\ProcessFlow;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Aksi web penghimpunan Wet/Dry: drum keluar (tanpa UR4), timbang drum, selesai himpunan (Dry), batalkan drum.
 * Aturan ada di PoolService; controller ini hanya otorisasi peran tahap + validasi input.
 */
class PoolController extends Controller
{
    public function __construct(private readonly PoolService $pools) {}

    /** Ringkasan himpunan untuk prop `pool` halaman tahap Wet/Dry. */
    public static function summary(int $companyId, string $stage): ?array
    {
        if (! PoolService::isPoolStage($stage)) {
            return null;
        }
        $tz = 'Asia/Jakarta';

        $pools = BatchPool::with(['inputs.batch.productCode'])
            ->where('company_id', $companyId)->where('stage', $stage)
            ->where('status', '!=', BatchPool::STATUS_CLOSED)->get()->keyBy('status');

        $fmt = fn (?BatchPool $p) => $p ? [
            'id'           => $p->id,
            'status'       => $p->status,
            'opened_at'    => $p->opened_at?->timezone($tz)->format('d/m/Y H:i') . ' WIB',
            'total_weight' => round((float) $p->inputs->sum('weight_at_join'), 2),
            'inputs'       => $p->inputs->sortBy('joined_at')->values()->map(fn ($i) => [
                'batch_id'   => $i->batch_id,
                'batch_code' => $i->batch?->batch_code,
                'product'    => $i->batch?->productCode?->code,
                'weight'     => $i->weight_at_join,
                'old_tag'    => $i->released_rfid_tag,
                'joined_at'  => $i->joined_at?->timezone($tz)->format('d/m H:i'),
            ])->all(),
        ] : null;

        $drums = Batch::with('productCode')->where('company_id', $companyId)->where('process_stage', $stage)
            ->whereNotNull('pool_id')->where('status', ProcessFlow::STATUS_AWAITING_WEIGHT)
            ->orderBy('id')->get()->map(fn (Batch $d) => [
                'id'         => $d->id,
                'pool_id'    => $d->pool_id,
                'batch_code' => $d->batch_code,
                'rfid_tag'   => $d->rfid_tag,
                'product'    => $d->initial_weight > 0 ? $d->productCode?->code : null,
                'weight'     => $d->initial_weight > 0 ? $d->initial_weight : null,
                'created_at' => Carbon::parse($d->created_at)->timezone($tz)->format('d/m H:i'),
            ])->all();

        return [
            'collecting' => $fmt($pools->get(BatchPool::STATUS_COLLECTING)),
            'outputting' => $fmt($pools->get(BatchPool::STATUS_OUTPUTTING)),
            'drums'      => $drums,
            'max_drums'  => $stage === 'dry_process' ? BatchPool::MAX_DRY_DRUMS : 1,
        ];
    }

    public function drum(Request $request, string $stage): RedirectResponse
    {
        $this->authorizeStage($stage);
        $data = $request->validate(['uid' => 'required|string|max:100']);

        $r = $this->pools->createOutputDrum(Auth::user()->company_id, $stage, $data['uid'], Auth::id(), 'web');

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    /** Receiving & Wet: drum besi ditimbang + dicek kadar Sn, lalu langsung masuk himpunan Wet. */
    public function intake(Request $request, Batch $batch): RedirectResponse
    {
        abort_unless($batch->company_id === Auth::user()->company_id, 404);
        abort_unless(Auth::user()->hasAnyRole(ProcessFlow::roles('receiving')), 403);
        $data = $request->validate([
            'weight'       => 'required|numeric|min:0.001|max:9999999',
            'sn_grade_pct' => 'required|numeric|min:0|max:100',
        ], [
            'sn_grade_pct.required' => 'Kadar Sn wajib diisi.',
        ]);

        try {
            $b = app(\App\Services\Process\IntakeService::class)->record($batch, (float) $data['weight'], (float) $data['sn_grade_pct'], Auth::id());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = "Drum {$b->batch_code} ditimbang " . number_format($b->current_weight, 2) . " kg, kadar Sn {$data['sn_grade_pct']}%, dan masuk himpunan Wet.";

        return back()->with($b->has_discrepancy ? 'warning' : 'success', $b->has_discrepancy ? "⚠ {$msg} Susut melewati ambang — ditandai untuk ditinjau." : $msg);
    }

    public function weigh(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorizeDrum($batch);
        $data = $request->validate([
            'weight'          => 'required|numeric|min:0.001|max:9999999',
            'product_code_id' => 'nullable|integer|exists:product_codes,id',
        ]);

        try {
            $drum = $this->pools->recordDrumWeight($batch, (float) $data['weight'], $data['product_code_id'] ?? null, Auth::id());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = $drum->status === ProcessFlow::STATUS_AFTER_SEND
            ? "Drum {$drum->batch_code} ditimbang " . number_format($drum->initial_weight, 2) . ' kg dan siap dikirim ke Dry Process.'
            : "Drum {$drum->batch_code} ditimbang " . number_format($drum->initial_weight, 2) . ' kg. Tekan "Selesai himpunan" bila semua drum sudah ditimbang.';

        return back()->with('success', $msg);
    }

    public function close(BatchPool $pool): RedirectResponse
    {
        abort_unless($pool->company_id === Auth::user()->company_id, 404);
        $this->authorizeStage($pool->stage);

        try {
            $pool = $this->pools->closeDryPool($pool, Auth::id());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Himpunan selesai. Susut ' . number_format((float) $pool->loss_pct, 2) . '%. Drum siap dikirim ke Gudang.');
    }

    public function cancel(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorizeDrum($batch);
        $data = $request->validate(['reason' => 'required|string|min:3|max:200']);

        try {
            $this->pools->cancelDrum($batch, Auth::id(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Drum {$batch->batch_code} dibatalkan. Batch kembali ke himpunan.");
    }

    private function authorizeStage(string $stage): void
    {
        abort_unless(PoolService::isPoolStage($stage), 404);
        abort_unless(Auth::user()->hasAnyRole(ProcessFlow::roles($stage)), 403);
    }

    private function authorizeDrum(Batch $batch): void
    {
        abort_unless($batch->company_id === Auth::user()->company_id && $batch->pool_id, 404);
        $this->authorizeStage($batch->process_stage);
    }
}
