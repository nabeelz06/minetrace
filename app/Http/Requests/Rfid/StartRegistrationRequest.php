<?php

namespace App\Http\Requests\Rfid;

use Illuminate\Foundation\Http\FormRequest;

class StartRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_id'  => ['required', 'integer'],
            'reader_id' => ['nullable', 'integer'],
            // Hanya untuk input manual/USB: UID diketik/dipindai langsung (tanpa menunggu reader).
            'uid'       => ['nullable', 'string', 'min:4', 'max:50'],
            // Asal UID: manual (ketik/keyboard-wedge) | uhf_desktop (reader UHF desktop via Web Serial di Receiving)
            'source'    => ['nullable', 'string', 'in:manual,uhf_desktop'],
            'rssi'      => ['nullable', 'integer', 'between:-120,0'],
            'antenna'   => ['nullable', 'integer', 'between:0,255'],
            'tid'       => ['nullable', 'string', 'max:64'],
        ];
    }
}
