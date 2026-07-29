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
            // Deliberately no "all three or none": an admin must be able to save
            // a half-filled form. Whether the account is usable is a different
            // question, asked at checkout by SiteSetting::hasBankAccount().
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_code' => ['nullable', 'string', 'max:10'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'account_holder' => ['nullable', 'string', 'max:100'],
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
            'bank_name.max' => 'Nama bank maksimal 60 karakter.',
            'account_number.max' => 'Nomor rekening maksimal 40 karakter.',
            'account_holder.max' => 'Nama pemilik rekening maksimal 100 karakter.',
            ...SocialPlatforms::messages(),
        ];
    }
}
