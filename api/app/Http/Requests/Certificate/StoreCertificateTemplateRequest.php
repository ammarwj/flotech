<?php

namespace App\Http\Requests\Certificate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant middleware already proved membership
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'background_url' => ['required', 'string', 'max:2048'],
            'orientation' => ['nullable', Rule::in(['landscape', 'portrait'])],

            'fields' => ['required', 'array', 'min:1'],
            // Only keys the renderer knows how to fill, so a template can never
            // place a field that would print blank.
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
