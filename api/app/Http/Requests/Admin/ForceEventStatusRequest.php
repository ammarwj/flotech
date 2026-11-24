<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Any status in `Event::TRANSITIONS` is a valid target — deliberately not
 * restricted to "backward" moves. See EventController::forceStatus() for why.
 */
class ForceEventStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(Event::TRANSITIONS))],
            // NOT NULL in the ledger row, same call as standing_adjustments.reason:
            // a status forced with nothing explaining it is worse than the
            // mistake it is meant to undo.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Pilih status tujuan.',
            'status.in' => 'Status tidak dikenal.',
            'reason.required' => 'Tulis alasan memaksa status ini.',
            'reason.max' => 'Alasan maksimal 500 karakter.',
        ];
    }
}
