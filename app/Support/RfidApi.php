<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * RfidApi — format respons endpoint UI RFID.
 * Setiap respons membawa `code` stabil (dipetakan UI ke kamus i18n) + `message` (ID) + `message_en`.
 * Endpoint PERANGKAT (ESP32) tidak memakai kelas ini — bentuknya terkunci (lihat ApiRfidController).
 */
final class RfidApi
{
    /** code => [pesan ID, pesan EN] */
    public const MESSAGES = [
        'ok'                       => ['Berhasil.', 'Done.'],
        'forbidden'                => ['Anda tidak memiliki izin untuk tindakan ini.', 'You do not have permission for this action.'],
        'not_found'                => ['Data tidak ditemukan.', 'Not found.'],
        'validation_failed'        => ['Data yang dikirim belum benar.', 'The submitted data is not valid.'],
        'registration_started'     => ['Sesi registrasi dimulai. Tempelkan tag pada reader.', 'Registration started. Tap the tag on the reader.'],
        'registration_cancelled'   => ['Sesi registrasi dibatalkan.', 'Registration cancelled.'],
        'tag_registered'           => ['Tag berhasil didaftarkan.', 'Tag registered.'],
        'batch_already_tagged'     => ['Batch ini sudah memiliki tag. Lepas tag lama terlebih dahulu.', 'This batch already has a tag. Release the old tag first.'],
        'batch_closed'             => ['Batch ini sudah diekspor/ditutup dan tidak dapat diberi tag.', 'This batch is exported/closed and cannot be tagged.'],
        'batch_has_session'        => ['Batch ini sedang didaftarkan di reader lain.', 'This batch is already being registered at another reader.'],
        'reader_invalid'           => ['Reader tidak valid atau nonaktif.', 'Reader is invalid or disabled.'],
        'tag_in_use'               => ['Tag ini sudah dipakai batch lain.', 'This tag is already used by another batch.'],
        'batch_missing'            => ['Batch tidak ditemukan.', 'Batch not found.'],
        'no_session'               => ['Tidak ada sesi registrasi aktif.', 'No active registration session.'],
        'tag_unknown'              => ['Tag tidak dikenal. Daftarkan tag ini ke sebuah batch terlebih dahulu.', 'Unknown tag. Register this tag to a batch first.'],
        'direction_mismatch'       => ['Reader ini hanya untuk satu arah (Scan Masuk atau Scan Keluar).', 'This reader is fixed to one direction (Scan IN or Scan OUT).'],
        'busy'                     => ['Tag sedang diproses. Coba lagi sebentar.', 'The tag is being processed. Try again shortly.'],
        'scan_recorded'            => ['Scan tercatat.', 'Scan recorded.'],
        'scan_duplicate'           => ['Pembacaan yang sama baru saja tercatat (diabaikan).', 'The same read was just recorded (ignored).'],
        'simulation_disabled'      => ['Mode simulasi dinonaktifkan di sistem ini.', 'Simulation mode is disabled on this system.'],
        'void_not_scan'            => ['Hanya catatan scan yang dapat dibatalkan.', 'Only scan records can be voided.'],
        'void_already'             => ['Scan ini sudah dibatalkan.', 'This scan was already voided.'],
        'scan_voided'              => ['Scan dibatalkan. Riwayat tetap tersimpan.', 'Scan voided. The history is preserved.'],
        'tag_none'                 => ['Batch ini tidak memiliki tag.', 'This batch has no tag.'],
        'tag_released'             => ['Tag dilepas dari batch.', 'Tag released from the batch.'],
        'reader_created'           => ['Reader dibuat. Simpan kunci perangkat sekarang — tidak akan ditampilkan lagi.', 'Reader created. Save the device key now — it will not be shown again.'],
        'reader_updated'           => ['Reader diperbarui.', 'Reader updated.'],
        'reader_key_rotated'       => ['Kunci dirotasi. Kunci lama tidak berlaku lagi; simpan kunci baru sekarang.', 'Key rotated. The old key no longer works; save the new key now.'],
    ];

    public static function ok(string $code = 'ok', array $data = [], int $status = 200, array $extra = []): JsonResponse
    {
        [$id, $en] = self::MESSAGES[$code] ?? self::MESSAGES['ok'];

        return response()->json(array_merge([
            'success'    => true,
            'code'       => $code,
            'message'    => $id,
            'message_en' => $en,
            'data'       => $data,
        ], $extra), $status);
    }

    public static function fail(string $code, int $status = 422, array $data = [], array $extra = []): JsonResponse
    {
        [$id, $en] = self::MESSAGES[$code] ?? self::MESSAGES['validation_failed'];

        return response()->json(array_merge([
            'success'    => false,
            'code'       => $code,
            'message'    => $id,
            'message_en' => $en,
            'data'       => $data,
        ], $extra), $status);
    }
}
