<?php

namespace App\Http\Requests\Rfid;

use App\Models\RfidTagEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Permintaan "Kelola Tag" berbasis UID: cek status (query), lepas tag (alasan baku + catatan), reset uji (catatan).
 * Otorisasi per aksi dilakukan di controller lewat Gate (rfid.view / rfid.operate / rfid.manage).
 */
class TagUidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uid = ['required', 'string', 'min:4', 'max:100'];

        if ($this->routeIs('*.tags.release')) {
            return [
                'uid'    => $uid,
                'reason' => ['required', Rule::in(RfidTagEvent::REASONS)],
                'note'   => ['nullable', 'string', 'max:255'],
            ];
        }

        if ($this->routeIs('*.tags.reset')) {
            return ['uid' => $uid, 'note' => ['nullable', 'string', 'max:255']];
        }

        return ['uid' => $uid];
    }
}
