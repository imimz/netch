#!/usr/bin/env bash
# نصب خودکار درگاه واسط زرین‌پال روی سرور (Ubuntu / Debian)
#
# نمونه:
#   sudo bash install.sh \
#     --domain pay.etrimer.ir \
#     --merchant xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx \
#     --notify-url https://BOT_DOMAIN/PATH/payment/zarinpal_gateway/notify.php \
#     --return-url https://t.me/YourBot \
#     --email you@example.com
#
# این اسکریپت به کانفیگ سایت فعلی دست نمی‌زند؛ فقط یک vhost جدید برای دامنه‌ی درگاه می‌سازد.
# اجرای دوباره‌ی آن امن است: فایل‌ها به‌روز می‌شوند ولی config.php و دیتابیس قبلی حفظ می‌شوند.
set -euo pipefail

DOMAIN=""
MERCHANT=""
NOTIFY_URL=""
RETURN_URL=""
EMAIL=""
INSTALL_DIR="/var/www/pay-gateway"
SANDBOX="false"
NO_SSL="false"
SITE_NAME="ایتریمر"
BOT_TITLE="ربات میرزا پرو"

die()  { echo -e "\e[31m✗ $*\e[0m" >&2; exit 1; }
info() { echo -e "\e[36m• $*\e[0m"; }
ok()   { echo -e "\e[32m✓ $*\e[0m"; }
warn() { echo -e "\e[33m! $*\e[0m"; }

usage() {
    sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
    cat <<'EOF'
گزینه‌ها:
  --domain       دامنه‌ی درگاه (باید رکورد A آن به IP همین سرور اشاره کند)   [الزامی]
  --merchant     مرچنت کد زرین‌پال                                              [الزامی]
  --notify-url   آدرس notify.php روی سرور ربات (بعداً هم در config.php قابل تغییر است)
  --return-url   لینک ربات برای دکمه‌ی «بازگشت به ربات»
  --email        ایمیل برای گواهی SSL (Let's Encrypt)
  --dir          مسیر نصب (پیش‌فرض: /var/www/pay-gateway)
  --sandbox      استفاده از سندباکس زرین‌پال برای تست
  --no-ssl       بدون گرفتن گواهی SSL (فقط برای تست؛ زرین‌پال callback غیر HTTPS را نمی‌پذیرد)
EOF
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)     DOMAIN="${2:-}"; shift 2 ;;
        --merchant)   MERCHANT="${2:-}"; shift 2 ;;
        --notify-url) NOTIFY_URL="${2:-}"; shift 2 ;;
        --return-url) RETURN_URL="${2:-}"; shift 2 ;;
        --email)      EMAIL="${2:-}"; shift 2 ;;
        --dir)        INSTALL_DIR="${2:-}"; shift 2 ;;
        --sandbox)    SANDBOX="true"; shift ;;
        --no-ssl)     NO_SSL="true"; shift ;;
        -h|--help)    usage 0 ;;
        *)            echo "گزینه‌ی ناشناخته: $1"; usage 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || die "اسکریپت را با sudo یا کاربر root اجرا کنید."
[[ -n "$DOMAIN" ]] || die "--domain الزامی است."
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || die "دامنه نامعتبر است: $DOMAIN"
[[ "$MERCHANT" =~ ^[0-9a-fA-F-]{36}$ ]] || die "مرچنت کد باید ۳۶ کاراکتر باشد (مثل xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)."
command -v apt-get >/dev/null || die "این اسکریپت فقط برای Ubuntu/Debian نوشته شده."

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/gateway"
[[ -f "$SRC_DIR/index.php" ]] || die "پوشه‌ی gateway کنار install.sh پیدا نشد ($SRC_DIR)."

# ---------- تشخیص وب‌سرور فعلی ----------
listener_on() { ss -Hltnp "( sport = :$1 )" 2>/dev/null | grep -oP 'users:\(\("\K[^"]+' | head -1 || true; }
L80="$(listener_on 80)"; L443="$(listener_on 443)"
LISTENER="${L443:-$L80}"
case "$LISTENER" in
    nginx*)         WEB="nginx" ;;
    apache2*|httpd) WEB="apache" ;;
    "")
        if command -v nginx >/dev/null; then WEB="nginx"
        elif command -v apache2 >/dev/null; then WEB="apache"
        else WEB="nginx"; fi ;;
    *)
        die "پورت 80/443 دست «$LISTENER» است (نه nginx و نه apache).
  احتمالاً سایت با Docker یا یک کنترل‌پنل اجرا می‌شود. در این حالت درگاه را دستی نصب کنید (README.md)." ;;
esac
ok "وب‌سرور: $WEB"

