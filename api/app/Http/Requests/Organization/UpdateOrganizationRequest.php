<?php

namespace App\Http\Requests\Organization;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Organizers type social profiles however they like — "@klubku",
     * "instagram.com/klubku", or the full URL. Normalize all three into a
     * profile URL here so everything downstream (settings form, public page)
     * only ever deals with a link it can render as an anchor.
     */
    protected function prepareForValidation(): void
    {
        $social = $this->input('social_links');

        if (! is_array($social)) {
            return;
        }

        $normalized = [];

        foreach (Organization::SOCIAL_PLATFORMS as $platform => $base) {
            $value = trim((string) ($social[$platform] ?? ''));

            if ($value === '') {
                $normalized[$platform] = null;

                continue;
            }

            $normalized[$platform] = match (true) {
                (bool) preg_match('#^https?://#i', $value) => $value,
                // Looks like a bare domain ("instagram.com/klubku") — just add the scheme.
                (bool) preg_match('#^(www\.)?[a-z0-9-]+\.[a-z]{2,}/#i', $value) => 'https://'.$value,
                default => $base.ltrim($value, '@/'),
            };
        }

        $this->merge(['social_links' => $normalized]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Organization $org */
        $org = $this->attributes->get('organization');

        $socialRules = [];

        foreach (array_keys(Organization::SOCIAL_PLATFORMS) as $platform) {
            $socialRules["social_links.{$platform}"] = ['nullable', 'url', 'max:255'];
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('organizations', 'slug')->ignore($org->id),
            ],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'banner_url' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'social_links' => ['nullable', 'array'],
            ...$socialRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, dan tanda hubung.',
            'slug.unique' => 'Slug ini sudah dipakai organisasi lain.',
        ];

        foreach (array_keys(Organization::SOCIAL_PLATFORMS) as $platform) {
            $messages["social_links.{$platform}.url"] = 'Tautan tidak valid. Isi username atau URL profil lengkap.';
        }

        return $messages;
    }
}
