<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The only thing allowed to move an event's custom domain forward.
 *
 * Three pieces of state have to agree, and they live in three different places:
 * the database row, a certificate on disk, and an nginx server block. This class
 * writes all three in one order and nothing else touches any of them — the same
 * reason WalletService owns every ledger write.
 *
 * Runs in the `scheduler` container, the only one that bind-mounts the host
 * directories. That is deliberate: certbot's exit code is then known in the same
 * process that holds the database connection, so the outcome is recorded where
 * it is learnt, with no handshake back from the host. The host keeps the one job
 * only it can do — reloading nginx.
 */
class DomainService
{
    private const CACHE_KEY = 'custom_domains_active';

    /** Hostname: labels of a-z 0-9 and hyphens, at least two of them. */
    private const PATTERN = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Live domains, for the Next middleware and the CORS allowlist.
     *
     * Only certified ones: a domain that is merely assigned has no certificate,
     * so serving it would hand visitors a TLS error instead of a page.
     *
     * @return array<string, array{org_slug: string, event_slug: string}>
     */
    public static function active(): array
    {
        return Cache::remember(self::CACHE_KEY, config('domains.cache_ttl'), function () {
            return Event::query()
                ->whereNotNull('custom_domain')
                ->whereNotNull('domain_certified_at')
                ->where('status', '!=', 'draft')
                ->with('organization:id,slug')
                ->get(['id', 'organization_id', 'slug', 'custom_domain'])
                ->mapWithKeys(fn (Event $event) => [
                    $event->custom_domain => [
                        'org_slug' => $event->organization->slug,
                        'event_slug' => $event->slug,
                    ],
                ])
                ->all();
        });
    }

    /**
     * Normalise what a human typed into a bare hostname.
     *
     * Accepts `https://Event-A.id/`, `EVENT-A.ID`, `event-a.id.` — all of which
     * are the same name written by someone who copied it out of a browser bar.
     * Anything still not shaped like a hostname after this is rejected rather
     * than repaired: silently turning a typo into a different valid domain would
     * request a certificate for a name nobody asked for.
     */
    public static function normalize(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];