# ---------- چک DNS ----------
SERVER_IP="$(curl -4 -s -m 8 https://api.ipify.org || true)"
DOMAIN_IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)"
if [[ -z "$DOMAIN_IP" ]]; then
    warn "دامنه‌ی $DOMAIN هنوز به هیچ IPای resolve نمی‌شود. اول رکورد A آن را روی ${SERVER_IP:-IP این سرور} بگذارید."
    [[ "$NO_SSL" == "true" ]] || die "بدون DNS درست، گواهی SSL صادر نمی‌شود."
elif [[ -n "$SERVER_IP" && "$DOMAIN_IP" != "$SERVER_IP" ]]; then
    warn "دامنه‌ی $DOMAIN به $DOMAIN_IP اشاره می‌کند ولی IP این سرور $SERVER_IP است (اگر پشت CDN است مشکلی نیست)."
fi

# ---------- نصب پکیج‌ها ----------
info "نصب PHP و افزونه‌ها..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || warn "apt-get update با خطا تمام شد؛ ادامه می‌دهیم..."
PKGS=(php-fpm php-cli php-curl php-sqlite3 curl)
if [[ "$NO_SSL" != "true" ]]; then
    PKGS+=(certbot)
    if [[ "$WEB" == "nginx" ]]; then PKGS+=(python3-certbot-nginx); else PKGS+=(python3-certbot-apache); fi
fi
if [[ "$WEB" == "nginx" ]] && ! command -v nginx >/dev/null; then PKGS+=(nginx); fi
apt-get install -y -qq "${PKGS[@]}" >/dev/null || die "نصب پکیج‌ها ناموفق بود: ${PKGS[*]}"
php -m | grep -qi pdo_sqlite || die "افزونه‌ی pdo_sqlite روی PHP فعال نشد."
php -m | grep -qi '^curl$'   || die "افزونه‌ی curl روی PHP فعال نشد."

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SERVICE="php${PHP_VER}-fpm"
systemctl enable --now "$FPM_SERVICE" >/dev/null 2>&1 || true
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
[[ -S "$FPM_SOCK" ]] || FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)"
[[ -S "$FPM_SOCK" ]] || die "سوکت php-fpm پیدا نشد."
ok "PHP $PHP_VER ($FPM_SOCK)"

# ---------- کپی فایل‌ها ----------
info "کپی فایل‌ها در $INSTALL_DIR ..."
mkdir -p "$INSTALL_DIR/data"
cp -a "$SRC_DIR"/. "$INSTALL_DIR"/
rm -f "$INSTALL_DIR/.gitignore"

SCHEME="https"; [[ "$NO_SSL" == "true" ]] && SCHEME="http"
BASE_URL="$SCHEME://$DOMAIN"
CONFIG="$INSTALL_DIR/config.php"
if [[ -f "$CONFIG" ]]; then
    warn "config.php از قبل وجود دارد و دست نخورد (برای تغییر، خودش را ویرایش کنید)."
    SECRET="$(php -r '$c = require $argv[1]; echo $c["bots"]["mirza"]["secret"] ?? "";' "$CONFIG")"
else
    SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
    GW_CONFIG="$CONFIG" GW_BASE_URL="$BASE_URL" GW_SITE="$SITE_NAME" GW_MERCHANT="$MERCHANT" \
    GW_SANDBOX="$SANDBOX" GW_DB="$INSTALL_DIR/data/gateway.sqlite" GW_BOT_TITLE="$BOT_TITLE" \
    GW_SECRET="$SECRET" GW_NOTIFY="${NOTIFY_URL:-https://BOT_DOMAIN/PATH/payment/zarinpal_gateway/notify.php}" \
    GW_RETURN="${RETURN_URL:-https://t.me/YourBot}" \
    php -r '
        $c = [
            "base_url" => getenv("GW_BASE_URL"),
            "site_name" => getenv("GW_SITE"),
            "support_url" => "",
            "zarinpal" => [
                "merchant_id" => getenv("GW_MERCHANT"),
                "sandbox" => getenv("GW_SANDBOX") === "true",
                "api_base" => "",
            ],
            "db_path" => getenv("GW_DB"),
            "geo_check" => "off",
            "bots" => [
                "mirza" => [
                    "title" => getenv("GW_BOT_TITLE"),
                    "secret" => getenv("GW_SECRET"),
                    "notify_url" => getenv("GW_NOTIFY"),
                    "return_url" => getenv("GW_RETURN"),
                    "min_amount" => 5000,
                    "max_amount" => 10000000,
                ],
            ],
        ];
        file_put_contents(getenv("GW_CONFIG"), "<?php\n// ساخته‌شده با install.sh\nreturn " . var_export($c, true) . ";\n");
    '
    ok "config.php ساخته شد."
fi
chown -R www-data:www-data "$INSTALL_DIR"
chmod 640 "$CONFIG"
chmod 750 "$INSTALL_DIR/data"

