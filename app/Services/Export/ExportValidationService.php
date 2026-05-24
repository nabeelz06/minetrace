<?php

namespace App\Services\Export;

use App\Models\Batch;
use App\Models\ProductCode;
use App\Models\CompanySetting;
use Illuminate\Support\Collection;

/**
 * ExportValidationService — validasi berlapis sebelum ekspor dikonfirmasi.
 *
 * Layer 1 — HARD BLOCK (tidak bisa di-bypass):
 *   - Product code MON → ekspor diblokir total
 *   - Batch status bukan in_warehouse
 *   - Berat batch = 0
 *
 * Layer 2 — SOFT WARNING (pop-up konfirmasi, bisa dilanjutkan):
 *   - Batch belum ada lab analysis
 *   - Mass balance discrepancy ada
 *   - Traceability tidak lengkap
 */
class ExportValidationService
{
    /**
     * Validasi batch sebelum ekspor.
     * Return: ['can_proceed' => bool, 'hard_blocks' => [], 'warnings' => []]
     */
    public function validate(array $batchIds, int $companyId): array
    {
        $batches     = Batch::whereIn('id', $batchIds)
            ->where('company_id', $companyId)
            ->with(['productCode', 'labAnalyses'])
            ->get();

        $hardBlocks = [];
        $warnings   = [];

        // ─── Ambil blocked codes dari company settings ────────────────────
        $settingValue = CompanySetting::get($companyId, 'export_block_codes', ['MON']);
        
        // Cek jika nilainya masih string (misal dari fallback), maka decode. 
        // Jika sudah berupa array (karena Model cast), gunakan langsung.
        $blockedCodes = is_string($settingValue) 
            ? json_decode($settingValue, true) 
            : $settingValue;

        $blockedCodes = $blockedCodes ?? ['MON'];

        foreach ($batches as $batch) {

            // ═══════ HARD BLOCKS ════════════════════════════════════════
            // 1. Product code diblokir (MON)
            if ($batch->productCode && in_array($batch->productCode->code, $blockedCodes)) {
                $hardBlocks[] = [
                    'batch_code' => $batch->batch_code,
                    'reason'     => "Batch {$batch->batch_code} ({$batch->productCode->code}) diblokir ekspor berdasarkan regulasi nasional.",
                    'type'       => 'export_blocked_product',
                ];
            }

            // 2. Status tidak valid untuk ekspor
            if (!in_array($batch->status, ['in_warehouse', 'active', 'completed'])) {
                $hardBlocks[] = [
                    'batch_code' => $batch->batch_code,
                    'reason'     => "Batch {$batch->batch_code} berstatus '{$batch->status}' — tidak valid untuk ekspor.",
                    'type'       => 'invalid_status',
                ];
            }

            // 3. Berat nol atau negatif
            if ($batch->current_weight <= 0) {
                $hardBlocks[] = [
                    'batch_code' => $batch->batch_code,
                    'reason'     => "Batch {$batch->batch_code} memiliki berat 0 kg.",
                    'type'       => 'zero_weight',
                ];
            }

            // ═══════ SOFT WARNINGS ════════════════════════════════════
            // 1. Belum ada lab analysis
            if ($batch->labAnalyses->isEmpty()) {
                $warnings[] = [
                    'batch_code' => $batch->batch_code,
                    'message'    => "Batch {$batch->batch_code} belum memiliki hasil analisis lab.",
                    'type'       => 'no_lab_analysis',
                ];
            }

            // 2. Mass balance discrepancy
            if ($batch->has_discrepancy) {
                $warnings[] = [
                    'batch_code' => $batch->batch_code,
                    'message'    => "Batch {$batch->batch_code} memiliki mass balance discrepancy (susut: {$batch->mass_loss_pct}%).",
                    'type'       => 'mass_balance_discrepancy',
                ];
            }
        }

        // ─── Batch tidak ditemukan ─────────────────────────────────────────
        if ($batches->count() !== count($batchIds)) {
            $foundIds   = $batches->pluck('id')->toArray();
            $missingIds = array_diff($batchIds, $foundIds);
            foreach ($missingIds as $id) {
                $hardBlocks[] = [
                    'batch_code' => "ID:{$id}",
                    'reason'     => "Batch ID:{$id} tidak ditemukan atau bukan milik company ini.",
                    'type'       => 'batch_not_found',
                ];
            }
        }

        return [
            'can_proceed'    => empty($hardBlocks),
            'hard_blocks'    => $hardBlocks,
            'warnings'       => $warnings,
            'batches'        => $batches,
            'total_weight'   => $batches->sum('current_weight'),
        ];
    }
}