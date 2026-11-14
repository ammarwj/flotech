<?php

namespace App\Support;

/**
 * Hostname shaping for the subdomain half of the domain add-on.
 *
 * A subdomain is bought as a *label* ("liga-a") and stored as a full hostname
 * ("liga-a.floevent.id"), because that is what every reader downstream needs:
 * `DomainService::active()` keys its routing map by hostname, the generated
 * nginx `server_name` is a hostname, and certbot is handed a hostname. Keeping
 * only the label would make each of those recompose it, and a second composer
 * is how one of them ends up pointing at the wrong zone.
 *
 * The label is kept beside it in `events.subdomain` all the same: it is what the
 * organizer typed, what the form must re-seed, and the only thing we can check
 * for uniqueness without re-parsing a hostname.
 *
 * Deliberately not in DomainService: that class owns *moving a domain forward*
 * (certificate, vhost, reload) and nothing here touches any of the three.
 */
class DomainNames
{
    /**
     * A single DNS label: a-z, 0-9 and inner hyphens, 3–40 characters.
     *
     * Longer than DNS itself allows (63) would still be legal, but the whole
     * name has to fit a certificate and read as a brand; shorter than three
     * would let an organizer take `a` and exhaust the good names in an
     * afternoon.
     */
    private const LABEL = '/^(?!-)[a-z0-9-]{3,40}(?<!-)$/';

    /**
     * Labels the platform serves itself, or intends to.
     *
     * The three the nginx config already answers on (`www`, `api`, plus the
     * apex) would collide outright — two server blocks for one name, and nginx
     * serves whichever it loaded first, exactly the failure `domains.reserved`
     * exists to prevent. The rest are held back because handing an organizer
     * `admin.floevent.id` or `mail.floevent.id` lets them put a page that looks
     * like ours on a name visitors would trust.
     *
     * @var list<string>
     */
    public const RESERVED_LABELS = [
        'www', 'api', 'admin', 'app', 'mail', 'smtp', 'imap', 'webmail',
        'ftp', 'cdn', 'static', 'assets', 'img', 'images', 'media',
        'blog', 'docs', 'help', 'support', 'status', 'dashboard',
        'staging', 'dev', 'test', 'demo', 'beta', 'sandbox',
        'login', 'auth', 'account', 'accounts', 'billing', 'pay',
        'event', 'events', 'floevent', 'flo', 'ns1', 'ns2', 'mx',
    ];

    /** The domain subdomains are issued under, e.g. `floevent.id`. */
    public static function platformDomain(): string
    {
        return mb_strtolower((string) config('domains.platform_domain'));
    }

    /**
     * Normalise whatever the organizer typed into a bare label.
     *
     * Accepts `Liga A`, `liga-a.floevent.id`, `https://liga-a.floevent.id/` —
     * all of which are the same request written by someone who pasted a URL or
     * typed a name with a space. Anything still not a label after this is
     * refused rather than repaired: silently turning a typo into a different
     * valid label would hand out a hostname nobody asked for, the same rule
     * DomainService::normalize() follows.
     */
    public static function normalizeLabel(string $input): string
    {
        $value = mb_strtolower(trim($input));
        $value = preg_replace('#^[a-z]+://#', '', $value) ?? '';
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];

        // A pasted full hostname: keep the first label only. Done before the
        // space-to-hyphen pass so "liga a.floevent.id" cannot smuggle a dot in.
        $suffix = '.'.self::platformDomain();
        if ($suffix !== '.' && str_ends_with($value, $suffix)) {
            $value = mb_substr($value, 0, -mb_strlen($suffix));
        }

        $value = explode('.', $value)[0];
        // Spaces and underscores are what people type for a hyphen.
        $value = preg_replace('/[\s_]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    public static function isValidLabel(string $label): bool
    {
        return (bool) preg_match(self::LABEL, $label);
    }

    public static function isReservedLabel(string $label): bool
    {
        return in_array($label, self::RESERVED_LABELS, true);
    }

    /** The full hostname a label is served on. */
    public static function hostFor(string $label): string
    {
        return $label.'.'.self::platformDomain();
    }
}
