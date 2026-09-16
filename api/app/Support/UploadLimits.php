<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The one place that answers "how big may an upload be, and what do we say when
 * it isn't?".
 *
 * Two limits decide this, and only the smaller one is real: the app's own
 * `max:` rule, and PHP's `upload_max_filesize`/`post_max_size`. PHP wins because
 * it throws the bytes away before any validator sees them — which is exactly how
 * this went wrong: the image shipped no php.ini, PHP defaulted to 2M, and a 4 MB
 * file came back as "The file failed to upload." while every message in the UI
 * promised 5 MB. The rule was 2 MB and nothing said so.
 *
 * So the message quotes the *effective* limit, computed here rather than typed
 * into a string. A deploy whose php.ini is smaller than MAX_KB still tells the
 * truth about itself instead of repeating what the code wishes were true.
 *
 * api/Dockerfile sets both ini values well above nginx's own cap so that in
 * practice the app is always the layer that refuses and MAX_KB is always the
 * number quoted. This class is the net under that arrangement, not a substitute
 * for it: PHP dropping a body whole is the one failure it cannot dress up into
 * a good message, because by then the request has no size to report.
 */
class UploadLimits
{
    /** The app's own cap, shared by every upload endpoint (kilobytes). */
    public const MAX_KB = 5120;

    /** The smaller of our cap and what PHP will actually accept, in kilobytes. */
    public static function effectiveKb(): int
    {
        return (int) min(
            self::MAX_KB,
            self::iniKb('upload_max_filesize'),
            // Covers the whole multipart body, so it bounds the file too.
            self::iniKb('post_max_size'),
        );
    }

    /** That limit as something to show a person: "5 MB", "1,5 MB". */
    public static function label(): string
    {
        $mb = self::effectiveKb() / 1024;

        return rtrim(rtrim(number_format($mb, 1, ',', '.'), '0'), ',').' MB';
    }

    /** Validation message for the `max:` rule, so both paths read the same. */
    public static function maxMessage(): string
    {
        return 'Ukuran berkas maksimal '.self::label().'.';
    }

    /**
     * Turn PHP's two silent oversize failures into the message above.
     *
     * Must run *before* validate(): by the time the validator looks, PHP has
     * already produced one of two lies about a file that was sent in full.
     *
     * - over `upload_max_filesize` — the field survives carrying UPLOAD_ERR_INI_SIZE,
     *   and the `file` rule reports "The file failed to upload", naming no size.
     * - over `post_max_size` — PHP discards the entire body, so $_POST and $_FILES
     *   are empty and `required` reports a *missing* field.
     */
    public static function guard(Request $request, string $field = 'file'): void
    {
        $fail = fn () => throw ValidationException::withMessages([$field => self::maxMessage()]);

        // The body was dropped wholesale: bytes were sent, nothing parsed out of
        // them. Narrowed to multipart because that is the only encoding PHP
        // parses into $_POST — a JSON body leaves both bags empty too, and is a
        // perfectly ordinary request that must not be blamed for its size.
        if (str_contains((string) $request->header('Content-Type'), 'multipart/form-data')
            && (int) $request->server('CONTENT_LENGTH') > 0
            && $request->post() === []
            && $request->allFiles() === []) {
            $fail();
        }

        $file = $request->file($field);

        if ($file instanceof UploadedFile
            && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $fail();
        }
    }

    /** Parse a php.ini shorthand ("10M", "512K", "1G") into kilobytes. */
    protected static function iniKb(string $directive): float
    {
        $raw = trim((string) ini_get($directive));

        // Both directives treat 0 (and an unset value) as "no limit".
        if ($raw === '' || $raw === '0') {
            return INF;
        }

        $value = (float) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024,
            'm' => $value * 1024,
            'k' => $value,
            default => $value / 1024, // plain bytes
        };
    }
}
