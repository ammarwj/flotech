<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-only validation. The rules that need to look at other rows (already
 * taken, reserved by the platform, event still a draft) live in
 * DomainService::assign() — that is the single write path, and splitting the
 * checks across two files is how one of them ends up enforced on only one route.
 */
class UpdateEventDomainRequest extends FormRequest
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
            // Nullable, not required: sending null is how a domain is detached.
            'custom_domain' => ['present', 'nullable', 'string', 'max:253'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_domain.present' => 'Kolom domain harus dikirim (kosongkan untuk melepas domain).',
            'custom_domain.max' => 'Domain terlalu panjang.',
        ];
    }
}
