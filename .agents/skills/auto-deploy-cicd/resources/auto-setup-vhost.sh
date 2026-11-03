#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 全环境自适应建站与 SSL 初始化脚本 (Universal Vhost & SSL Setup Script)
# 自动兼容:
#   1. 宝塔面板 (Alibaba Cloud Linux / CentOS / Debian / Ubuntu)
#   2. 原生 Ubuntu / Debian (Nginx + PHP-FPM + Certbot)
#   3. 原生 CentOS / RHEL / Rocky Linux (Nginx + PHP-FPM + Certbot)
#   4. 1Panel 等现代 Linux 面板
# ==============================================================================

DOMAIN="${1:?错误: 必须指定域名，例如: app.moxunlink.cn}"
PROJECT="${2:?错误: 必须指定项目标识名，例如: my-saas}"
PHP_VER="${3:-8.2}"
PHP_VER_DIGITS="${PHP_VER//./}"

echo "=========================================================="
echo "🔍 正在自动探测服务器操作系统与 Web 环境类型..."
echo "  - 目标域名: $DOMAIN"
echo "  - 项目标识: $PROJECT"
echo "  - 指定 PHP: $PHP_VER"
echo "=========================================================="

# 1. 自动判断 Web 根目录前缀与 Web 用户
BASE_WEBROOT="/var/www"
WEB_USER="www-data"

if [ -d "/www/server/panel" ] || [ -d "/www/wwwroot" ]; then
    SERVER_TYPE="baota"
    BASE_WEBROOT="/www/wwwroot"
    WEB_USER="www"
    echo "💡 探测结果: 宝塔面板环境"
elif [ -f "/etc/debian_version" ]; then
    SERVER_TYPE="debian"
    BASE_WEBROOT="/var/www"
    WEB_USER="www-data"
    echo "💡 探测结果: 原生 Debian/Ubuntu 环境"
else
    SERVER_TYPE="redhat"
    BASE_WEBROOT="/var/www"
    WEB_USER="nginx"
    echo "💡 探测结果: 原生 RHEL/CentOS/Alinux 环境"
fi

ROOT_DIR="$BASE_WEBROOT/$PROJECT"

# 2. 自动定位 Nginx 配置路径
NGINX_CONF=""
EXT_DIR=""

if [ "$SERVER_TYPE" == "baota" ]; then
    NGINX_CONF="/www/server/panel/vhost/nginx/$DOMAIN.conf"
    EXT_DIR="/www/server/panel/vhost/nginx/extension/$DOMAIN"
elif [ -d "/etc/nginx/conf.d" ]; then
    NGINX_CONF="/etc/nginx/conf.d/$DOMAIN.conf"
    EXT_DIR="/etc/nginx/snippets/$DOMAIN"
elif [ -d "/etc/nginx/sites-available" ]; then
    NGINX_CONF="/etc/nginx/sites-available/$DOMAIN.conf"
    EXT_DIR="/etc/nginx/snippets/$DOMAIN"
fi

mkdir -p "$EXT_DIR"
mkdir -p "$ROOT_DIR/releases"
mkdir -p "$ROOT_DIR/shared/uploads"
mkdir -p "$ROOT_DIR/shared/logs"

# 3. 自动探测 PHP FastCGI Socket 或端口
PHP_PASS=""
if [ -S "/tmp/php-cgi-${PHP_VER_DIGITS}.sock" ]; then
    PHP_PASS="unix:/tmp/php-cgi-${PHP_VER_DIGITS}.sock;"
elif [ -S "/run/php/php${PHP_VER}-fpm.sock" ]; then
    PHP_PASS="unix:/run/php/php${PHP_VER}-fpm.sock;"
elif [ -S "/var/run/php-fpm/www.sock" ]; then
    PHP_PASS="unix:/var/run/php-fpm/www.sock;"
elif nc -z 127.0.0.1 9000 2>/dev/null; then
    PHP_PASS="127.0.0.1:9000;"
else
    PHP_PASS="unix:/tmp/php-cgi-82.sock;" # 默认回退
fi
echo "🐘 探测到 FastCGI 通信后端: $PHP_PASS"

# 4. 创建初始占位版本
INIT_RELEASE="$ROOT_DIR/releases/initial_$(date +%Y%m%d%H%M%S)"
mkdir -p "$INIT_RELEASE"
cat << EOF > "$INIT_RELEASE/index.html"
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>$DOMAIN - 初始化成功</title></head>
<body style="font-family:sans-serif;text-align:center;padding:80px 20px;">
  <h2>🚀 站点 [$DOMAIN] 底座已就绪</h2>
  <p>等待 GitHub Actions 首次自动化代码发布...</p>
</body>
</html>
EOF

