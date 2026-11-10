#!/usr/bin/env bash
# Cài API gửi mail form liên hệ (Resend). Chạy MỘT LẦN trên server với sudo:
#   sudo bash setup-contact-api.sh deploy
# An toàn khi chạy lại nhiều lần. Không ghi đè cấu hình HTTPS của certbot.
set -euo pipefail

DEPLOY_USER="${1:-deploy}"
DEPLOY_PATH="/var/www/miraway"
SERVICE="miraway-contact"
PORT=3001

# 1. Node.js (chỉ cài nếu chưa có)
if ! command -v node >/dev/null 2>&1; then
  apt-get update
  apt-get install -y nodejs
fi
NODE_BIN="$(command -v node)"
echo "Node: $($NODE_BIN --version)"

# 2. Thư mục chứa file .env dùng chung giữa các bản deploy
mkdir -p "$DEPLOY_PATH/shared"
touch "$DEPLOY_PATH/shared/.env"
chmod 600 "$DEPLOY_PATH/shared/.env"
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$DEPLOY_PATH/shared"

# 3. Service systemd
cat > "/etc/systemd/system/$SERVICE.service" <<EOF
[Unit]
Description=Miraway contact form API (Resend)
After=network.target

[Service]
User=$DEPLOY_USER
WorkingDirectory=$DEPLOY_PATH/current/server
Environment=PORT=$PORT
Environment=ENV_FILE=$DEPLOY_PATH/shared/.env
ExecStart=$NODE_BIN $DEPLOY_PATH/current/server/contact.js
Restart=always
RestartSec=3
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable "$SERVICE"

# 4. Cho user deploy reload nginx và restart service không cần mật khẩu
SYSTEMCTL="$(command -v systemctl)"
cat > "/etc/sudoers.d/$DEPLOY_USER-nginx" <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: $SYSTEMCTL reload nginx, $SYSTEMCTL restart $SERVICE
EOF
chmod 440 "/etc/sudoers.d/$DEPLOY_USER-nginx"
visudo -cf "/etc/sudoers.d/$DEPLOY_USER-nginx"

# 5. Nginx: chuyển /api/ sang Node
mkdir -p /etc/nginx/snippets
cat > /etc/nginx/snippets/miraway-api.conf <<EOF
location /api/ {
    proxy_pass http://127.0.0.1:$PORT;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    client_max_body_size 32k;
}
# không cho tải mã nguồn API qua web
location ^~ /server/ { deny all; }
EOF
SITE=/etc/nginx/sites-available/miraway
if [ -f "$SITE" ] && ! grep -q 'miraway-api.conf' "$SITE"; then
  cp "$SITE" "$SITE.bak.$(date +%s)"
  # chèn include vào mọi server block (cả HTTP và HTTPS do certbot tạo)
  sed -i "s#^\(\s*\)root $DEPLOY_PATH/current;#&\n\1include /etc/nginx/snippets/miraway-api.conf;#" "$SITE"
fi
nginx -t && systemctl reload nginx

# 6. Khởi động service nếu đã có code
if [ -f "$DEPLOY_PATH/current/server/contact.js" ]; then
  systemctl restart "$SERVICE"
  sleep 1
  systemctl --no-pager --lines=5 status "$SERVICE" || true
else
  echo "Chưa có code server/ — service sẽ chạy sau lần deploy tiếp theo."
fi

echo
echo "Xong. API key được GitHub Actions ghi vào $DEPLOY_PATH/shared/.env (secret RESEND_API_KEY)."
