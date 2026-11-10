#!/usr/bin/env bash
# Chạy MỘT LẦN trên server (Ubuntu/Debian) với quyền sudo:
#   sudo bash setup-server.sh deploy example.com
# Tham số: <user deploy> <domain>
set -euo pipefail

DEPLOY_USER="${1:-deploy}"
DOMAIN="${2:-example.com}"
DEPLOY_PATH="/var/www/miraway"

apt-get update  apt-get install -y nginx rsync

id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
mkdir -p "/home/$DEPLOY_USER/.ssh"
touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 700 "/home/$DEPLOY_USER/.ssh"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown -R "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"

mkdir -p "$DEPLOY_PATH/releases"
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$DEPLOY_PATH"

# Cho phép user deploy reload nginx không cần mật khẩu
echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx, /bin/systemctl reload nginx" \
  > "/etc/sudoers.d/$DEPLOY_USER-nginx"
chmod 440 "/etc/sudoers.d/$DEPLOY_USER-nginx"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
sed -e "s/example.com www.example.com/$DOMAIN www.$DOMAIN/" \
    -e "s#/var/www/miraway/current#$DEPLOY_PATH/current#" \
    "$SCRIPT_DIR/nginx.conf" > /etc/nginx/sites-available/miraway
ln -sfn /etc/nginx/sites-available/miraway /etc/nginx/sites-enabled/miraway
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# API gửi mail form liên hệ
bash "$SCRIPT_DIR/setup-contact-api.sh" "$DEPLOY_USER"

echo
echo "Xong. Dán public key của GitHub Actions vào: /home/$DEPLOY_USER/.ssh/authorized_keys"
echo "DEPLOY_PATH = $DEPLOY_PATH"
