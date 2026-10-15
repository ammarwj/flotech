<?php

namespace App\Http\Requests\Race;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRaceCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        // `sometimes`, which is what lets an organizer rename a category
        // without resending its whole price matrix. An absent key leaves the
        // stored prices alone; an empty array clears them, which reads as
        // "not sold in any period" — see the migration.
        ] + $this->priceRules('sometimes');
    }
}
