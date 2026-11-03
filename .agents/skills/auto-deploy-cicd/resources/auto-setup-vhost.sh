#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 新域名全自动建站与 SSL 初始化脚本 (Auto Setup Vhost & SSL)
# 用法:
#   bash auto-setup-vhost.sh <DOMAIN> <PROJECT_NAME> [PHP_VERSION]
# 示例:
#   bash auto-setup-vhost.sh shop.example.com shop-saas 82
# ==============================================================================

DOMAIN="${1:?错误: 必须指定域名，例如: app.moxunlink.cn}"
PROJECT="${2:?错误: 必须指定项目标识名，例如: my-saas}"
PHP_VER="${3:-82}"

ROOT_DIR="/www/wwwroot/$PROJECT"
NGINX_CONF="/www/server/panel/vhost/nginx/$DOMAIN.conf"
EXT_DIR="/www/server/panel/vhost/nginx/extension/$DOMAIN"

echo "=========================================================="
echo "🚀 开始为域名 [$DOMAIN] 初始化站点与自动化部署底座..."
echo "📂 根目录: $ROOT_DIR"
echo "🐘 PHP 版本: $PHP_VER"
echo "=========================================================="

# 1. 创建现代原子发布目录架构
mkdir -p "$ROOT_DIR/releases"
mkdir -p "$ROOT_DIR/shared/uploads"
mkdir -p "$ROOT_DIR/shared/logs"
mkdir -p "$EXT_DIR"

# 创建占位初始版本并链接
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

# 2. 修正权限（忽略宝塔 .user.ini 锁）
if id "www" &>/dev/null; then
    chown -R www:www "$ROOT_DIR" 2>/dev/null || true
fi
chmod -R 755 "$ROOT_DIR" 2>/dev/null || true
chmod -R 777 "$ROOT_DIR/shared/uploads" "$ROOT_DIR/shared/logs" 2>/dev/null || true

# 3. 部署生产安全拦截规则
cat << 'EOF' > "$EXT_DIR/security.conf"
# 1. 禁止访问敏感数据文件与版本控制
location ~* \.(sql|bak|inc|old|log|env|yml|yaml|ini)$ { return 404; }
location ~* /(config\.php|install\.lock|\.git|\.user\.ini|\.htaccess) { return 404; }

# 2. 禁止直接访问私有代码与日志
location ~* ^/(includes|cron|logs|storage/logs)/ { return 404; }

# 3. 上线后禁用安装向导
location ~* ^/install\.php { return 404; }

# 4. 上传静态媒体目录禁止解析任何可执行脚本
location ~* ^/uploads/.*\.(php|phtml|phar|sh|pl|py|cgi)$ { deny all; }
EOF

# 4. 生成初版 Nginx 配置（支持 ACME 验证与安全规则）
cat << EOF > "$NGINX_CONF"
server
{
    listen 80;
    server_name $DOMAIN;
    index index.php index.html index.htm default.php default.html;
    root $ROOT_DIR/current;

    include $EXT_DIR/*.conf;
    include enable-php-$PHP_VER.conf;

    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|LICENSE|README.md) {
        return 404;
    }

    location ~ \.well-known {
        allow all;
    }

    access_log  /www/wwwlogs/$DOMAIN.log;
    error_log   /www/wwwlogs/$DOMAIN.error.log;
}
EOF

nginx -t && nginx -s reload
echo "✅ HTTP 站点底座配置完成并生效！"

# 5. 自动申请 Let's Encrypt 证书并启用强制 HTTPS
echo "🔒 正在为 [$DOMAIN] 申请 Let's Encrypt SSL 证书..."
CERT_DIR="/www/server/panel/vhost/cert/$DOMAIN"
mkdir -p "$CERT_DIR"

# 优先使用 acme.sh 或 certbot
if [ -f "$HOME/.acme.sh/acme.sh" ]; then
    "$HOME/.acme.sh/acme.sh" --issue -d "$DOMAIN" -w "$ROOT_DIR/current" --nginx || true
    "$HOME/.acme.sh/acme.sh" --install-cert -d "$DOMAIN" \
        --key-file       "$CERT_DIR/privkey.pem" \
        --fullchain-file "$CERT_DIR/fullchain.pem" \
        --reloadcmd     "nginx -s reload" || true
fi

# 如果证书生成成功，更新 Nginx 启用 HTTPS
if [ -f "$CERT_DIR/fullchain.pem" ] && [ -f "$CERT_DIR/privkey.pem" ]; then
    cat << EOF > "$NGINX_CONF"
server
{
    listen 80;
    listen 443 ssl;
    listen 443 quic;
    http2 on;
    server_name $DOMAIN;
    index index.php index.html index.htm default.php default.html;
    root $ROOT_DIR/current;

    ssl_certificate     $CERT_DIR/fullchain.pem;
    ssl_certificate_key $CERT_DIR/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers EECDH+CHACHA20:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:!MD5;
    ssl_prefer_server_ciphers on;
    add_header Strict-Transport-Security "max-age=31536000";

    # 强制 HTTPS
    if (\$server_port !~ 443){
        rewrite ^(/.*)$ https://\$host\$1 permanent;
    }

    include $EXT_DIR/*.conf;
    include enable-php-$PHP_VER.conf;

    location ~ ^/(\.user.ini|\.htaccess|\.git|\.env|LICENSE|README.md) {
        return 404;
    }

    location ~ \.well-known {
        allow all;
    }

    access_log  /www/wwwlogs/$DOMAIN.log;
    error_log   /www/wwwlogs/$DOMAIN.error.log;
}
EOF
    nginx -t && nginx -s reload
    echo "🎉 SSL 证书配置成功，已开启全站强制 HTTPS！"
else
    echo "⚠️ ACME 证书签发未立即完成（可能 DNS 刚解析），您也可在宝塔面板点击【SSL -> Let's Encrypt】一键完成申请。"
fi

echo "=========================================================="
echo "🎯 站点准备就绪！"
echo "  - 域名: https://$DOMAIN"
echo "  - 部署目录: $ROOT_DIR/current"
echo "  - GitHub Secret DEPLOY_REMOTE_DIR 请配置为: $ROOT_DIR"
echo "=========================================================="
