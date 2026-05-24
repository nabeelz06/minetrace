<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * CreateUserRequest — validasi form pembuatan user baru.
 */
class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('super_admin');
    }

    public function rules(): array
    {
        return [
            'company_id'  => 'nullable|exists:companies,id',
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'password'    => ['required', Password::min(8)->letters()->numbers()->symbols()],
            'role'        => 'required|exists:roles,name',
            'employee_id' => 'nullable|string|max:50',
            'department'  => 'nullable|string|max:100',
            'phone'       => 'nullable|string|max:20',
            'language'    => 'nullable|in:id,en',
            'is_active'   => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'   => 'Email sudah terdaftar dalam sistem.',
            'role.required'  => 'Role wajib dipilih.',
            'role.exists'    => 'Role tidak valid.',
        ];
    }
}