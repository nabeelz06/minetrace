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
        ];
    }
}
