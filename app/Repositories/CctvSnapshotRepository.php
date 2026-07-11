<?php

namespace App\Repositories;

use App\Models\CctvSnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * CctvSnapshotRepository — query terpusat untuk CctvSnapshot.
 *
 * Memisahkan pembangunan query filter dari controller agar
 * CctvSnapshotController tetap tipis dan query bisa diuji/re-use
 * secara independen (mis. dari command lain di masa depan).
 */
class CctvSnapshotRepository
{
    /**
     * Paginated + filtered index query, di-scope ke satu company.
     *
     * @param  array{batch_code?: string, stage?: string, capture_mode?: string,
     *               capture_status?: string, verified?: string, date_from?: string,
     *               date_to?: string}  $filters
     */
    public function paginateForCompany(int $companyId, array $filters, int $perPage = 24): LengthAwarePaginator
    {
        return $this->applyFilters(
            CctvSnapshot::query()
                ->with(['batch:id,batch_code,product_code_id', 'batch.productCode:id,code', 'camera:id,name'])
                ->where('company_id', $companyId),
            $filters
        )
            ->latest('captured_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function trashedForCompany(int $companyId, array $filters, int $perPage = 24): LengthAwarePaginator
    {
        return $this->applyFilters(
            CctvSnapshot::onlyTrashed()
                ->with(['batch:id,batch_code,product_code_id', 'batch.productCode:id,code', 'camera:id,name'])
                ->where('company_id', $companyId),
            $filters
        )
            ->latest('deleted_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function forBatch(int $batchId): \Illuminate\Database\Eloquent\Collection
    {
        return CctvSnapshot::with(['camera:id,name', 'verifiedBy:id,name'])
            ->forBatch($batchId)
            ->latest('captured_at')
            ->get();
    }

    /**
     * Bentuk satu CctvSnapshot jadi array serializable untuk Inertia —
     * SATU-SATUNYA sumber bentuk data ini, dipakai baik oleh
     * CctvSnapshotController (global) maupun BatchController::show()
     * (per-batch), agar SnapshotGalleryCard.vue menerima struktur props
     * yang identik di kedua konteks.
     */
    public function present(CctvSnapshot $s): array
    {
        $tz = 'Asia/Jakarta';

        return [
            'id'               => $s->id,
            'batch_id'         => $s->batch_id,
            'batch_code'       => $s->batch?->batch_code,
            'product_code'     => $s->batch?->productCode?->code,
            'url'              => $s->url,
            'capture_mode'     => $s->capture_mode,
            'capture_status'   => $s->capture_status,
            'failure_reason'   => $s->failure_reason,
            'scan_type'        => $s->scan_type,
            'rfid_uid'         => $s->rfid_uid,
            'stage'            => $s->stage,
            'stage_label'      => $this->stageLabel($s->stage),
            'camera_name'      => $s->camera?->name,
            'filesize_kb'      => $s->filesize_kb,
            'captured_at'      => $s->captured_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'captured_at_diff' => $s->captured_at?->diffForHumans(),
            'is_verified'      => $s->is_verified,
            'verified_by'      => $s->verifiedBy?->name,
            'verified_at'      => $s->verified_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
            'review_notes'     => $s->review_notes,
            'deleted_at'       => $s->deleted_at?->timezone($tz)->format('d/m/Y H:i:s') . ' WIB',
        ];
    }

    public function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'lab_kimia'     => 'Lab Kimia',
            'receiving'     => 'Receiving Area',
            'wet_process'   => 'Wet Process',
            'dry_process'   => 'Dry Process',
            'project_plant' => 'Project Plant',
            'warehouse'     => 'LTJ Warehouse',
            default         => ucwords(str_replace('_', ' ', $stage ?? '')),
        };
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['batch_code'])) {
            $query->whereHas('batch', fn ($q) => $q->where('batch_code', 'ILIKE', "%{$filters['batch_code']}%"));
        }
        if (!empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }
        if (!empty($filters['capture_mode'])) {
            $query->where('capture_mode', $filters['capture_mode']);
        }
        if (!empty($filters['capture_status'])) {
            $query->where('capture_status', $filters['capture_status']);
        }
        if (isset($filters['verified']) && $filters['verified'] !== '') {
            $query->where('is_verified', filter_var($filters['verified'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('captured_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('captured_at', '<=', $filters['date_to']);
        }

        return $query;
    }
}
