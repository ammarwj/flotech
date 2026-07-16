# flo-event

SaaS platform for end-to-end sports tournament management (registration, scheduling,
live standings, brackets, QR tickets, certificate generation). See [`PRD.md`](./PRD.md).

## Monorepo layout

```
flo-event/
├── web/                  # Next.js 16 frontend (React 19, TS, Tailwind 4) — bun
├── api/                  # Laravel 13 backend (PHP 8.4) — composer
├── nginx/conf.d/         # reverse-proxy config (flo-event.id, api.flo-event.id)
├── docker-compose.yml    # production stack
├── docker-compose.dev.yml# dev override (hot reload, exposed ports)
├── init-letsencrypt.sh   # one-time TLS cert bootstrap (certbot)
└── .env.example          # root/compose env template
```

## Tech stack

- **Frontend:** Next.js 16 · React 19 · TypeScript · Tailwind 4 · shadcn/ui · TanStack Query · Zustand · Axios · Sentry
- **Backend:** Laravel 13 · PHP 8.4 · tymon/jwt-auth (RS256) · Horizon · Telescope · Spatie Permission · DomPDF · Maatwebsite Excel · Intervention Image · AWS SDK (R2) · Sentry
- **Infra:** Docker Compose · Nginx · PostgreSQL 17 · Redis 8 · Cloudflare R2 · Let's Encrypt

## Local development

Prerequisites: [bun](https://bun.sh), PHP 8.4 + Composer (or just Docker).

### Frontend
```bash
cd web
bun install
bun run dev          # http://localhost:3000
```

### Backend
```bash
cd api
cp .env.example .env             # already present locally
composer install
php artisan key:generate
php artisan migrate --seed       # needs Postgres (or run via Docker below)
php artisan serve                # http://localhost:8000  (health: /api/v1/health)
```

JWT keys live in `api/storage/jwt/` (RS256). Generate a fresh pair with:
```bash
openssl genrsa -out storage/jwt/jwt-private.pem 2048
openssl rsa -in storage/jwt/jwt-private.pem -pubout -out storage/jwt/jwt-public.pem
```

### Full stack via Docker (dev)
```bash
cp .env.example .env
cp api/.env.example api/.env     # then set APP_KEY (php artisan key:generate)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up web api db redis
```

## Production deploy (VPS)

The host needs only Docker and the Compose plugin — every runtime ships in the
images. DNS for all three names must already point at the box.

```bash
cp .env.example .env             # domains + POSTGRES_* (compose interpolation only)
cp api/.env.example api/.env     # the app's own secrets — see the two notes below
cp web/.env.example web/.env.production

# RS256 keys must exist BEFORE anything builds: the image bakes them in via
# `COPY . .`, and .gitignore keeps *.pem out of the clone, so a fresh checkout
# has none. Build first and api, worker and scheduler all come up unable to
# sign tokens. init-letsencrypt.sh builds too, so generate them here, not later.
openssl genrsa -out api/storage/jwt/jwt-private.pem 2048
openssl rsa -in api/storage/jwt/jwt-private.pem -pubout -out api/storage/jwt/jwt-public.pem

./init-letsencrypt.sh            # obtain TLS certs
docker compose up -d --build
docker compose exec api php artisan migrate --force
```

Two things about `api/.env` that bite in production:

- **`DB_USERNAME`/`DB_PASSWORD` must match `POSTGRES_USER`/`POSTGRES_PASSWORD`
  in the root `.env`.** The root file only feeds compose interpolation — it is
  never injected into the api container, which reads `api/.env` via `env_file`.
  Setting `APP_KEY` or `JWT_*` in the root `.env` does nothing.
- **Generate `APP_KEY` on the host**, not with `artisan key:generate`. Nothing
  bind-mounts `./api` in production, so the command would rewrite the `.env`
  *inside* a container and lose it on the next recreate. Paste this in instead:
  ```bash
  echo "base64:$(openssl rand -base64 32)"
  ```

Services: `nginx` (80/443) · `web` (Next.js) · `api` (Laravel) · `worker` (Horizon)
· `scheduler` · `db` (Postgres) · `redis`.

## Notes

- **Testing:** Laravel ships PHPUnit; Pest is pending upstream Laravel 13 (symfony 8) support.
- **Redis client:** `predis` (pure PHP) for portability; switch to `phpredis` in prod if the extension is installed.
- **Telescope:** dev only — keep `TELESCOPE_ENABLED=false` in production.
