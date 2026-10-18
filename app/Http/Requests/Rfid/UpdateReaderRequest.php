<?php

namespace App\Http\Requests\Rfid;

use App\Models\RfidReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'stage'       => ['sometimes', 'nullable', Rule::in(StoreReaderRequest::stages())],
            'direction'   => ['sometimes', Rule::in(RfidReader::DIRECTIONS)],
            'device_type' => ['sometimes', Rule::in(RfidReader::DEVICE_TYPES)],
            'is_active'   => ['sometimes', 'boolean'],
        ] + StoreReaderRequest::connectionRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $existing  = RfidReader::where('company_id', $this->user()->company_id)->find($this->route('id'));
            $direction = $this->input('direction') ?? $existing?->direction;
            StoreReaderRequest::validateConnection($v, $direction, $existing);
        });
    }
}
