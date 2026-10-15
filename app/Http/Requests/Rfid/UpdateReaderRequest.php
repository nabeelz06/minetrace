<?php

namespace App\Http\Requests\Rfid;

use App\Models\RfidReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:100'],
            'stage'       => ['sometimes', 'nullable', Rule::in(StoreReaderRequest::STAGES)],
            'direction'   => ['sometimes', Rule::in(RfidReader::DIRECTIONS)],
            'device_type' => ['sometimes', Rule::in(RfidReader::DEVICE_TYPES)],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
