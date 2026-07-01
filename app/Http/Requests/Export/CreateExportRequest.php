<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * CreateExportRequest — validasi form pembuatan ekspor baru.
 */
class CreateExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->hasAnyRole(['company_admin', 'operator_warehouse']);
    }

    public function rules(): array
    {
        return [
            'batch_ids'             => 'required|array|min:1',
            'batch_ids.*'           => 'integer|exists:batches,id',
            'export_type'           => 'required|in:international,domestic',
            'destination_country'   => 'required_if:export_type,international|nullable|string|max:100',
            'destination_company'   => 'required|string|max:255',
            'destination_address'   => 'nullable|string|max:500',
            'manifest_number'       => 'nullable|string|max:100',
            'invoice_number'        => 'nullable|string|max:100',
            'container_number'      => 'nullable|string|max:100',
            'vessel_name'           => 'nullable|string|max:255',
            'port_of_loading'       => 'nullable|string|max:100',
            'port_of_discharge'     => 'nullable|string|max:100',
            'value_usd'             => 'nullable|numeric|min:0',
            'notes'                 => 'nullable|string|max:2000',
            'warnings_acknowledged' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'batch_ids.required'              => 'Pilih minimal 1 batch untuk diekspor.',
            'destination_company.required'    => 'Nama perusahaan tujuan wajib diisi.',
            'destination_country.required_if' => 'Negara tujuan wajib diisi untuk ekspor internasional.',
        ];
    }
}