<?php

namespace App\Support;

/**
 * The social profiles flo-event knows how to render, shared by the organizer's
 * public page (`organizations.social_links`) and the platform's own footer
 * (`site_settings.social_links`).
 *
 * Both store the same shape — a `{platform: url}` map — so the base URLs and
 * the handle-to-URL normalization live here instead of being copied per table.
 * Adding a platform is one entry below plus one in `web/lib/social.ts`.
 */
class SocialPlatforms
{
    /**
     * Platform => base URL used to turn a bare handle into a full profile URL.
     *
     * @var array<string, string>
     */
    public const BASE_URLS = [
        'instagram' => 'https://instagram.com/',
        'youtube' => 'https://youtube.com/@',
        'x' => 'https://x.com/',
        'tiktok' => 'https://tiktok.com/@',
        'facebook' => 'https://facebook.com/',
    ];

    /**
     * People type social profiles however they like — "@klubku",
     * "instagram.com/klubku", or the full URL. Normalize all three into a
     * profile URL so everything downstream (settings forms, public pages)
     * only ever deals with a link it can render as an anchor.
     *
     * Every platform comes back present, `null` for the blank ones, so a
     * partial submission still clears what the user emptied.
     *
     * @return array<string, string|null>
     */
    public static function normalize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $normalized = [];

        foreach (self::BASE_URLS as $platform => $base) {
            $value = trim((string) ($input[$platform] ?? ''));

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

        return $normalized;
    }

    /**
     * Stored links as a complete map — every known platform is present, with
     * `null` where nobody filled one in, so a settings form can bind to a
     * stable shape.
     *
     * @param  array<string, string|null>|null  $stored
     * @return array<string, string|null>
     */
    public static function map(?array $stored): array
    {
        $stored ??= [];

        return array_map(
            fn (string $platform) => $stored[$platform] ?? null,
            array_combine(array_keys(self::BASE_URLS), array_keys(self::BASE_URLS)),
        );
    }

    /**
     * Validation rules for the `social_links.*` keys, so the two requests that
     * accept them can never disagree about what a legal link is.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (array_keys(self::BASE_URLS) as $platform) {
            $rules["social_links.{$platform}"] = ['nullable', 'url', 'max:255'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [];

        foreach (array_keys(self::BASE_URLS) as $platform) {
            $messages["social_links.{$platform}.url"] = 'Tautan tidak valid. Isi username atau URL profil lengkap.';
        }

        return $messages;
    }
}
