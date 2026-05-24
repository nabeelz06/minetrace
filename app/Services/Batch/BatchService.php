<?php

namespace App\Services\Batch;

use App\Models\Batch;
use App\Services\Batch\BatchCodeService;
use App\Models\BatchTransformation;
use App\Models\Checkpoint;
use App\Models\Company;
use App\Models\Location;
use App\Models\MassBalanceThreshold;
use App\Models\ProductCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * BatchService — business logic utama sistem traceability.
 *
 * Semua operasi krusial (create, update, split, transform)
 * dibungkus dalam DB::transaction untuk data integrity.
 */
class BatchService
{
    public function __construct(
        private BatchCodeService $codeService
    ) {}

    // ═══════════════════════════════════════════════════════════════════════
    // CREATE BATCH
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Membuat batch baru (titik awal di Lab Kimia).
     * Menggunakan transaksi dan atomic batch code generation.
     *
     * @param  array $data  Data batch dari form input
     * @return Batch
     */
    public function createBatch(array $data): Batch
    {
        return DB::transaction(function () use ($data) {
            $company     = Company::findOrFail($data['company_id']);
            $location    = Location::findOrFail($data['location_id']);
            $productCode = ProductCode::findOrFail($data['product_code_id']);

            // ─── Generate kode batch yang atomic ──────────────────────────
            $batchCode = $this->codeService->generate(
                $company,
                $location,
                $data['process_stage'] ?? 'lab_kimia',
                $productCode
            );

            // ─── Buat batch ────────────────────────────────────────────────
            $batch = Batch::create([
                'company_id'         => $company->id,
                'batch_code'         => $batchCode,
                'product_code_id'    => $productCode->id,
                'initial_weight'     => $data['initial_weight'],
                'current_weight'     => $data['initial_weight'],
                'process_stage'      => $data['process_stage'] ?? 'lab_kimia',
                'status'             => 'active',
                'current_location_id'=> $location->id,
                'export_status'      => $productCode->is_export_blocked
                    ? 'not_applicable'
                    : 'available',
                'metadata'           => $data['metadata'] ?? [],
                'created_by'         => Auth::id(),
            ]);

            // ─── Catat checkpoint pertama ──────────────────────────────────
            $this->recordCheckpoint($batch, [
                'checkpoint_stage' => 'lab_kimia_create',
                'weight_recorded'  => $data['initial_weight'],
                'notes'            => $data['notes'] ?? null,
                'checkpoint_data'  => $data['checkpoint_data'] ?? [],
            ]);

            return $batch->fresh(['productCode', 'currentLocation']);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // UPDATE BERAT & STATUS (misal: setelah Wet Process)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Update berat batch dan catat checkpoint, hitung mass balance.
     *
     * @param  Batch  $batch           Batch yang diupdate
     * @param  float  $newWeight       Berat baru setelah proses (kg)
     * @param  string $checkpointStage Stage checkpoint yang dicatat
     * @param  array  $additionalData  Data tambahan untuk checkpoint
     * @return Batch
     */
    public function updateWeight(
        Batch $batch,
        float $newWeight,
        string $checkpointStage,
        array $additionalData = []
    ): Batch {
        return DB::transaction(function () use ($batch, $newWeight, $checkpointStage, $additionalData) {
            $previousWeight = $batch->current_weight;
            $loss           = $previousWeight - $newWeight;
            $lossPct        = $previousWeight > 0
                ? round(($loss / $previousWeight) * 100, 2)
                : 0;

            // ─── Cek apakah ada discrepancy ───────────────────────────────
            $hasDiscrepancy = $this->checkMassBalance(
                $batch->company_id,
                $batch->process_stage,
                $lossPct
            );

            // ─── Update batch ──────────────────────────────────────────────
            $batch->update([
                'current_weight'  => $newWeight,
                'mass_loss_pct'   => $lossPct,
                'has_discrepancy' => $hasDiscrepancy,
                'updated_by'      => Auth::id(),
            ]);

            // ─── Catat checkpoint ──────────────────────────────────────────
            $this->recordCheckpoint($batch, array_merge([
                'checkpoint_stage'  => $checkpointStage,
                'weight_recorded'   => $newWeight,
                'weight_previous'   => $previousWeight,
                'weight_loss'       => $loss,
                'weight_loss_pct'   => $lossPct,
                'has_discrepancy'   => $hasDiscrepancy,
            ], $additionalData));

            return $batch->fresh();
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PINDAH STAGE (advance to next stage)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Pindahkan batch ke stage/lokasi berikutnya.
     *
     * @param  Batch    $batch       Batch yang dipindah
     * @param  string   $nextStage   Stage tujuan
     * @param  Location $location    Lokasi tujuan
     * @param  string   $newStatus   Status baru
     * @param  array    $checkpointData Data checkpoint
     * @return Batch
     */
    public function advanceStage(
        Batch $batch,
        string $nextStage,
        Location $location,
        string $newStatus = 'active',
        array $checkpointData = []
    ): Batch {
        return DB::transaction(function () use ($batch, $nextStage, $location, $newStatus, $checkpointData) {

            // ─── Update stage dan lokasi ───────────────────────────────────
            $batch->update([
                'process_stage'       => $nextStage,
                'status'              => $newStatus,
                'current_location_id' => $location->id,
                'updated_by'          => Auth::id(),
            ]);

            // ─── Catat checkpoint transit ──────────────────────────────────
            $this->recordCheckpoint($batch, array_merge([
                'checkpoint_stage' => 'in_transit',
                'weight_recorded'  => $batch->current_weight,
                'next_stage'       => $nextStage,
            ], $checkpointData));

            return $batch->fresh();
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DRY PROCESS TRANSFORMATION (1 input → 3 output)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Eksekusi transformasi Dry Process.
     * 1 batch input (MIK) → 3 batch output baru (MON + ZIR + ILM).
     *
     * State Machine Guard: batch HARUS in_process di dry_process.
     * Menggunakan pessimistic locking untuk race-condition protection.
     *
     * @param  Batch  $inputBatch  Batch MIK yang akan ditransformasi
     * @param  array  $outputs     [{product_code_id, weight_kg, percentage}, ...]
     * @param  int    $locationId  Lokasi transformasi
     * @param  string $notes       Catatan operator
     * @return array               ['input' => Batch, 'outputs' => Collection]
     */
    public function executeDryProcessTransformation(
        Batch $inputBatch,
        array $outputs,
        int $locationId,
        string $notes = ''
    ): array {
        return DB::transaction(function () use ($inputBatch, $outputs, $locationId, $notes) {

            // ─── Pessimistic Lock: cegah concurrent transform ──────────────
            $inputBatch = Batch::lockForUpdate()->findOrFail($inputBatch->id);

            // ─── State Machine Guard ───────────────────────────────────────
            throw_unless(
                $inputBatch->process_stage === 'dry_process',
                new \InvalidArgumentException('Batch bukan di stage Dry Process.')
            );
            throw_unless(
                in_array($inputBatch->status, ['active', 'in_process']),
                new \InvalidArgumentException('Status batch tidak valid untuk transformasi.')
            );

            $location          = Location::findOrFail($locationId);
            $company           = Company::findOrFail($inputBatch->company_id);
            $totalOutputWeight = collect($outputs)->sum('weight_kg');
            $lossPct           = $inputBatch->current_weight > 0
                ? round((($inputBatch->current_weight - $totalOutputWeight) / $inputBatch->current_weight) * 100, 2)
                : 0;

            // ─── Archive batch input ───────────────────────────────────────
            $inputBatch->update([
                'status'     => 'transformed',
                'updated_by' => Auth::id(),
            ]);

            // ─── Buat batch output baru ────────────────────────────────────
            $outputBatches = collect($outputs)->map(function ($output) use ($inputBatch, $location, $company) {
                $productCode = ProductCode::findOrFail($output['product_code_id']);

                $batchCode = $this->codeService->generate(
                    $company,
                    $location,
                    'dry_process',
                    $productCode
                );

                $outputBatch = Batch::create([
                    'company_id'          => $company->id,
                    'batch_code'          => $batchCode,
                    'product_code_id'     => $productCode->id,
                    'parent_batch_id'     => $inputBatch->id,
                    'initial_weight'      => $output['weight_kg'],
                    'current_weight'      => $output['weight_kg'],
                    'process_stage'       => 'dry_process',
                    'status'              => 'completed',
                    'current_location_id' => $location->id,
                    'export_status'       => $productCode->is_export_blocked
                        ? 'not_applicable'
                        : 'available',
                    'metadata'            => [
                        'percentage'           => $output['percentage'] ?? null,
                        'from_transformation'  => $inputBatch->batch_code,
                    ],
                    'created_by'          => Auth::id(),
                ]);

                // Checkpoint untuk setiap output
                $this->recordCheckpoint($outputBatch, [
                    'checkpoint_stage'  => 'dry_process_output',
                    'weight_recorded'   => $output['weight_kg'],
                    'checkpoint_data'   => [
                        'percentage'    => $output['percentage'] ?? null,
                        'product_code'  => $productCode->code,
                        'from_batch'    => $inputBatch->batch_code,
                    ],
                ]);

                return $outputBatch;
            });

            // ─── Catat transformasi untuk audit trail ─────────────────────
            BatchTransformation::create([
                'input_batch_id'          => $inputBatch->id,
                'output_batch_ids'        => $outputBatches->pluck('id')->toArray(),
                'transformation_type'     => 'dry_process_extraction',
                'location_id'             => $locationId,
                'transformation_details'  => [
                    'input_weight'        => $inputBatch->current_weight,
                    'outputs'             => collect($outputs)->map(function ($o, $i) use ($outputBatches) {
                        return [
                            'batch_id'   => $outputBatches[$i]->id,
                            'batch_code' => $outputBatches[$i]->batch_code,
                            'product'    => ProductCode::find($o['product_code_id'])->code,
                            'weight'     => $o['weight_kg'],
                            'percentage' => $o['percentage'] ?? null,
                        ];
                    })->toArray(),
                    'total_output_weight' => $totalOutputWeight,
                    'loss_pct'            => $lossPct,
                ],
                'notes'                   => $notes,
                'performed_by'            => Auth::id(),
                'transformed_at'          => now(),
            ]);

            return [
                'input'   => $inputBatch->fresh(),
                'outputs' => $outputBatches,
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SPLIT BATCH (untuk warehouse)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Split batch menjadi beberapa child batches.
     * Menggunakan pessimistic locking + idempotency check.
     *
     * @param  Batch  $batch   Batch yang akan di-split
     * @param  array  $splits  [{weight_kg, notes}, ...]
     * @param  string $reason  Alasan split
     * @return array           Child batches yang dibuat
     */
    public function splitBatch(Batch $batch, array $splits, string $reason = ''): array
    {
        return DB::transaction(function () use ($batch, $splits, $reason) {

            // ─── Pessimistic Lock ──────────────────────────────────────────
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            // ─── Validasi: cek apakah sudah pernah di-split ────────────────
            if ($batch->is_split && $batch->childBatches()->exists()) {
                throw new \InvalidArgumentException(
                    "Batch {$batch->batch_code} sudah pernah di-split sebelumnya."
                );
            }

            // ─── Validasi berat: total split tidak boleh melebihi berat batch ──
            $totalSplitWeight = collect($splits)->sum('weight_kg');
            if ($totalSplitWeight > $batch->current_weight) {
                throw new \InvalidArgumentException(
                    "Total berat split ({$totalSplitWeight} kg) melebihi berat batch ({$batch->current_weight} kg)."
                );
            }

            $company  = Company::findOrFail($batch->company_id);
            $location = $batch->currentLocation;

            // ─── Buat child batches ────────────────────────────────────────
            $childBatches = [];
            foreach ($splits as $index => $splitData) {

                // Pastikan kode split unik (while-loop dengan timestamp fallback)
                $splitCode = $this->codeService->generateSplitCode($batch->batch_code, $index);
                $attempt   = 0;
                while (Batch::where('batch_code', $splitCode)->exists() && $attempt < 10) {
                    $splitCode = $this->codeService->generateSplitCode($batch->batch_code, $index)
                        . '_' . now()->format('His');
                    $attempt++;
                }

                $childBatch = Batch::create([
                    'company_id'          => $batch->company_id,
                    'batch_code'          => $splitCode,
                    'product_code_id'     => $batch->product_code_id,
                    'parent_batch_id'     => $batch->id,
                    'initial_weight'      => $splitData['weight_kg'],
                    'current_weight'      => $splitData['weight_kg'],
                    'process_stage'       => $batch->process_stage,
                    'status'              => $batch->status,
                    'current_location_id' => $location?->id,
                    'split_suffix'        => '/' . chr(65 + $index),
                    'export_status'       => $batch->export_status,
                    'metadata'            => array_merge($batch->metadata ?? [], [
                        'split_from'  => $batch->batch_code,
                        'split_notes' => $splitData['notes'] ?? null,
                    ]),
                    'created_by'          => Auth::id(),
                ]);

                $this->recordCheckpoint($childBatch, [
                    'checkpoint_stage' => 'warehouse_inbound',
                    'weight_recorded'  => $splitData['weight_kg'],
                    'notes'            => "Split dari {$batch->batch_code}: " . ($splitData['notes'] ?? ''),
                ]);

                $childBatches[] = $childBatch;
            }

            // ─── Update batch parent ───────────────────────────────────────
            $batch->update([
                'is_split'     => true,
                'split_count'  => count($splits),
                'split_reason' => $reason,
                'status'       => 'split',
                'updated_by'   => Auth::id(),
            ]);

            // ─── Catat transformasi audit trail ───────────────────────────
            BatchTransformation::create([
                'input_batch_id'         => $batch->id,
                'output_batch_ids'       => collect($childBatches)->pluck('id')->toArray(),
                'transformation_type'    => 'warehouse_split',
                'location_id'            => $location?->id,
                'transformation_details' => [
                    'reason'        => $reason,
                    'split_count'   => count($splits),
                    'splits'        => collect($childBatches)->map(fn ($b, $i) => [
                        'batch_code' => $b->batch_code,
                        'weight'     => $splits[$i]['weight_kg'],
                    ])->toArray(),
                ],
                'performed_by'           => Auth::id(),
                'transformed_at'         => now(),
            ]);

            return $childBatches;
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Catat checkpoint untuk batch.
     */
    private function recordCheckpoint(Batch $batch, array $data): Checkpoint
    {
        return Checkpoint::create(array_merge([
            'batch_id'       => $batch->id,
            'location_id'    => $batch->current_location_id,
            'is_verified'    => false,
            'recorded_by'    => Auth::id(),
            'recorded_at'    => now(),
        ], $data));
    }

    /**
     * Cek apakah loss melebihi threshold (mass balance discrepancy).
     * Return true jika ada discrepancy (loss melebihi warning threshold).
     */
    private function checkMassBalance(int $companyId, string $stage, float $lossPct): bool
    {
        $threshold = MassBalanceThreshold::where('company_id', $companyId)
            ->where('process_stage', $stage)
            ->first();

        if (!$threshold) {
            return false; // Tidak ada threshold = tidak ada alert
        }

        return $lossPct >= $threshold->warning_threshold_pct;
    }

    /**
     * Ambil timeline batch untuk ditampilkan di detail view.
     * Menggabungkan checkpoints dan transformations secara kronologis.
     */
    public function getTimeline(Batch $batch): array
    {
        $timeline = [];

        // ─── Event: Batch dibuat ───────────────────────────────────────────
        $timeline[] = [
            'type'        => 'created',
            'title'       => 'Batch Dibuat',
            'description' => "Berat awal: {$batch->initial_weight} kg",
            'timestamp'   => $batch->created_at,
            'user'        => $batch->createdByUser?->name ?? 'System',
            'color'       => 'blue',
        ];

        // ─── Events: Checkpoints ──────────────────────────────────────────
        foreach ($batch->checkpoints as $cp) {
            $timeline[] = [
                'type'        => 'checkpoint',
                'title'       => $cp->stage_label,
                'description' => $cp->notes,
                'timestamp'   => $cp->recorded_at,
                'user'        => $cp->recordedByUser?->name ?? 'Unknown',
                'weight'      => $cp->weight_recorded,
                'loss_pct'    => $cp->weight_loss_pct,
                'has_alert'   => $cp->has_discrepancy,
                'color'       => $cp->has_discrepancy ? 'red' : 'green',
            ];
        }

        // ─── Sort kronologis ──────────────────────────────────────────────
        usort($timeline, fn ($a, $b) =>
            $a['timestamp']->timestamp <=> $b['timestamp']->timestamp
        );

        return $timeline;
    }
}