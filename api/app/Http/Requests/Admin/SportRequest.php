<?php

namespace App\Http\Requests\Admin;

use App\Models\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SportRequest extends FormRequest
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
        $id = $this->route('sport')?->id ?? $this->route('sport');
        $creating = $this->isMethod('post');

        return [
            'slug' => [
                $creating ? 'required' : 'sometimes',
                'string', 'max:30', 'alpha_dash',
                Rule::unique('sports', 'slug')->ignore($id),
            ],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:8'],
            'scoring' => ['nullable', Rule::in(Sport::SCORING)],
            'default_match_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