        return rtrim($domain, '.');
    }

    /**
     * Attach a domain to an event (null detaches it).
     *
     * Clears every derived column: a reassigned domain has not been verified and
     * has no certificate, and leaving `domain_certified_at` behind would publish
     * a new hostname pointing at a certificate issued for the old one.
     */
    public function assign(Event $event, ?string $domain): Event
    {
        if ($domain === null || $domain === '') {
            return $this->release($event);
        }

        $domain = self::normalize($domain);

        if (! preg_match(self::PATTERN, $domain) || mb_strlen($domain) > 253) {
            throw new DomainException(
                'Format domain tidak valid. Tulis nama host saja, misalnya event-a.id.',
                ['custom_domain' => ['Format domain tidak valid.']],
            );
        }

        if (in_array($domain, config('domains.reserved'), true)) {
            throw new DomainException(
                'Domain ini dipakai platform dan tidak bisa diberikan ke event.',
                ['custom_domain' => ['Domain ini dipakai platform.']],
            );
        }

        // A draft is not publicly visible (see ResolvesPublicEvent), so its
        // domain would resolve to a 404 — and issuing a certificate for it burns
        // Let's Encrypt quota on a page nobody can open.
        if ($event->status === 'draft') {
            throw new DomainException(
                'Event masih draf. Publikasikan dulu sebelum memasang domain.',
                ['custom_domain' => ['Event masih draf.']],
            );
        }

        $taken = Event::where('custom_domain', $domain)->where('id', '!=', $event->id)->exists();
        if ($taken) {
            throw new DomainException(
                'Domain ini sudah dipakai event lain.',
                ['custom_domain' => ['Domain sudah dipakai event lain.']],
            );
        }

        // Changing to a different name retires the old certificate; keeping the
        // same name keeps it, so re-saving an active domain does not knock it
        // offline for the minute it takes to reissue.
        $previous = $event->custom_domain;
        if ($previous && $previous !== $domain) {
            $this->deleteCertificate($previous);
        }

        $event->forceFill([
            'custom_domain' => $domain,
            'domain_verified_at' => $previous === $domain ? $event->domain_verified_at : null,
            'domain_certified_at' => $previous === $domain ? $event->domain_certified_at : null,
            'domain_error' => null,
            'domain_attempted_at' => null,
        ])->save();

        self::flush();
        $this->publish();

        return $event;
    }

    /** Detach the domain and delete its certificate. */
    public function release(Event $event): Event
    {
        $domain = $event->custom_domain;

        $event->forceFill([
            'custom_domain' => null,
            'domain_verified_at' => null,
            'domain_certified_at' => null,
            'domain_error' => null,
            'domain_attempted_at' => null,
        ])->save();

        if ($domain) {
            $this->deleteCertificate($domain);
        }

        self::flush();
        $this->publish();

        return $event;
    }

    /**
     * Does this domain's A record point at us?
     *
     * Checked before every certificate request, because a domain whose DNS is
     * not ready yet fails the ACME challenge — and five of those in an hour lock
     * out every other domain on the account, not just this one.
     */
    public function verifyDns(string $domain): bool
    {
        $expected = (string) config('domains.server_ip');

        if ($expected === '') {
            return false;
        }

        $records = @dns_get_record($domain, DNS_A) ?: [];

        foreach ($records as $record) {
            if (($record['ip'] ?? null) === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify DNS, then issue the certificate and serve the domain.
     *
     * The port-80 block for this domain is published FIRST (publish() below
     * emits one as soon as a domain is assigned, certificate or not). Without
     * its server_name already registered, the ACME challenge for a new hostname
     * falls through to a neighbouring vhost and can never succeed.
     */
    public function issue(Event $event): bool
    {
        $domain = $event->custom_domain;

        if (! $domain) {
            throw new DomainException('Event ini belum punya custom domain.');
        }

        $event->forceFill(['domain_attempted_at' => now()])->save();

        if (! $this->verifyDns($domain)) {
            return $this->fail($event, sprintf(
                'DNS %s belum mengarah ke %s. Tambahkan A record, tunggu propagasi, lalu coba lagi.',
                $domain,
                config('domains.server_ip') ?: '(CUSTOM_DOMAIN_SERVER_IP belum diisi)',
            ));
        }

        $event->forceFill(['domain_verified_at' => now()])->save();

        // Make sure the challenge path is being served before asking for a cert.
        $this->publish();

        $result = Process::timeout(180)->run($this->certbotCommand($domain));

        if (! $result->successful()) {
            return $this->fail($event, $this->lastMeaningfulLine(
                $result->errorOutput().$result->output(),
            ) ?: 'Penerbitan sertifikat gagal.');
        }

        $event->forceFill([
            'domain_certified_at' => now(),
            'domain_error' => null,
        ])->save();

        self::flush();
        // Second reload: now that the certificate exists, publish() emits the
        // 443 block too and the domain starts serving.
        $this->publish();

        return true;
    }

    /**
     * Domains waiting for a certificate, skipping ones that failed recently.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    public function pending(): \Illuminate\Database\Eloquent\Collection
    {
        $retryAfter = now()->subMinutes(config('domains.retry_after_minutes'));

        return Event::query()
            ->whereNotNull('custom_domain')
            ->whereNull('domain_certified_at')
            ->where('status', '!=', 'draft')
            ->where(fn ($q) => $q
                ->whereNull('domain_attempted_at')
                ->orWhere('domain_attempted_at', '<=', $retryAfter))
            ->orderBy('created_at')
            ->get();
    }

    /** Write the nginx config and ask the host to reload it. */
    public function publish(): void
    {
        $path = config('domains.paths.nginx_conf');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            // Not an error: on a dev machine the shared directories do not
            // exist, and everything except serving still works.
            return;
        }

        $current = is_file($path) ? file_get_contents($path) : null;
        $next = $this->renderNginxConfig();

        // An unchanged config means nothing to reload. Without this check every
        // domains:sync tick would bounce nginx for the whole host.
        if ($current === $next) {
            return;
        }

        file_put_contents($path, $next);
        $this->requestReload();
    }

    /**
     * The generated vhosts, one pair per domain.
     *
     * Every server_name is spelled out. No `default_server`, and no regex
     * catch-all: this host's nginx also fronts other stacks, only one
     * default_server may exist per port, and a regex server_name is matched
     * BEFORE the default — either would quietly steal a neighbour's traffic.
     */
    public function renderNginxConfig(): string
    {
        $webroot = config('domains.paths.webroot');
        $live = rtrim(config('domains.paths.letsencrypt'), '/').'/live';
        $proxy = config('domains.proxy_pass');

        $out = [
            '# Digenerate DomainService::renderNginxConfig(). Jangan diedit tangan —',
            '# perubahan akan tertimpa saat domain berikutnya diaktifkan.',
            '# Dibuat: '.now()->toDateTimeString(),
            '',
        ];

        $events = Event::query()
            ->whereNotNull('custom_domain')
            ->where('status', '!=', 'draft')
            ->orderBy('custom_domain')
            ->get(['id', 'custom_domain', 'domain_certified_at']);

        foreach ($events as $event) {
            $domain = $event->custom_domain;

            // Port 80 exists for every assigned domain, certificate or not: it
            // is what answers the ACME challenge that produces the certificate.
            $out[] = "server {";
            $out[] = "    listen 80;";
            $out[] = "    listen [::]:80;";
            $out[] = "    server_name {$domain};";
            $out[] = "";
            $out[] = "    location /.well-known/acme-challenge/ {";
            $out[] = "        root {$webroot};";
            $out[] = "    }";
            $out[] = "";

            if ($event->domain_certified_at) {
                $out[] = "    location / {";
                $out[] = "        return 301 https://\$host\$request_uri;";
                $out[] = "    }";
            } else {
                // No certificate yet: send visitors to the canonical URL rather
                // than to an https:// that cannot complete a handshake.
                $out[] = "    location / {";
                $out[] = "        proxy_pass {$proxy};";
                $out[] = "        proxy_http_version 1.1;";
                $out[] = "        proxy_set_header Host \$host;";
                $out[] = "        proxy_set_header X-Real-IP \$remote_addr;";
                $out[] = "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;";
                $out[] = "        proxy_set_header X-Forwarded-Proto \$scheme;";
                $out[] = "    }";
            }

            $out[] = "}";
            $out[] = "";

            if (! $event->domain_certified_at) {
                continue;
            }

            $out[] = "server {";
            $out[] = "    listen 443 ssl;";
            $out[] = "    listen [::]:443 ssl;";
            $out[] = "    http2 on;";
            $out[] = "    server_name {$domain};";
            $out[] = "";
            // Explicit paths, not a variable ssl_certificate: nginx open source
            // re-reads the files on every handshake when they are dynamic, and a
            // missing cert only surfaces as a failed handshake. Spelled out,
            // `nginx -t` catches it before the reload happens.
            $out[] = "    ssl_certificate {$live}/{$domain}/fullchain.pem;";
            $out[] = "    ssl_certificate_key {$live}/{$domain}/privkey.pem;";
            $out[] = "";
            $out[] = "    client_max_body_size 25m;";
            $out[] = "";
            $out[] = "    location /.well-known/acme-challenge/ {";
            $out[] = "        root {$webroot};";
            $out[] = "    }";
            $out[] = "";
            $out[] = "    location / {";
            $out[] = "        proxy_pass {$proxy};";
            $out[] = "        proxy_http_version 1.1;";
            $out[] = "        proxy_set_header Upgrade \$http_upgrade;";
            $out[] = "        proxy_set_header Connection \"upgrade\";";
            // $host, not a literal: the Next middleware routes on it.
            $out[] = "        proxy_set_header Host \$host;";
            $out[] = "        proxy_set_header X-Real-IP \$remote_addr;";
            $out[] = "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;";
            $out[] = "        proxy_set_header X-Forwarded-Proto \$scheme;";
            $out[] = "    }";
            $out[] = "}";
            $out[] = "";
        }

        return implode("\n", $out);
    }

    /** Renew everything certbot knows about. Scheduled daily. */
    public function renew(): bool
    {
        $result = Process::timeout(600)->run(implode(' ', [
            'certbot renew',
            '--config-dir '.escapeshellarg(config('domains.paths.letsencrypt')),
            '--work-dir '.escapeshellarg(config('domains.paths.letsencrypt').'/work'),
            '--logs-dir '.escapeshellarg(config('domains.paths.letsencrypt').'/logs'),
            '--webroot -w '.escapeshellarg(config('domains.paths.webroot')),
            '--quiet',
        ]));

        if ($result->successful()) {
            // Renewal replaces the files in place, so nginx must re-read them.
            $this->requestReload();
        }

        return $result->successful();
    }

    /** @return array<int, string> */
    private function certbotCommand(string $domain): array
    {
        $letsencrypt = config('domains.paths.letsencrypt');

        $command = [
            'certbot', 'certonly',
            '--webroot', '-w', config('domains.paths.webroot'),
            // Keep certbot's whole state on the shared mount: without these it
            // writes to /etc/letsencrypt inside the container, which host nginx
            // cannot see and which dies with the container.
            '--config-dir', $letsencrypt,
            '--work-dir', $letsencrypt.'/work',
            '--logs-dir', $letsencrypt.'/logs',
            '--cert-name', $domain,
            '-d', $domain,
            '--email', config('domains.letsencrypt_email'),
            '--agree-tos', '--no-eff-email',
            '--non-interactive',
        ];

        if (config('domains.staging')) {
            $command[] = '--staging';
        }

        return $command;
    }

    private function deleteCertificate(string $domain): void
    {
        $letsencrypt = config('domains.paths.letsencrypt');

        if (! is_dir($letsencrypt)) {
            return;
        }

        // Best effort: a domain with no certificate (assigned but never
        // activated) makes certbot exit non-zero, and that is not a failure
        // worth blocking the release on.
        Process::timeout(60)->run([
            'certbot', 'delete',
            '--cert-name', $domain,
            '--config-dir', $letsencrypt,
            '--work-dir', $letsencrypt.'/work',
            '--logs-dir', $letsencrypt.'/logs',
            '--non-interactive',
        ]);
    }

    private function requestReload(): void
    {
        $flag = config('domains.paths.reload_flag');

        if (! is_dir(dirname($flag))) {
            return;
        }

        file_put_contents($flag, (string) now()->timestamp);
    }

    private function fail(Event $event, string $message): bool
    {
        $event->forceFill(['domain_error' => $message])->save();

        Log::warning('Aktivasi custom domain gagal', [
            'event_id' => $event->id,
            'domain' => $event->custom_domain,
            'error' => $message,
        ]);

        return false;
    }

    /** Certbot puts the useful line last; the rest is progress chatter. */
    private function lastMeaningfulLine(string $output): ?string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            fn ($line) => $line !== '',
        ));

        return $lines ? mb_substr(end($lines), 0, 500) : null;
    }
}
