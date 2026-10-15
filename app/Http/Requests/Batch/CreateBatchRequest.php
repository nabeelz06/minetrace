<?php

namespace App\Http\Requests\Batch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * CreateBatchRequest — validasi dan otorisasi pembuatan batch baru.
 *
 * authorize() adalah gerbang pertama sebelum controller dipanggil.
 * Jika authorize() return false → Laravel otomatis throw 403
 * dengan pesan "This action is unauthorized."
 *
 * PERBAIKAN: role lama 'operator_lab' → 'operator_lab_station'
 */
class CreateBatchRequest extends FormRequest
{
    /**
     * Tentukan apakah user boleh membuat batch baru.
     *
     * ✅ company_admin        → boleh (full access)
     * ✅ operator_lab_station → boleh (tugas utama di Lab Kimia)
     * ✅ operator_wet         → boleh (tugas utama di Wet Process)
     * ❌ semua role lain      → 403 "This action is unauthorized."
     */
    public function authorize(): bool
    {
        return Auth::user()->hasAnyRole([
            'company_admin',
            'operator_lab_station',  
            'operator_wet',     
        ]);
    }

    /**
     * Rules validasi field batch baru.
     */
    public function rules(): array
    {
        return [
            'product_code_id' => ['required', 'integer', 'exists:product_codes,id'],
            'location_id'     => ['required', 'integer', 'exists:locations,id'],
            'initial_weight'  => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Pesan error yang user-friendly (bilingual).
     */
    public function messages(): array
    {
        $lang = Auth::user()?->language ?? 'id';

        return $lang === 'en' ? [
            'product_code_id.required' => 'Product code is required.',
            'product_code_id.exists'   => 'Selected product code is invalid.',
            'location_id.required'     => 'Location is required.',
            'location_id.exists'       => 'Selected location is invalid.',
            'initial_weight.required'  => 'Initial weight is required.',
            'initial_weight.numeric'   => 'Initial weight must be a number.',
            'initial_weight.min'       => 'Initial weight must be greater than 0.',
            'initial_weight.max'       => 'Initial weight cannot exceed 999,999.99 kg.',
        ] : [
            'product_code_id.required' => 'Kode produk wajib dipilih.',
            'product_code_id.exists'   => 'Kode produk yang dipilih tidak valid.',
            'location_id.required'     => 'Lokasi wajib dipilih.',
            'location_id.exists'       => 'Lokasi yang dipilih tidak valid.',
            'initial_weight.required'  => 'Berat awal wajib diisi.',
            'initial_weight.numeric'   => 'Berat awal harus berupa angka.',
            'initial_weight.min'       => 'Berat awal harus lebih dari 0.',
            'initial_weight.max'       => 'Berat awal tidak boleh melebihi 999.999,99 kg.',
        ];
    }

    /**
     * Attribute names untuk pesan error (bilingual).
     */
    public function attributes(): array
    {
        $lang = Auth::user()?->language ?? 'id';

        return $lang === 'en' ? [
            'product_code_id' => 'product code',
            'location_id'     => 'location',
            'initial_weight'  => 'initial weight',
        ] : [
            'product_code_id' => 'kode produk',
            'location_id'     => 'lokasi',
            'initial_weight'  => 'berat awal',
        ];
    }
}