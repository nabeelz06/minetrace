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

    /** Input kosong ("") pada kolom opsional dianggap tidak diisi (lot_number UNIQUE tidak boleh menyimpan ""). */
    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['lot_number', 'partner_name', 'origin_location', 'partner_signatory'] as $k) {
            if ($this->has($k)) {
                $v = trim((string) $this->input($k));
                $clean[$k] = $v === '' ? null : $v;
            }
        }
        $this->merge($clean);
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
            // Data mitra PT Timah (semua opsional): no. partai/kode mitra unik, nama mitra, lokasi asal, penanda tangan.
            'lot_number'        => ['nullable', 'string', 'max:80', Rule::unique('batches', 'lot_number')],
            'partner_name'      => ['nullable', 'string', 'max:120'],
            'origin_location'   => ['nullable', 'string', 'max:120'],
            'partner_signatory' => ['nullable', 'string', 'max:120'],
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
            'lot_number.unique'        => 'This lot / partner number is already used by another batch.',
        ] : [
            'product_code_id.required' => 'Kode produk wajib dipilih.',
            'product_code_id.exists'   => 'Kode produk yang dipilih tidak valid.',
            'location_id.required'     => 'Lokasi wajib dipilih.',
            'location_id.exists'       => 'Lokasi yang dipilih tidak valid.',
            'initial_weight.required'  => 'Berat awal wajib diisi.',
            'initial_weight.numeric'   => 'Berat awal harus berupa angka.',
            'initial_weight.min'       => 'Berat awal harus lebih dari 0.',
            'initial_weight.max'       => 'Berat awal tidak boleh melebihi 999.999,99 kg.',
            'lot_number.unique'        => 'No. partai / kode mitra ini sudah dipakai batch lain.',
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
            'lot_number'      => 'lot / partner number',
            'partner_name'    => 'partner name',
            'origin_location' => 'origin location',
            'partner_signatory' => 'partner signatory',
        ] : [
            'product_code_id' => 'kode produk',
            'location_id'     => 'lokasi',
            'initial_weight'  => 'berat awal',
            'lot_number'      => 'no. partai / kode mitra',
            'partner_name'    => 'nama mitra',
            'origin_location' => 'lokasi asal',
            'partner_signatory' => 'penanda tangan mitra',
        ];
    }
}