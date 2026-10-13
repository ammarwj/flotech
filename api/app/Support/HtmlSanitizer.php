<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Turns the rich-text editor's HTML into HTML that is safe to print.
 *
 * Sanitising on WRITE, not on read, is the invariant: the column holds only
 * what has already been through here, so every reader — the public article
 * page, the admin preview, a future RSS feed, an export — is safe without
 * knowing this class exists. A reader-side filter would have to be remembered
 * at each of those places, and the one that forgets is the one that ships an
 * XSS to every visitor.
 *
 * The allowed set mirrors what the Tiptap toolbar can actually produce
 * (web/components/admin/rich-text-editor.tsx). Widening it means widening the
 * toolbar too — the two lists are a pair.
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(string $html): string
    {
        return trim(self::purifier()->purify($html));
    }

    /**
     * Plain text of the article, for excerpts and the reading-time estimate.
     */
    public static function toText(string $html): string
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '';

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Rough reading time in minutes. 200 wpm, floored at 1 — an article that
     * says "0 menit baca" reads as a bug, not as a short read.
     */
    public static function readMinutes(string $html): int
    {
        $words = str_word_count(self::toText($html), 0, '0123456789');

        return max(1, (int) ceil($words / 200));
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'em', 's', 'code',
            'h2[id]', 'h3[id]', 'h4[id]',
            'ul', 'ol', 'li',
            'blockquote', 'pre', 'hr',
            'a[href|title|target|rel]',
            'img[src|alt|width|height]',
        ]));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // Every outbound link opens in a new tab with the opener severed; the
        // editor cannot be trusted to set rel itself.
        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('Core.RemoveInvalidImg', true);
        // Writable cache dir: without one HTMLPurifier warns on every call in
        // containers where the vendor tree is read-only.
        $cache = storage_path('app/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0o775, true);
        }
        $config->set('Cache.SerializerPath', is_writable($cache) ? $cache : null);

        return self::$purifier = new HTMLPurifier($config);
    }
}
