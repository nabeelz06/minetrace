<?php

namespace App\Http\Requests\Lab;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * CreateLabAnalysisRequest — validasi form input analisis lab LTJ.
 */
class CreateLabAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->hasRole([
            'company_admin', 'operator_lab'
        ]);
    }

    public function rules(): array
    {
        return [
            'location_id'     => 'nullable|exists:locations,id',
            'la_pct'          => 'nullable|numeric|min:0|max:100',
            'ce_pct'          => 'nullable|numeric|min:0|max:100',
            'pr_pct'          => 'nullable|numeric|min:0|max:100',
            'nd_pct'          => 'nullable|numeric|min:0|max:100',
            'y_pct'           => 'nullable|numeric|min:0|max:100',
            'tho2_pct'        => 'nullable|numeric|min:0|max:100',
            'u3o8_pct'        => 'nullable|numeric|min:0|max:100',
            'lab_method'      => 'nullable|string|max:50',
            'equipment_used'  => 'nullable|string|max:100',
            'sample_weight_g' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'la_pct.numeric' => 'Nilai La (%) harus berupa angka.',
            'ce_pct.numeric' => 'Nilai Ce (%) harus berupa angka.',
            'pr_pct.numeric' => 'Nilai Pr (%) harus berupa angka.',
            'nd_pct.numeric' => 'Nilai Nd (%) harus berupa angka.',
            'y_pct.numeric'  => 'Nilai Y (%) harus berupa angka.',
        ];
    }

    /**
     * Hitung total REO setelah validasi berhasil.
     */
    public function totalReo(): float
    {
        return round(
            ($this->la_pct ?? 0) + ($this->ce_pct ?? 0) +
            ($this->pr_pct ?? 0) + ($this->nd_pct ?? 0) + ($this->y_pct ?? 0),
            3
        );
    }
}