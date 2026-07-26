<?php

namespace App\Http\Requests\Organization;

use App\Models\Organization;
use App\Support\SocialPlatforms;
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
     * "instagram.com/klubku", or the full URL. SocialPlatforms turns all three
     * into a profile URL so everything downstream (settings form, public page)
     * only ever deals with a link it can render as an anchor.
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
        /** @var Organization $org */
        $org = $this->attributes->get('organization');

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
            ...SocialPlatforms::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, dan tanda hubung.',
            'slug.unique' => 'Slug ini sudah dipakai organisasi lain.',
            ...SocialPlatforms::messages(),
        ];
    }
}
