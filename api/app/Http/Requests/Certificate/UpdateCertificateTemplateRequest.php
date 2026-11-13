<?php

namespace App\Http\Requests\Certificate;

use App\Http\Requests\Concerns\ChecksTemplateFieldKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCertificateTemplateRequest extends FormRequest
{
    use ChecksTemplateFieldKeys;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Only when the client actually sent the list. A PATCH that changes
            // nothing but the name must not be told its (absent) fields are
            // malformed.
            if ($this->has('fields')) {
                $this->checkFieldKeys($v, $this, 'certificate');
            }
        });
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
            // Shape only — see the Store twin for why membership moved out.
            'fields.*.key' => ['required', 'string', 'max:160'],
            'fields.*.x' => ['required', 'numeric', 'between:0,100'],
            'fields.*.y' => ['required', 'numeric', 'between:0,100'],
            'fields.*.size' => ['required', 'numeric', 'between:6,120'],
            'fields.*.color' => ['nullable', 'string', 'max:9'],
            'fields.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'fields.*.bold' => ['nullable', 'boolean'],
            'fields.*.uppercase' => ['nullable', 'boolean'],

            // The text box — see the Store twin for why both halves are
            // optional and what their absence means.
            'fields.*.w' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.h' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.wrap' => ['nullable', 'numeric', 'between:1,100'],
        ];
    }
}
