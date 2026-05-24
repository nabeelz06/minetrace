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
 * ExportSummaryExport — Excel export untuk laporan ringkasan ekspor.
 */
class ExportSummaryExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(
        private Collection $exports,
        private mixed $company,
        private Carbon $from,
        private Carbon $to
    ) {}

    public function title(): string { return 'Ringkasan Ekspor'; }

    public function collection(): Collection
    {
        return $this->exports->map(fn($e) => [
            'ID Ekspor'       => '#' . str_pad($e->id, 6, '0', STR_PAD_LEFT),
            'Tipe'            => $e->export_type === 'international' ? 'Internasional' : 'Domestik',
            'Perusahaan Tujuan'=> $e->destination_company,
            'Negara Tujuan'   => $e->destination_country ?? 'Indonesia',
            'Total Berat (kg)'=> $e->total_weight_kg,
            'Total Berat (MT)'=> round($e->total_weight_kg / 1000, 4),
            'Nilai (USD)'     => $e->value_usd ?? '—',
            'No. Invoice'     => $e->invoice_number ?? '—',
            'No. Manifest'    => $e->manifest_number ?? '—',
            'Container'       => $e->container_number ?? '—',
            'Kapal'           => $e->vessel_name ?? '—',
            'Pelabuhan Muat'  => $e->port_of_loading ?? '—',
            'Pelabuhan Tujuan'=> $e->port_of_discharge ?? '—',
            'Status'          => $e->status,
            'Jumlah Batch'    => count($e->batch_ids ?? []),
            'Dibuat Oleh'     => $e->createdByUser?->name ?? '—',
            'Tanggal Ekspor'  => $e->exported_at?->format('d/m/Y'),
            'Catatan'         => $e->notes ?? '—',
        ]);
    }

    public function headings(): array
    {
        return [
            'ID Ekspor', 'Tipe', 'Perusahaan Tujuan', 'Negara Tujuan',
            'Total Berat (kg)', 'Total Berat (MT)', 'Nilai (USD)',
            'No. Invoice', 'No. Manifest', 'Container', 'Kapal',
            'Pelabuhan Muat', 'Pelabuhan Tujuan', 'Status',
            'Jumlah Batch', 'Dibuat Oleh', 'Tanggal Ekspor', 'Catatan',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3E5C74']],
            ],
        ];
    }
}