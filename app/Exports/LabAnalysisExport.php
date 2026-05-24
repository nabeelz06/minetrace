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
 * LabAnalysisExport — Excel export untuk laporan analisis lab LTJ.
 */
class LabAnalysisExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(
        private Collection $analyses,
        private mixed      $company,
        private Carbon     $from,
        private Carbon     $to
    ) {}

    public function title(): string { return 'Lab Analisis LTJ'; }

    public function collection(): Collection
    {
        return $this->analyses->map(fn($a) => [
            'Kode Batch'       => $a->batch?->batch_code ?? '—',
            'Kode Produk'      => $a->batch?->productCode?->code ?? '—',
            'Nama Produk'      => $a->batch?->productCode?->name ?? '—',
            'La (%)'           => $a->la_pct ?? '—',
            'La (kg)'          => $a->la_kg  ?? '—',
            'Ce (%)'           => $a->ce_pct ?? '—',
            'Ce (kg)'          => $a->ce_kg  ?? '—',
            'Pr (%)'           => $a->pr_pct ?? '—',
            'Pr (kg)'          => $a->pr_kg  ?? '—',
            'Nd (%)'           => $a->nd_pct ?? '—',
            'Nd (kg)'          => $a->nd_kg  ?? '—',
            'Y (%)'            => $a->y_pct  ?? '—',
            'Y (kg)'           => $a->y_kg   ?? '—',
            'Total REO (%)'    => $a->total_reo_pct ?? '—',
            'ThO2 (%)'         => $a->tho2_pct ?? '—',
            'U3O8 (%)'         => $a->u3o8_pct ?? '—',
            'Metode'           => $a->lab_method ?? '—',
            'Tervalidasi'      => $a->is_validated ? 'Ya' : 'Tidak',
            'Dianalisis Oleh'  => $a->analyzedByUser?->name ?? '—',
            'Tanggal Analisis' => $a->analyzed_at?->format('d/m/Y H:i'),
            'Catatan'          => $a->notes ?? '—',
        ]);
    }

    public function headings(): array
    {
        return [
            'Kode Batch', 'Kode Produk', 'Nama Produk',
            'La (%)', 'La (kg)', 'Ce (%)', 'Ce (kg)',
            'Pr (%)', 'Pr (kg)', 'Nd (%)', 'Nd (kg)',
            'Y (%)', 'Y (kg)', 'Total REO (%)',
            'ThO2 (%)', 'U3O8 (%)', 'Metode',
            'Tervalidasi', 'Dianalisis Oleh', 'Tanggal Analisis', 'Catatan',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Header row styling
        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3E5C74']],
        ];

        // Highlight REO column
        $reoStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => '1E40AF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
        ];

        return [
            1  => $headerStyle,
            'N' => $reoStyle, // Total REO column
        ];
    }
}