# ---------- vhost ----------
DENY_RE='^/(data/|config\.php|config\.sample\.php|lib\.php|retry_notify\.php|install\.sh)'
if [[ "$WEB" == "nginx" ]]; then
    VHOST="/etc/nginx/sites-available/pay-gateway.conf"
    if [[ -f "$VHOST" ]] && grep -q "managed-by-certbot\|# managed by Certbot" "$VHOST"; then
        info "vhost قبلی nginx (با SSL) حفظ شد: $VHOST"
    else
        cat > "$VHOST" <<EOF
# درگاه واسط زرین‌پال — ساخته‌شده با install.sh
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $INSTALL_DIR;
    index index.php;
    client_max_body_size 1m;

    location ~ $DENY_RE { return 404; }
    location ~ /\.(?!well-known) { return 404; }

    location / { try_files \$uri \$uri/ =404; }

    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:$FPM_SOCK;
    }
}
EOF
        ln -sf "$VHOST" /etc/nginx/sites-enabled/pay-gateway.conf
    fi
    nginx -t 2>&1 | tail -2
    systemctl reload nginx || systemctl restart nginx
else
    VHOST="/etc/apache2/sites-available/pay-gateway.conf"
    cat > "$VHOST" <<EOF
# درگاه واسط زرین‌پال — ساخته‌شده با install.sh
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $INSTALL_DIR
    <Directory $INSTALL_DIR>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \.php\$>
        SetHandler "proxy:unix:$FPM_SOCK|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
EOF
    a2enmod -q proxy_fcgi setenvif rewrite >/dev/null
    a2ensite -q pay-gateway >/dev/null
    apache2ctl configtest
    systemctl reload apache2
fi
ok "vhost برای $DOMAIN فعال شد."

# ---------- SSL ----------
if [[ "$NO_SSL" != "true" ]]; then
    info "گرفتن گواهی SSL برای $DOMAIN ..."
    EMAIL_ARGS=(--register-unsafely-without-email)
    [[ -n "$EMAIL" ]] && EMAIL_ARGS=(-m "$EMAIL")
    certbot "--$WEB" -d "$DOMAIN" --non-interactive --agree-tos --redirect --keep-until-expiring "${EMAIL_ARGS[@]}" \
        || die "گرفتن گواهی SSL ناموفق بود. DNS دامنه و باز بودن پورت 80 را چک کنید و اسکریپت را دوباره اجرا کنید."
    ok "SSL فعال شد."
fi

# ---------- کرون ----------
cat > /etc/cron.d/pay-gateway <<EOF
# ارسال دوباره‌ی نتیجه‌ی پرداخت به ربات
*/5 * * * * www-data php $INSTALL_DIR/retry_notify.php >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/pay-gateway
ok "کرون ارسال دوباره نصب شد."

# ---------- تست ----------
info "تست..."
code() { curl -s -o /dev/null -w '%{http_code}' -m 15 --resolve "$DOMAIN:${2}:127.0.0.1" "$1"; }
PORT=443; [[ "$NO_SSL" == "true" ]] && PORT=80
HOME_BODY="$(curl -s -m 15 --resolve "$DOMAIN:$PORT:127.0.0.1" "$BASE_URL/" || true)"
if grep -q 'لینک نامعتبر' <<<"$HOME_BODY"; then ok "صفحه‌ی درگاه بالا آمد: $BASE_URL"; else warn "صفحه‌ی اصلی درگاه جواب مورد انتظار را نداد؛ لاگ‌ها: $INSTALL_DIR/data/error.log و لاگ $WEB"; fi
for p in config.php data/gateway.sqlite lib.php; do
    c="$(code "$BASE_URL/$p" "$PORT")"
    if [[ "$c" == "403" || "$c" == "404" ]]; then ok "$p از بیرون بسته است ($c)"; else warn "$p از بیرون در دسترس است (HTTP $c)! کانفیگ وب‌سرور را چک کنید."; fi
done

cat <<EOF

────────────────────────────────────────────────────────
 نصب تمام شد.

 آدرس درگاه:      $BASE_URL
 مسیر نصب:        $INSTALL_DIR
 تنظیمات:         $CONFIG
 سندباکس:         $SANDBOX

 این دو مقدار را در payment/zarinpal_gateway/config.php روی سرور ربات بگذارید:
   'gateway_url' => '$BASE_URL',
   'secret'      => '$SECRET',
EOF
if [[ -z "$NOTIFY_URL" || -z "$RETURN_URL" ]]; then
cat <<EOF

 ! هنوز notify_url و/یا return_url ربات تنظیم نشده. آن‌ها را در $CONFIG پر کنید.
EOF
fi
echo "────────────────────────────────────────────────────────"
