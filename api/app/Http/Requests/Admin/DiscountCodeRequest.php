<?php

namespace App\Http\Requests\Admin;

use App\Models\DiscountCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountCodeRequest extends FormRequest
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
        $id = $this->route('discount_code')?->id ?? $this->route('discount_code');
        $percent = $this->input('discount_type') === 'percent';

        return [
            // Uppercased before validation (prepareForValidation) so the unique
            // check cannot be fooled by case — LAUNCH50 and launch50 would
            // otherwise be two rows nobody can tell apart, and
            // DiscountCode::normalize() would match whichever came first.
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('discount_codes', 'code')->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(DiscountCode::TYPES)],
            // A percent above 100 would be refunding money; the clamp in
            // discountFor() stops it mattering, but accepting it here would
            // print "diskon 150%" on an admin screen.
            'discount_value' => ['required', 'numeric', 'min:0', ...($percent ? ['max:100'] : [])],
            // Only meaningful for percent codes; a fixed code's value already is
            // its ceiling.
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'once_per_organization' => ['boolean'],
            // Empty list = every plan. See DiscountCode::plans().
            'plan_ids' => ['array'],
            'plan_ids.*' => ['uuid', Rule::exists('plans', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => DiscountCode::normalize((string) $this->input('code'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh huruf kapital, angka, garis bawah, dan tanda hubung (contoh: LAUNCH50).',
            'code.unique' => 'Kode itu sudah dipakai.',
            'discount_value.max' => 'Diskon persentase tidak boleh lebih dari 100%.',
            'ends_at.after' => 'Tanggal berakhir harus setelah tanggal mulai.',
        ];
    }

    /**
     * The pivot is synced separately, so the controller never passes it to
     * fill() — `plan_ids` is not a column.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return collect($this->validated())->except('plan_ids')->all();
    }

    /**
     * @return array<int, string>
     */
    public function planIds(): array
    {
        return array_values(array_unique($this->input('plan_ids', [])));
    }
}
