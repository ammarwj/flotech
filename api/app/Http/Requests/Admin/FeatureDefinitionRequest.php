<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeatureDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `feature_key` is code-facing — gating reads the literal string — so it is
     * held to the shape the seeders use. A key with spaces or capitals saves
     * fine and then matches nothing, the same silent failure as a misspelt key
     * in `SyncPlanFeaturesRequest`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('feature_definition')?->id ?? $this->route('feature_definition');

        return [
            'feature_key' => [
                'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('feature_definitions', 'feature_key')->ignore($id),
            ],
            'feature_label' => ['required', 'string', 'max:255'],
            'feature_group' => ['nullable', 'string', 'max:100'],
            'feature_type' => ['required', Rule::in(['boolean', 'numeric', 'text'])],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'feature_key.regex' => 'Feature key hanya boleh huruf kecil, angka, dan garis bawah (contoh: online_registration).',
        ];
    }
}
