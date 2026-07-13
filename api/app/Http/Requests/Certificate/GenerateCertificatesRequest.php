<?php

namespace App\Http\Requests\Certificate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateCertificatesRequest extends FormRequest
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
            'certificate_template_id' => ['required', 'uuid'],
            'award_title' => ['required', 'string', 'max:120'],

            'recipients' => ['required', 'array', 'min:1', 'max:500'],
            'recipients.*.type' => ['required', Rule::in(['team', 'player'])],
            'recipients.*.id' => ['required', 'uuid'],

            // Only honoured when the plan includes `certificate_email`; the
            // controller rejects it otherwise rather than silently ignoring it.
            'send_email' => ['nullable', 'boolean'],
        ];
    }
}
