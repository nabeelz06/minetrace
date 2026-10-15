<?php

namespace App\Services\Batch;

use App\Models\Batch;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * QrCodeService — generate dan verifikasi QR Code untuk batch.
 *
 * Payload QR = URL verifikasi publik:
 *   {APP_URL}/verify/batch/{batch_id}?code={batch_code}&cs={checksum}
 * cs = 12 karakter pertama HMAC-SHA256("{id}:{batch_code}:{company_id}") dengan APP_KEY.
 * APP_KEY JANGAN dirotasi: QR yang sudah tercetak di karung akan gagal verifikasi.
 *
 * Arsitektur fleksibel:
 * - Saat RFID ditambahkan, QrCodeService tetap bisa dipakai paralel
 * - Format QR bisa diubah tanpa breaking change (checksum mencegah tampering)
 */
class QrCodeService
{
    /**
     * Generate QR Code SVG untuk batch dan simpan ke storage.
     *
     * @param  Batch  $batch  Batch yang akan dibuatkan QR
     * @return string         Path file QR Code yang disimpan
     */
    public function generateForBatch(Batch $batch): string
    {
        $payload  = $this->buildPayload($batch);
        $svgData  = QrCode::format('svg')
            ->size(300)
            ->margin(1)
            ->errorCorrection('H')
            ->generate($payload);

        $path = "qrcodes/{$batch->company_id}/{$batch->id}.svg";
        Storage::put($path, $svgData);

        // Update batch dengan path QR
        $batch->update(['qr_code_path' => $path]);

        return $path;
    }

    /**
     * Build payload string yang dikodekan ke dalam QR.
     */
    public function buildPayload(Batch $batch): string
    {
        $checksum = $this->generateChecksum($batch);
        $baseUrl  = config('app.url');

        return "{$baseUrl}/verify/batch/{$batch->id}?code={$batch->batch_code}&cs={$checksum}";
    }

    /**
     * Verifikasi QR Code — pastikan payload valid dan tidak dimanipulasi.
     *
     * @param  int    $batchId    ID batch dari URL
     * @param  string $batchCode  Kode batch dari URL
     * @param  string $checksum   Checksum dari URL
     * @return array              ['valid' => bool, 'batch' => Batch|null, 'message' => string]
     */
    public function verify(int $batchId, string $batchCode, string $checksum): array
    {
        $batch = Batch::with(['productCode', 'currentLocation', 'company'])
            ->find($batchId);

        if (!$batch) {
            return [
                'valid'   => false,
                'batch'   => null,
                'message' => 'Batch tidak ditemukan.',
            ];
        }

        if ($batch->batch_code !== $batchCode) {
            return [
                'valid'   => false,
                'batch'   => null,
                'message' => 'Kode batch tidak cocok.',
            ];
        }

        $expectedChecksum = $this->generateChecksum($batch);
        if (!hash_equals($expectedChecksum, $checksum)) {
            return [
                'valid'   => false,
                'batch'   => null,
                'message' => 'QR Code tidak valid atau sudah dimanipulasi.',
            ];
        }

        return [
            'valid'   => true,
            'batch'   => $batch,
            'message' => 'Batch terverifikasi.',
        ];
    }

    /**
     * Generate checksum HMAC untuk anti-tampering.
     */
    private function generateChecksum(Batch $batch): string
    {
        $data = "{$batch->id}:{$batch->batch_code}:{$batch->company_id}";
        return substr(hash_hmac('sha256', $data, config('app.key')), 0, 12);
    }

    /**
     * Ambil QR Code sebagai base64 (untuk embed di PDF/Vue).
     */
    public function getBase64(Batch $batch): ?string
    {
        if (!$batch->qr_code_path || !Storage::exists($batch->qr_code_path)) {
            $this->generateForBatch($batch);
        }

        $svg = Storage::get($batch->qr_code_path);
        return $svg ? 'data:image/svg+xml;base64,' . base64_encode($svg) : null;
    }
}