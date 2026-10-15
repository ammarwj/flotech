<?php

namespace App\Http\Requests\Race;

use Illuminate\Foundation\Http\FormRequest;

class StoreRaceCategoryRequest extends FormRequest
{
    use Concerns\ValidatesRacePrices;

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
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ] + $this->priceRules('required');
    }
}
