<?php

namespace App\Http\Requests\Rfid;

use App\Models\RfidTagEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Dipakai untuk "batalkan scan" (reason bebas) dan "lepas tag" (reason baku + catatan). */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('*.release-tag')) {
            return [
                'reason' => ['required', Rule::in(RfidTagEvent::REASONS)],
                'note'   => ['nullable', 'string', 'max:255'],
            ];
        }

        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }
}
