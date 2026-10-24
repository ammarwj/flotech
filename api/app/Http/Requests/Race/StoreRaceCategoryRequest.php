<?php

namespace App\Http\Requests\Race;

use Illuminate\Foundation\Http\FormRequest;

class StoreRaceCategoryRequest extends FormRequest
{
    use Concerns\ValidatesEmailDomains;
    use Concerns\ValidatesRacePrices;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeDomains($this->all()));
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
            // `nullable` rather than `present`: a category with no condition is
            // the ordinary case, so a client that never heard of this field
            // must still be able to create one.
        ] + $this->domainRules('nullable') + $this->priceRules('required');
    }
}
