<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custom domains per event
    |--------------------------------------------------------------------------
    |
    | A published event can be served on its own hostname. The owner points an A
    | record at this VPS; a super admin then activates it, which issues a Let's
    | Encrypt certificate and writes the nginx server blocks that serve it.
    |
    | Deployed config, not admin-editable: every value here is a property of the
    | machine (its IP, where nginx reads its files), and getting one wrong takes
    | down vhosts belonging to other stacks on the same host.
    |
    */

    // The A record every custom domain must point at. Empty disables the whole
    // feature — verifyDns() then has nothing to compare against and refuses,
    // which is the safe direction: no certificate requests get made.
    'server_ip' => env('CUSTOM_DOMAIN_SERVER_IP', ''),

    'letsencrypt_email' => env('LETSENCRYPT_EMAIL', 'admin@floevent.id'),

    // Rehearse against the Let's Encrypt staging CA. Same switch as
    // init-letsencrypt.sh — the production CA allows only 5 failures per
    // hostname per hour, counted per account.
    'staging' => (bool) env('CUSTOM_DOMAIN_STAGING', false),

    // Shared with the host: the container writes, host nginx reads.
    'paths' => [
        'letsencrypt' => env('CUSTOM_DOMAIN_LETSENCRYPT_PATH', '/opt/flo-event/letsencrypt'),
        'webroot' => env('CUSTOM_DOMAIN_WEBROOT_PATH', '/opt/flo-event/certbot-www'),
        'nginx_conf' => env('CUSTOM_DOMAIN_NGINX_CONF', '/opt/flo-event/nginx/custom-domains.conf'),
        // Touched after writing the config. A systemd path unit on the host
        // watches it and runs `nginx -t && systemctl reload nginx` — the one
        // job only the host can do. In its own directory because that directory
        // is what gets bind-mounted: mounting the file itself would break the
        // moment it is replaced rather than rewritten.
        'reload_flag' => env('CUSTOM_DOMAIN_RELOAD_FLAG', '/opt/flo-event/flags/reload.flag'),
    ],

    // Where the generated vhosts proxy to: the Next.js container's loopback
    // port from docker-compose.shared.yml. Same target as the main site.
    'proxy_pass' => env('CUSTOM_DOMAIN_PROXY_PASS', 'http://127.0.0.1:3001'),

    // Hostnames the platform serves itself. Letting an event claim one would
    // generate a second server block for a name the main vhost already owns,
    // and nginx serves whichever it loads first — the main site would start
    // resolving to an event page, or stop resolving at all.
    'reserved' => array_values(array_filter([
        env('APP_DOMAIN', 'floevent.id'),
        env('API_DOMAIN', 'api.floevent.id'),
        'www.'.env('APP_DOMAIN', 'floevent.id'),
    ])),

    // How long a failed domain waits before domains:sync retries it. Guards the
    // Let's Encrypt failure quota, which is shared across every domain we hold.
    'retry_after_minutes' => (int) env('CUSTOM_DOMAIN_RETRY_MINUTES', 60),

    // How long the public domain list stays cached. The Next middleware reads
    // it on every request for an unknown host, so it must not be a query.
    'cache_ttl' => (int) env('CUSTOM_DOMAIN_CACHE_TTL', 60),

];
