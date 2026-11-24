<?php

namespace App\Services\Batch;

use App\Models\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penelusuran silsilah batch: sisi `batch_lineage` yang tidak di-void (penghimpunan Wet/Dry) ditambah
 * `parent_batch_id` lama (split / transformasi Dry lama). Rekursif sampai batch asal di Lab Kimia.
 */
class LineageService
{
    private const MAX_DEPTH = 20;

    /**
     * Semua leluhur batch, terdekat lebih dulu. Satu batch muncul sekali (kedalaman terkecil).
     *
     * @return Collection<int, array{id:int, batch_code:string, product:?string, process_stage:string, status:string, depth:int, weight:?float, child_id:int, is_estimate:bool}>
     */
    public function ancestors(Batch $batch): Collection
    {
        return $this->walk($batch->id, 'up');
    }

    /** Semua keturunan batch (batch gabungan tempat batch ini masuk), terdekat lebih dulu. */
    public function descendants(Batch $batch): Collection
    {
        return $this->walk($batch->id, 'down');
    }

    /** ID batch ini beserta seluruh leluhurnya (untuk linimasa gabungan). */
    public function lineageIds(Batch $batch): array
    {
        return array_merge([$batch->id], $this->ancestors($batch)->pluck('id')->all());
    }

    private function walk(int $id, string $dir): Collection
    {
        [$from, $to] = $dir === 'up' ? ['child_id', 'parent_id'] : ['parent_id', 'child_id'];

        $sql = <<<SQL
            WITH RECURSIVE edges AS (
                SELECT parent_batch_id AS parent_id, child_batch_id AS child_id,
                       contributed_weight AS weight, weight_is_estimate AS is_estimate
                FROM batch_lineage WHERE voided_at IS NULL
                UNION ALL
                SELECT parent_batch_id, id, NULL, false FROM batches WHERE parent_batch_id IS NOT NULL
            ),
            walk(id, link_id, depth, weight, is_estimate, path) AS (
                SELECT e.{$to}, e.{$from}, 1, e.weight, e.is_estimate, ARRAY[?::bigint, e.{$to}]
                FROM edges e WHERE e.{$from} = ?
                UNION ALL
                SELECT e.{$to}, e.{$from}, w.depth + 1, e.weight, e.is_estimate, w.path || e.{$to}
                FROM edges e JOIN walk w ON e.{$from} = w.id
                WHERE w.depth < ? AND NOT (e.{$to} = ANY (w.path))
            )
            SELECT DISTINCT ON (w.id) w.id, w.link_id, w.depth, w.weight, w.is_estimate,
                   b.batch_code, b.process_stage, b.status, p.code AS product
            FROM walk w
            JOIN batches b ON b.id = w.id
            LEFT JOIN product_codes p ON p.id = b.product_code_id
            ORDER BY w.id, w.depth
        SQL;

        return collect(DB::select($sql, [$id, $id, self::MAX_DEPTH]))
            ->map(fn ($r) => [
                'id'            => (int) $r->id,
                'batch_code'    => $r->batch_code,
                'product'       => $r->product,
                'process_stage' => $r->process_stage,
                'status'        => $r->status,
                'depth'         => (int) $r->depth,
                'weight'        => $r->weight !== null ? (float) $r->weight : null,
                'link_id'       => (int) $r->link_id,
                'is_estimate'   => (bool) $r->is_estimate,
            ])
            ->sortBy([['depth', 'asc'], ['id', 'asc']])
            ->values();
    }
}
