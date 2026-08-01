<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
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
        $planId = $this->route('plan')?->id ?? $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required', 'string', 'max:50',
                Rule::unique('plans', 'slug')->ignore($planId),
            ],
            'description' => ['nullable', 'string'],
            // One price, charged once, for one event. There is no cycle to
            // discount and nothing derived from this — what is typed here is
            // what EventPlanOrderService charges.
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
