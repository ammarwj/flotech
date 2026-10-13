<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store and update share one rule set, with `presence()` flipping between
 * `required` and `sometimes` — the same shape as PayoutDestinationRequest.
 * Two copies would mean one door accepting an article the other rejects.
 */
class BlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** `required` when creating, `sometimes` when patching an existing row. */
    private function presence(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'title' => [$presence, 'string', 'max:255'],
            // Sanitised server-side, so no HTML rule here — what matters is
            // that something was written at all.
            'body' => [$presence, 'string'],
            // Optional everywhere: blank means "derive from the body".
            'slug' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'blog_category_id' => ['nullable', 'uuid', 'exists:blog_categories,id'],
            'cover_url' => ['nullable', 'string', 'max:2048'],
            'author_name' => ['nullable', 'string', 'max:255'],
            // Null = draft. A future date is allowed on purpose: scheduling is
            // why this is a timestamp rather than the is_active boolean the
            // other CMS tables use.
            'published_at' => ['nullable', 'date'],
            'is_featured' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'title.max' => 'Judul maksimal 255 karakter.',
            'body.required' => 'Isi artikel wajib diisi.',
            'excerpt.max' => 'Ringkasan maksimal 500 karakter.',
            'blog_category_id.exists' => 'Kategori tidak ditemukan.',
            'published_at.date' => 'Tanggal terbit tidak valid.',
        ];
    }
}
