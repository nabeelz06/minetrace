<?php

namespace App\Services\Export;

use App\Models\Batch;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\LabAnalysis;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * DocumentGeneratorService — auto-generate dokumen ekspor dalam format PDF.
 *
 * Dokumen yang dihasilkan:
 * 1. Certificate of Analysis (CoA)
 * 2. Packing List
 * 3. Export Notification (untuk BIM)
 */
class DocumentGeneratorService
{
    /**
     * Generate semua dokumen ekspor sekaligus.
     * Simpan ke storage dan return path masing-masing.
     */
    public function generateExportDocuments(ExportLog $exportLog): array
    {
        $company = Company::findOrFail($exportLog->company_id);
        $batches = $exportLog->getBatches();
        $paths   = [];

        // ─── 1. Certificate of Analysis (CoA) ────────────────────────────
        $paths['coa'] = $this->generateCoA($exportLog, $company, $batches);

        // ─── 2. Packing List ──────────────────────────────────────────────
        $paths['packing_list'] = $this->generatePackingList($exportLog, $company, $batches);

        // ─── 3. Export Notification ───────────────────────────────────────
        $paths['export_notification'] = $this->generateExportNotification($exportLog, $company, $batches);

        return $paths;
    }

    /**
     * Generate Certificate of Analysis (CoA).
     */
    private function generateCoA(ExportLog $exportLog, Company $company, $batches): string
    {
        // Kumpulkan lab analyses untuk semua batch
        $labData = [];
        foreach ($batches as $batch) {
            $analysis = LabAnalysis::where('batch_id', $batch->id)
                ->where('is_validated', true)
                ->latest()
                ->first();
            if ($analysis) {
                $labData[] = [
                    'batch_code'   => $batch->batch_code,
                    'product_code' => $batch->productCode?->code,
                    'product_name' => $batch->productCode?->name,
                    'weight_kg'    => $batch->current_weight,
                    'la_pct'       => $analysis->la_pct,
                    'ce_pct'       => $analysis->ce_pct,
                    'pr_pct'       => $analysis->pr_pct,
                    'nd_pct'       => $analysis->nd_pct,
                    'y_pct'        => $analysis->y_pct,
                    'total_reo'    => $analysis->total_reo_pct,
                    'tho2_pct'     => $analysis->tho2_pct,
                    'u3o8_pct'     => $analysis->u3o8_pct,
                    'method'       => $analysis->lab_method,
                    'analyzed_at'  => $analysis->analyzed_at,
                ];
            }
        }

        $pdf = Pdf::loadView('pdf.export.coa', [
            'export'     => $exportLog,
            'company'    => $company,
            'batches'    => $batches,
            'lab_data'   => $labData,
            'coa_number' => $exportLog->coa_number ?? 'COA-' . now()->format('Ymd') . '-' . str_pad($exportLog->id, 4, '0', STR_PAD_LEFT),
            'date'       => now()->format('d F Y'),
        ])->setPaper('a4', 'portrait');

        $path = "exports/{$exportLog->id}/coa.pdf";
        Storage::put($path, $pdf->output());
        return $path;
    }

    /**
     * Generate Packing List.
     */
    private function generatePackingList(ExportLog $exportLog, Company $company, $batches): string
    {
        $pdf = Pdf::loadView('pdf.export.packing-list', [
            'export'  => $exportLog,
            'company' => $company,
            'batches' => $batches,
            'date'    => now()->format('d F Y'),
        ])->setPaper('a4', 'portrait');

        $path = "exports/{$exportLog->id}/packing-list.pdf";
        Storage::put($path, $pdf->output());
        return $path;
    }

    /**
     * Generate Export Notification untuk BIM.
     */
    private function generateExportNotification(ExportLog $exportLog, Company $company, $batches): string
    {
        $pdf = Pdf::loadView('pdf.export.notification', [
            'export'  => $exportLog,
            'company' => $company,
            'batches' => $batches,
            'date'    => now()->format('d F Y'),
        ])->setPaper('a4', 'portrait');

        $path = "exports/{$exportLog->id}/notification.pdf";
        Storage::put($path, $pdf->output());
        return $path;
    }
}