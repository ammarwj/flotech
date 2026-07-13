<?php

namespace App\Http\Requests\Certificate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificateTemplateRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'background_url' => ['sometimes', 'required', 'string', 'max:2048'],
            'orientation' => ['sometimes', 'nullable', Rule::in(['landscape', 'portrait'])],

            'fields' => ['sometimes', 'required', 'array', 'min:1'],
            'fields.*.key' => ['required', 'string', Rule::in(array_keys((array) config('certificate.fields')))],
            'fields.*.x' => ['required', 'numeric', 'between:0,100'],
            'fields.*.y' => ['required', 'numeric', 'between:0,100'],
            'fields.*.size' => ['required', 'numeric', 'between:6,120'],
            'fields.*.color' => ['nullable', 'string', 'max:9'],
            'fields.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'fields.*.bold' => ['nullable', 'boolean'],
            'fields.*.uppercase' => ['nullable', 'boolean'],
        ];
    }
}
