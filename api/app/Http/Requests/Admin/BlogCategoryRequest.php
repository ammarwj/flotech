<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogCategoryRequest extends FormRequest
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
        $id = $this->route('blog_category')?->id;

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            // Unique on the column itself, not derived like a post's: a
            // category slug is typed once and then lives in every article URL
            // filter, so silently renaming it to "tips-2" would be worse than
            // a 422.
            'slug' => [
                'nullable', 'string', 'max:255',
                Rule::unique('blog_categories', 'slug')->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'slug.unique' => 'Slug kategori sudah dipakai.',
        ];
    }
}
