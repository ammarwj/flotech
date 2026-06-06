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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('feature_definition')?->id ?? $this->route('feature_definition');

        return [
            'feature_key' => [
                'required', 'string', 'max:100',
                Rule::unique('feature_definitions', 'feature_key')->ignore($id),
            ],
            'feature_label' => ['required', 'string', 'max:255'],
            'feature_group' => ['nullable', 'string', 'max:100'],
            'feature_type' => ['required', Rule::in(['boolean', 'numeric', 'text'])],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
