<?php

namespace App\Http\Requests\Rfid;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uid'       => ['required', 'string', 'min:4', 'max:50'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'stage'     => ['nullable', Rule::in(StoreReaderRequest::STAGES)],
            'reader_id' => ['nullable', 'integer'],
            'event_id'  => ['nullable', 'string', 'regex:/^[A-Za-z0-9._:\-]{8,64}$/'],
            'simulate'  => ['sometimes', 'boolean'],
        ];
    }
}
