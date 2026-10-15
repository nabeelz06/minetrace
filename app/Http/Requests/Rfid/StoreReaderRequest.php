<?php

namespace App\Http\Requests\Rfid;

use App\Models\RfidReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReaderRequest extends FormRequest
{
    public const STAGES = ['receiving', 'wet_process', 'dry_process', 'warehouse', 'project_plant'];

    public function authorize(): bool
    {
        return true; // otorisasi di controller (Gate rfid.manage)
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                              Rule::unique('rfid_readers', 'code')->where('company_id', $this->user()->company_id)],
            'name'        => ['required', 'string', 'max:100'],
            'stage'       => ['nullable', Rule::in(self::STAGES)],
            'direction'   => ['required', Rule::in(RfidReader::DIRECTIONS)],
            'device_type' => ['required', Rule::in(RfidReader::DEVICE_TYPES)],
        ];
    }
}
