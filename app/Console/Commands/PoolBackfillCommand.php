<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Services\Process\PoolService;
use App\Support\ProcessFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sekali jalan setelah rilis penghimpunan: batch yang SEDANG diproses (in_process) di Wet/Dry dimasukkan ke
 * himpunan `collecting` tahapnya dan tag-nya dilepas. Batch in_transit tidak disentuh (ikut alur baru saat diterima).
 */
class PoolBackfillCommand extends Command
{
    protected $signature = 'batches:pool-backfill {--dry-run : Tampilkan saja, tanpa menulis}';

    protected $description = 'Masukkan batch in_process di Wet/Dry ke himpunan (rilis penghimpunan)';

    public function handle(PoolService $pools): int
    {
        $batches = Batch::whereIn('process_stage', ['wet_process', 'dry_process'])
            ->where('status', ProcessFlow::STATUS_AFTER_RECEIVE)
            ->whereNull('pool_id')
            ->whereDoesntHave('poolInput')
            ->orderBy('company_id')->orderBy('process_stage')->orderBy('id')
            ->get();

        if ($batches->isEmpty()) {
            $this->info('Tidak ada batch yang perlu dihimpun.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Kode', 'Perusahaan', 'Tahap', 'Tag', 'Berat (kg)'], $batches->map(fn ($b) => [
            $b->id, $b->batch_code, $b->company_id, $b->process_stage, $b->rfid_tag ?? '—', $b->current_weight,
        ]));

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$batches->count()} batch akan dihimpun. Tidak ada yang ditulis.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($batches, $pools) {
            foreach ($batches as $b) {
                $pools->join($b, null);
            }
        });

        $this->info("{$batches->count()} batch dimasukkan ke himpunan.");

        return self::SUCCESS;
    }
}