ln -sfn "$INIT_RELEASE" "$ROOT_DIR/current"

if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" "$ROOT_DIR" 2>/dev/null || true
fi
chmod -R 755 "$ROOT_DIR" 2>/dev/null || true
chmod -R 777 "$ROOT_DIR/shared/uploads" "$ROOT_DIR/shared/logs" 2>/dev/null || true

# 5. 生成通用安全拦截规则
cat << 'EOF' > "$EXT_DIR/security.conf"
location ~* \.(sql|bak|inc|old|log|env|yml|yaml|ini)$ { return 404; }
location ~* /(config\.php|install\.lock|\.git|\.user\.ini|\.htaccess) { return 404; }
location ~* ^/(includes|cron|logs|storage/logs)/ { return 404; }
location ~* ^/install\.php { return 404; }
location ~* ^/uploads/.*\.(php|phtml|phar|sh|pl|py|cgi)$ { deny all; }
EOF

# 6. 生成标准 Nginx 配置
cat << EOF > "$NGINX_CONF"
server
{
    listen 80;
    server_name $DOMAIN;
    index index.php index.html index.htm;
    root $ROOT_DIR/current;

    include $EXT_DIR/*.conf;

    location ~ \.php$ {
        fastcgi_pass $PHP_PASS
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|LICENSE|README.md) {
        return 404;
    }

    location ~ \.well-known {
        allow all;
    }

    access_log  /var/log/nginx/${DOMAIN}.log;
    error_log   /var/log/nginx/${DOMAIN}.error.log;
}
EOF

# 若为 Debian sites-available，创建 sites-enabled 软链接
if [ -d "/etc/nginx/sites-enabled" ] && [ ! -L "/etc/nginx/sites-enabled/$DOMAIN.conf" ]; then
    ln -sfn "$NGINX_CONF" "/etc/nginx/sites-enabled/$DOMAIN.conf"
fi

nginx -t && nginx -s reload
echo "✅ HTTP 站点底座配置成功！"

# 7. 自动申请 SSL 证书并开启强制 HTTPS
echo "🔒 正在为 [$DOMAIN] 申请 SSL 证书..."
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
if [ "$SERVER_TYPE" == "baota" ]; then
    CERT_DIR="/www/server/panel/vhost/cert/$DOMAIN"
fi
mkdir -p "$CERT_DIR"

if command -v certbot &>/dev/null; then
    certbot certonly --webroot -w "$ROOT_DIR/current" -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email || true
elif [ -f "$HOME/.acme.sh/acme.sh" ]; then
    "$HOME/.acme.sh/acme.sh" --issue -d "$DOMAIN" -w "$ROOT_DIR/current" --nginx || true
    "$HOME/.acme.sh/acme.sh" --install-cert -d "$DOMAIN" \
        --key-file "$CERT_DIR/privkey.pem" \
        --fullchain-file "$CERT_DIR/fullchain.pem" \
        --reloadcmd "nginx -s reload" || true
fi

# 检查证书是否存在并开启 HTTPS
FULLCHAIN=""
PRIVKEY=""
if [ -f "$CERT_DIR/fullchain.pem" ]; then
    FULLCHAIN="$CERT_DIR/fullchain.pem"
    PRIVKEY="$CERT_DIR/privkey.pem"
elif [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    FULLCHAIN="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
    PRIVKEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
fi

if [ -n "$FULLCHAIN" ] && [ -f "$FULLCHAIN" ]; then
    cat << EOF > "$NGINX_CONF"
server
{
    listen 80;
    listen 443 ssl;
    listen 443 quic;
    http2 on;
    server_name $DOMAIN;
    index index.php index.html index.htm;
    root $ROOT_DIR/current;

    ssl_certificate     $FULLCHAIN;
    ssl_certificate_key $PRIVKEY;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers EECDH+CHACHA20:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:!MD5;
    ssl_prefer_server_ciphers on;
    add_header Strict-Transport-Security "max-age=31536000";

    if (\$server_port !~ 443){
        rewrite ^(/.*)$ https://\$host\$1 permanent;
    }

    include $EXT_DIR/*.conf;

    location ~ \.php$ {
        fastcgi_pass $PHP_PASS
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|LICENSE|README.md) {
        return 404;
    }

    location ~ \.well-known {
        allow all;
    }
}
EOF
    nginx -t && nginx -s reload
    echo "🎉 SSL 证书配置成功，已自动开启全站强制 HTTPS！"
fi

echo "=========================================================="
echo "🎯 新站点初始化就绪！"
echo "  - 访问地址: https://$DOMAIN"
echo "  - 部署根目录: $ROOT_DIR"
echo "  - GitHub Secret DEPLOY_REMOTE_DIR: $ROOT_DIR"
echo "=========================================================="
