#!/usr/bin/env bash
# ============================================================
# Obtain the initial Let's Encrypt certificate via the certbot
# container's webroot. Run once on the VPS after DNS points at it.
#   DOMAIN / EMAIL are read from the root .env (APP_DOMAIN, LETSENCRYPT_EMAIL).
#
# Set STAGING=1 to rehearse against the LE staging CA. Do that first: the
# production CA allows only 5 failures per hostname per hour.
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"
[ -f .env ] && set -a && . ./.env && set +a

DOMAIN="${APP_DOMAIN:-flo-event.flotech.id}"
API="${API_DOMAIN:-api-flo-event.flotech.id}"
EMAIL="${LETSENCRYPT_EMAIL:-admin@flotech.id}"
STAGING="${STAGING:-0}"

staging_arg=""
[ "$STAGING" != "0" ] && staging_arg="--staging"

# The lineage certbot creates is named after the FIRST -d, and nginx.conf reads
# its certs from that directory. Keep the two in step.
LIVE="/etc/letsencrypt/live/$DOMAIN"

echo "==> Certificate for: $DOMAIN, $API"

# ---- 1. Dummy certificate ---------------------------------------------------
# Chicken-and-egg: nginx refuses to start when `ssl_certificate` points at a
# missing file, but nginx is what serves the ACME challenge that produces that
# file. Break the cycle with a throwaway pair at the exact path nginx expects.
echo "==> Creating dummy certificate..."
docker compose run --rm --entrypoint sh certbot -c "\
  mkdir -p '$LIVE' && \
  openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -keyout '$LIVE/privkey.pem' \
    -out '$LIVE/fullchain.pem' \
    -subj '/CN=localhost'"

# ---- 2. Start nginx ---------------------------------------------------------
# No --no-deps: `proxy_pass http://web:3000` is resolved when nginx loads its
# config, so web and api must be resolvable or nginx exits on "host not found
# in upstream". Bringing up nginx pulls both in via depends_on.
echo "==> Starting nginx..."
docker compose up -d --force-recreate nginx

# ---- 3. Discard the dummy ---------------------------------------------------
# Running nginx already holds it in memory, so deleting the files now is safe.
# Leaving them would make certbot treat this as an existing lineage and prompt
# to renew/expand instead of issuing.
echo "==> Removing dummy certificate..."
docker compose run --rm --entrypoint sh certbot -c "\
  rm -rf '$LIVE' \
         '/etc/letsencrypt/archive/$DOMAIN' \
         '/etc/letsencrypt/renewal/$DOMAIN.conf'"

# ---- 4. Real certificate ----------------------------------------------------
# --entrypoint certbot restores the image default. The compose service replaces
# it with a `sh -c '<renew loop>'`, which would swallow these args as $0/$1 and
# sit in that loop forever.
echo "==> Requesting certificate..."
docker compose run --rm --entrypoint certbot certbot certonly \
  --webroot -w /var/www/certbot \
  $staging_arg \
  --email "$EMAIL" --agree-tos --no-eff-email \
  -d "$DOMAIN" -d "$API"

# ---- 5. Serve it ------------------------------------------------------------
echo "==> Reloading nginx..."
docker compose exec nginx nginx -s reload

echo "==> Done. Certificates installed for $DOMAIN."
