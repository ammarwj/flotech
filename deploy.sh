#!/usr/bin/env bash
# ============================================================
# flo-event — deploy / rilis versi baru
#
# Ambil commit terbaru dari remote, rebuild image yang berubah,
# migrasi + seed katalog (idempoten), lalu tampilkan status.
#
# Jalankan DI VPS, dari dalam folder repo (tempat docker-compose.yml):
#   ./deploy.sh                 # rilis biasa (branch saat ini) — TIDAK seed
#   BRANCH=master ./deploy.sh   # paksa branch tertentu
#   SEED=1 ./deploy.sh          # + seed ulang katalog (lihat peringatan di bawah)
#
# PERINGATAN seed: seeder katalog (Plan/Feature/Testimonial/Faq) pakai
# updateOrCreate, jadi MENIMPA balik harga/paket/fitur/testimoni/FAQ ke nilai
# default di seeder — termasuk yang sudah kamu edit lewat /admin. Karena itu
# seed TIDAK jalan otomatis. Pakai SEED=1 hanya saat ada perubahan katalog di
# kode dan kamu memang ingin nilai kode itu menang atas editan admin.
#
# docker compose otomatis membaca COMPOSE_FILE / COMPOSE_PROFILES dari
# root .env, jadi skrip ini sama untuk Varian A (standalone) & B (shared).
# ============================================================
set -euo pipefail

# Selalu bekerja dari lokasi skrip (root repo), apa pun cwd pemanggil.
cd "$(dirname "$0")"

BRANCH="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\n\033[1;33m!! \033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mXX \033[0m %s\n' "$*" >&2; exit 1; }

# --- Prasyarat ------------------------------------------------
command -v git >/dev/null    || die "git tidak ditemukan."
docker compose version >/dev/null 2>&1 || die "docker compose (plugin) tidak ditemukan."
[ -f docker-compose.yml ]    || die "docker-compose.yml tidak ada — jalankan dari root repo."
[ -f api/.env ]              || die "api/.env belum ada (lihat deploy.md langkah 3)."

# --- Tolak deploy kalau working tree kotor --------------------
# git reset --hard di bawah akan menghapus perubahan lokal; hindari.
if [ -n "$(git status --porcelain)" ]; then
  git status --short
  die "Working tree kotor. Commit/stash/buang perubahan dulu sebelum deploy."
fi

# --- Ambil commit terbaru dari remote -------------------------
log "Fetch dari remote (origin/$BRANCH)…"
git fetch --prune origin "$BRANCH"

OLD_REF="$(git rev-parse HEAD)"
NEW_REF="$(git rev-parse "origin/$BRANCH")"

if [ "$OLD_REF" = "$NEW_REF" ]; then
  log "Sudah di commit terbaru ($(git rev-parse --short HEAD)). Tetap lanjut rebuild."
else
  log "Update $(git rev-parse --short "$OLD_REF") → $(git rev-parse --short "$NEW_REF")"
  git checkout -q "$BRANCH"
  git reset --hard "origin/$BRANCH"
fi

# Apakah env build frontend berubah? NEXT_PUBLIC_* di-inline saat build,
# jadi image web wajib di-rebuild tanpa cache kalau file ini ikut berubah.
WEB_ENV_CHANGED=0
if git diff --name-only "$OLD_REF" "$NEW_REF" 2>/dev/null | grep -q '^web/.env'; then
  WEB_ENV_CHANGED=1
fi

# --- Build & up ----------------------------------------------
log "Build & up seluruh stack…"
docker compose up -d --build

if [ "$WEB_ENV_CHANGED" = "1" ]; then
  warn "web/.env berubah — rebuild image web tanpa cache (NEXT_PUBLIC_* di-inline saat build)."
  docker compose build --no-cache web
  docker compose up -d web
fi

# --- Migrasi & seed ------------------------------------------
log "Migrasi database…"
docker compose exec -T api php artisan migrate --force

if [ "${SEED:-0}" = "1" ]; then
  warn "SEED=1 — seed ulang katalog. Ini MENIMPA harga/paket/fitur/testimoni/FAQ"
  warn "yang diedit lewat /admin kembali ke nilai default di seeder."
  docker compose exec -T api php artisan db:seed --force
else
  log "Lewati seeder katalog (default). Jalankan dengan SEED=1 bila memang perlu."
fi

# Bersihkan cache config/route/view produksi.
log "Optimize cache Laravel…"
docker compose exec -T api php artisan optimize

# --- Status --------------------------------------------------
log "Status container:"
docker compose ps

log "Deploy selesai — sekarang di commit $(git rev-parse --short HEAD)."
