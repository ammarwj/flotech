#!/usr/bin/env bash
#
# Bootstrap custom domain per event — dijalankan SEKALI di VPS, sebagai root.
#
# Menyiapkan direktori bersama antara container `scheduler` dan nginx host,
# memasang vhost `include`-nya, dan mengaktifkan systemd path unit yang mereload
# nginx saat container meminta.
#
# Ini BUKAN init-letsencrypt.sh. Yang itu untuk Varian A (nginx di dalam Docker,
# volume `certbot_certs`) dan tidak disentuh sama sekali oleh fitur ini.
#
#   sudo bash deploy/setup-custom-domains.sh
#
set -euo pipefail

ROOT=/opt/flo-event
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ $EUID -ne 0 ]]; then
  echo "Jalankan sebagai root: sudo bash deploy/setup-custom-domains.sh" >&2
  exit 1
fi

echo "==> Membuat $ROOT"
mkdir -p "$ROOT"/{letsencrypt,certbot-www,nginx,flags}

# Container jalan sebagai root (image php:8.4-cli-alpine), jadi tidak ada
# penyesuaian uid. Yang penting nginx host bisa MEMBACA keduanya.
chmod 755 "$ROOT" "$ROOT/certbot-www" "$ROOT/nginx"
# Kunci privat ada di sini — hanya root.
chmod 700 "$ROOT/letsencrypt"

# nginx menolak start kalau `include`-nya menunjuk file yang tidak ada, dan
# file ini baru ditulis saat domain pertama diaktifkan.
if [[ ! -f "$ROOT/nginx/custom-domains.conf" ]]; then
  echo "# Kosong sampai domain pertama diaktifkan. Digenerate DomainService." \
    > "$ROOT/nginx/custom-domains.conf"
fi

echo "==> Memasang vhost include"
install -m 644 "$REPO/deploy/host-nginx/flo-event-domains.conf" \
  /etc/nginx/sites-available/flo-event-domains.conf
ln -sf /etc/nginx/sites-available/flo-event-domains.conf \
  /etc/nginx/sites-enabled/flo-event-domains.conf

echo "==> Memasang systemd path unit"
install -m 644 "$REPO/deploy/host-nginx/flo-domains-reload.service" \
  /etc/systemd/system/flo-domains-reload.service
install -m 644 "$REPO/deploy/host-nginx/flo-domains-reload.path" \
  /etc/systemd/system/flo-domains-reload.path
systemctl daemon-reload
systemctl enable --now flo-domains-reload.path

echo "==> Tes config nginx"
nginx -t
systemctl reload nginx

cat <<'EOF'

Selesai. Sisanya di aplikasi:

  1. Isi di api/.env:
       CUSTOM_DOMAIN_SERVER_IP=<IP publik VPS ini>
       LETSENCRYPT_EMAIL=admin@floevent.id
       # Untuk uji coba dulu, supaya kuota Let's Encrypt tidak terbakar:
       # CUSTOM_DOMAIN_STAGING=true

     IP yang kosong mematikan fitur ini dengan aman — verifyDns() tidak punya
     pembanding sehingga tidak ada permintaan sertifikat yang dikirim.

  2. docker compose up -d --build scheduler web

  3. Di /admin/events: pasang domain, minta pemiliknya mengarahkan A record ke
     IP di atas, lalu tekan "Aktifkan & terbitkan SSL".

Memeriksa hasilnya:

     cat /opt/flo-event/nginx/custom-domains.conf
     systemctl status flo-domains-reload
     curl -I https://<domain-uji>/
EOF
