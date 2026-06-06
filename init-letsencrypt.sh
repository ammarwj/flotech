#!/usr/bin/env bash
# ============================================================
# Obtain the initial Let's Encrypt certificate via the certbot
# container's webroot. Run once on the VPS after DNS points at it.
#   DOMAIN / EMAIL are read from the root .env (APP_DOMAIN, LETSENCRYPT_EMAIL).
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"
[ -f .env ] && set -a && . ./.env && set +a

DOMAIN="${APP_DOMAIN:-flo-event.id}"
API="${API_DOMAIN:-api.${DOMAIN}}"
EMAIL="${LETSENCRYPT_EMAIL:-admin@${DOMAIN}}"
STAGING="${STAGING:-0}" # set STAGING=1 to test against the LE staging CA

staging_arg=""
[ "$STAGING" != "0" ] && staging_arg="--staging"

echo "Requesting certificate for: $DOMAIN, www.$DOMAIN, $API"

# Bring up nginx so the ACME HTTP-01 challenge is reachable.
docker compose up -d nginx

docker compose run --rm certbot certonly \
  --webroot -w /var/www/certbot \
  $staging_arg \
  --email "$EMAIL" --agree-tos --no-eff-email \
  -d "$DOMAIN" -d "www.$DOMAIN" -d "$API"

echo "Reloading nginx..."
docker compose exec nginx nginx -s reload

echo "Done. Certificates installed for $DOMAIN."
