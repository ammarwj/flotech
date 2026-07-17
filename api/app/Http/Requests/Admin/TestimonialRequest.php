<?php

namespace App\Http\Requests\Admin;

use App\Models\Testimonial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestimonialRequest extends FormRequest
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
        return [
            'quote' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'initials' => ['required', 'string', 'max:4'],
            'avatar_preset' => ['required', Rule::in(Testimonial::PRESETS)],
            'rating' => ['integer', 'min:1', 'max:5'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quote.required' => 'Kutipan wajib diisi.',
            'name.required' => 'Nama wajib diisi.',
            'role.required' => 'Peran wajib diisi.',
            'initials.required' => 'Inisial wajib diisi.',
            'initials.max' => 'Inisial maksimal 4 karakter.',
            'avatar_preset.in' => 'Preset avatar tidak dikenal.',
            'rating.min' => 'Rating minimal 1 bintang.',
            'rating.max' => 'Rating maksimal 5 bintang.',
        ];
    }
}
