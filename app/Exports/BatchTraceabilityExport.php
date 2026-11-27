<?php

namespace App\Exports;

use App\Support\CheckpointPresenter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * BatchTraceabilityExport — Excel export untuk laporan traceability batch.
 * Multi-sheet: Summary + Detail per Batch
 */
class BatchTraceabilityExport implements WithMultipleSheets
{
    public function __construct(
        private Collection $batches,
        private mixed      $company,
        private Carbon     $from,
        private Carbon     $to
    ) {}

    public function sheets(): array
    {
        return [
            new BatchSummarySheet($this->batches, $this->company, $this->from, $this->to),
            new BatchDetailSheet($this->batches),
        ];
    }
}

class BatchSummarySheet implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(
        private Collection $batches,
        private mixed $company,
        private Carbon $from,
        private Carbon $to
    ) {}

    public function title(): string { return 'Ringkasan'; }

    public function collection(): Collection
    {
        return $this->batches->map(fn($b) => [
            'Kode Batch'     => $b->batch_code,
            'No. Partai / Lot' => $b->lot_number ?? '—',
            'Nama Mitra'     => $b->metadata['partner_name'] ?? '—',
            'Lokasi Asal'    => $b->metadata['origin_location'] ?? '—',
            'Penanda Tangan Mitra' => $b->metadata['partner_signatory'] ?? '—',
            'Produk'         => $b->productCode?->code ?? '—',
            'Nama Produk'    => $b->productCode?->name ?? '—',
            'Stage Saat Ini' => str_replace('_', ' ', ucfirst($b->process_stage)),
            'Status'         => $b->status,
            'Berat Awal (kg)'=> $b->initial_weight,
            'Berat Saat Ini (kg)' => $b->current_weight,
            'Susut (%)'      => $b->mass_loss_pct ?? 0,
            'Ada Discrepancy'=> $b->has_discrepancy ? 'Ya' : 'Tidak',
            'Lokasi'         => $b->currentLocation?->name ?? '—',
            // Waktu WIB sampai detik (penelusuran kronologis).
            'Dibuat (WIB)'   => CheckpointPresenter::time($b->created_at),
            'Terakhir Update (WIB)' => CheckpointPresenter::time($b->updated_at),
        ]);
    }

    public function headings(): array
    {
        return [
            'Kode Batch', 'No. Partai / Lot', 'Nama Mitra', 'Lokasi Asal', 'Penanda Tangan Mitra',
            'Produk', 'Nama Produk',
            'Stage Saat Ini', 'Status', 'Berat Awal (kg)', 'Berat Saat Ini (kg)',
            'Susut (%)', 'Ada Discrepancy', 'Lokasi', 'Dibuat (WIB)', 'Terakhir Update (WIB)',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3E5C74']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}

class BatchDetailSheet implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize
{
    public function __construct(private Collection $batches) {}

    public function title(): string { return 'Detail Checkpoint'; }

    public function collection(): Collection
    {
        $rows = collect();
        foreach ($this->batches as $batch) {
            foreach ($batch->checkpoints as $cp) {
                $rows->push([
                    'Kode Batch'        => $batch->batch_code,
                    'No. Partai / Lot'  => $batch->lot_number ?? '—',
                    'Nama Mitra'        => $batch->metadata['partner_name'] ?? '—',
                    // "Stage Point": nama tahap checkpoint apa adanya, mis. "Receiving Verify" untuk penerimaan di Receiving & Wet.
                    'Stage Point'       => CheckpointPresenter::stagePoint($cp->checkpoint_stage),
                    'Berat Dicatat (kg)'=> $cp->weight_recorded ?? '—',
                    'Berat Sebelumnya (kg)' => $cp->weight_previous ?? '—',
                    'Susut (%)'         => $cp->weight_loss_pct ?? '—',
                    'Discrepancy'       => $cp->has_discrepancy ? 'Ya' : 'Tidak',
                    'Terverifikasi'     => $cp->is_verified ? 'Ya' : 'Tidak',
                    'Lokasi'            => $cp->location?->name ?? '—',
                    'Dicatat Oleh'      => $cp->recordedByUser?->name ?? '—',
                    'Waktu (WIB)'       => CheckpointPresenter::time($cp->recorded_at),
                    'Catatan'           => CheckpointPresenter::note($cp),
                ]);
            }
        }
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Kode Batch', 'No. Partai / Lot', 'Nama Mitra', 'Stage Point', 'Berat Dicatat (kg)', 'Berat Sebelumnya (kg)',
            'Susut (%)', 'Discrepancy', 'Terverifikasi', 'Lokasi',
            'Dicatat Oleh', 'Waktu (WIB)', 'Catatan',
        ];
    }
}
