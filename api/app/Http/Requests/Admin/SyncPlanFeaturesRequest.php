<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncPlanFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Body shape: { "features": { "max_active_events": "10", "qr_tickets": "true" } }
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'features' => ['required', 'array'],
            'features.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
