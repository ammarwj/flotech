<?php

namespace App\Http\Requests\Certificate;

use App\Http\Requests\Concerns\ChecksTemplateFieldKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCertificateTemplateRequest extends FormRequest
{
    use ChecksTemplateFieldKeys;

    public function authorize(): bool
    {
        return true; // tenant middleware already proved membership
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkFieldKeys($v, $this, 'certificate'));
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
            /*
             * Shape only. WHICH keys exist is no longer a deploy-time list —
             * a template may place any question the organizer asked on one of
             * their own events' registration forms — so the membership test
             * lives in ChecksTemplateFieldKeys, where the organization is in
             * hand. A `Rule::in(config(...))` here as well would be a second,
             * narrower answer that silently refuses every custom key.
             */
            'fields.*.key' => ['required', 'string', 'max:160'],
            'fields.*.x' => ['required', 'numeric', 'between:0,100'],
            'fields.*.y' => ['required', 'numeric', 'between:0,100'],
            'fields.*.size' => ['required', 'numeric', 'between:6,120'],
            'fields.*.color' => ['nullable', 'string', 'max:9'],
            'fields.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'fields.*.bold' => ['nullable', 'boolean'],
            'fields.*.uppercase' => ['nullable', 'boolean'],

            /*
             * The text box. Both optional, and both have to be present for a
             * field to be fitted: with no width there is nothing to wrap
             * against, with no height no limit to fit into. Absent on both
             * means the field renders exactly as every template saved before
             * this existed.
             *
             * `wrap` is the ID card's older spelling of `w`, accepted here so
             * one editor shape serves both surfaces.
             */
            'fields.*.w' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.h' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.wrap' => ['nullable', 'numeric', 'between:1,100'],
        ];
    }
}
