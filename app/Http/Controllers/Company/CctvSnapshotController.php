<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CctvSnapshot;
use App\Repositories\CctvSnapshotRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CctvSnapshotController — CRUD riwayat capture CCTV (entitas first-class).
 *
 * Data ini bersifat evidentiary untuk audit BIM/ESDM — hapus HANYA soft
 * delete dan WAJIB disertai alasan yang tercatat di activity log
 * (lihat CctvSnapshot::getActivitylogOptions() — kolom deleted_at di-log).
 * Tidak ada endpoint hard-delete.
 */
class CctvSnapshotController extends Controller
{
    public function __construct(
        private CctvSnapshotRepository $repository
    ) {}

    /** GET /company/cctv/snapshots */
    public function index(Request $request): Response
    {
        $companyId = Auth::user()->company_id;

        $filters = $request->only([
            'batch_code', 'stage', 'capture_mode', 'capture_status', 'verified', 'date_from', 'date_to',
        ]);

        return Inertia::render('Company/Cctv/SnapshotHistory', [
            'snapshots' => $this->presentPage($this->repository->paginateForCompany($companyId, $filters)),
            'filters'   => $filters,
            'can_manage' => Auth::user()->hasAnyRole(['company_admin', 'company_auditor']),
            'can_delete' => Auth::user()->hasRole('company_admin'),
        ]);
    }

    /** GET /company/cctv/snapshots/trash */
    public function trashed(Request $request): Response
    {
        abort_unless(Auth::user()->hasRole('company_admin'), 403, 'Hanya Admin Perusahaan yang dapat melihat arsip terhapus.');

        $companyId = Auth::user()->company_id;
        $filters   = $request->only([
            'batch_code', 'stage', 'capture_mode', 'capture_status', 'date_from', 'date_to',
        ]);

        return Inertia::render('Company/Cctv/SnapshotTrash', [
            'snapshots' => $this->presentPage($this->repository->trashedForCompany($companyId, $filters)),
            'filters'   => $filters,
        ]);
    }

    /** PATCH /company/cctv/snapshots/{id}/verify */
    public function verify(Request $request, int $id): RedirectResponse
    {
        abort_unless(
            Auth::user()->hasAnyRole(['company_admin', 'company_auditor']),
            403,
            'Hanya Admin atau Auditor Perusahaan yang dapat memverifikasi capture.'
        );

        $validated = $request->validate([
            'is_verified'   => 'required|boolean',
            'review_notes'  => 'nullable|string|max:1000',
        ]);

        $snapshot = CctvSnapshot::where('company_id', Auth::user()->company_id)->findOrFail($id);

        $snapshot->update([
            'is_verified'  => $validated['is_verified'],
            'verified_by'  => Auth::id(),
            'verified_at'  => now(),
            'review_notes' => $validated['review_notes'] ?? $snapshot->review_notes,
        ]);

        Log::info('CctvSnapshot: capture diverifikasi.', [
            'snapshot_id' => $snapshot->id,
            'verified_by' => Auth::id(),
            'is_verified' => $validated['is_verified'],
        ]);

        return back()->with('success', $validated['is_verified']
            ? 'Capture berhasil diverifikasi.'
            : 'Status verifikasi capture diperbarui.');
    }

    /** DELETE /company/cctv/snapshots/{id} */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        abort_unless(Auth::user()->hasRole('company_admin'), 403, 'Hanya Admin Perusahaan yang dapat menghapus capture.');

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $snapshot = CctvSnapshot::where('company_id', Auth::user()->company_id)->findOrFail($id);

        activity()
            ->causedBy(Auth::user())
            ->performedOn($snapshot)
            ->withProperties(['reason' => $validated['reason']])
            ->log('cctv_snapshot_soft_deleted');

        Log::warning('CctvSnapshot: soft-delete dengan alasan.', [
            'snapshot_id' => $snapshot->id,
            'deleted_by'  => Auth::id(),
            'reason'      => $validated['reason'],
        ]);

        $snapshot->delete();

        return back()->with('success', 'Capture dihapus (soft-delete) dan tercatat di audit log.');
    }

    /** PATCH /company/cctv/snapshots/{id}/restore */
    public function restore(int $id): RedirectResponse
    {
        abort_unless(Auth::user()->hasRole('company_admin'), 403, 'Hanya Admin Perusahaan yang dapat memulihkan capture.');

        $snapshot = CctvSnapshot::onlyTrashed()->where('company_id', Auth::user()->company_id)->findOrFail($id);

        activity()
            ->causedBy(Auth::user())
            ->performedOn($snapshot)
            ->log('cctv_snapshot_restored');

        Log::info('CctvSnapshot: dipulihkan dari arsip.', [
            'snapshot_id' => $snapshot->id,
            'restored_by' => Auth::id(),
        ]);

        $snapshot->restore();

        return back()->with('success', 'Capture berhasil dipulihkan.');
    }

    /**
     * Bentuk payload paginator jadi array serializable untuk Inertia via
     * CctvSnapshotRepository::present() — SATU-SATUNYA sumber bentuk data,
     * dipakai juga oleh BatchController::show() agar SnapshotGalleryCard.vue
     * menerima struktur props yang identik di kedua konteks.
     */
    private function presentPage(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        $paginator->getCollection()->transform(fn (CctvSnapshot $s) => $this->repository->present($s));

        return $paginator->toArray();
    }
}
