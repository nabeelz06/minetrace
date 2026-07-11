<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * MassBalanceExport — Excel export untuk laporan mass balance per stage.
 */
class MassBalanceExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(
        private Collection $data,
        private mixed $company,
        private Carbon $from,
        private Carbon $to
    ) {}

    public function title(): string { return 'Mass Balance'; }

    public function collection(): Collection
    {
        return $this->data->map(fn($row) => [
            'Stage'              => $row['stage_label'],
            'Jumlah Batch'        => $row['total_batches'],
            'Berat Awal (kg)'     => $row['input_weight'],
            'Berat Saat Ini (kg)' => $row['current_weight'],
            'Rata-rata Susut (%)' => $row['avg_loss_pct'],
            'Jumlah Alert'        => $row['alert_count'],
        ]);
    }

    public function headings(): array
    {
        return [
            'Stage', 'Jumlah Batch', 'Berat Awal (kg)',
            'Berat Saat Ini (kg)', 'Rata-rata Susut (%)', 'Jumlah Alert',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3E5C74']],
            ],
        ];
    }
}
