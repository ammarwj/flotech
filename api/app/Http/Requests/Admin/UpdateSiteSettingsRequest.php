<?php

namespace App\Http\Requests\Admin;

use App\Support\SocialPlatforms;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same handle-or-URL leniency organizers get on their own profiles —
     * SocialPlatforms turns "@floevent", "tiktok.com/@floevent" and a full URL
     * into the one thing the footer can render: a link.
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('social_links'))) {
            return;
        }

        $this->merge(['social_links' => SocialPlatforms::normalize($this->input('social_links'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'sales_email' => ['nullable', 'email', 'max:255'],
            'social_links' => ['nullable', 'array'],
            ...SocialPlatforms::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_email.email' => 'Format email kontak tidak valid.',
            'sales_email.email' => 'Format email sales tidak valid.',
            'contact_phone.max' => 'Nomor telepon maksimal 20 karakter.',
            ...SocialPlatforms::messages(),
        ];
    }
}
