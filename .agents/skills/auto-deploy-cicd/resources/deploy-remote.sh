#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 通用多环境自适应原子化发布脚本 (Multi-OS Self-Adaptive Deploy Script)
# 支持环境：原生 Ubuntu/Debian、CentOS/Alibaba Cloud Linux/Rocky、宝塔面板、1Panel 等
# ==============================================================================

DEPLOY_DIR="${DEPLOY_REMOTE_DIR:?DEPLOY_REMOTE_DIR must be specified}"
RELEASE_ARCHIVE="/tmp/release-${RELEASE_ID:?RELEASE_ID must be specified}.tar.gz"

DATE_STR=$(date +%Y%m%d_%H%M%S)
SHORT_SHA="${RELEASE_ID:0:7}"
NEW_RELEASE_DIR="$DEPLOY_DIR/releases/${DATE_STR}_${SHORT_SHA}"

echo "----------------------------------------------------"
echo "🚀 开始在服务器执行版本发布激活..."
echo "📂 根目录: $DEPLOY_DIR"
echo "📦 发布归档文件: $RELEASE_ARCHIVE"
echo "🏷️ 新版本目录: $NEW_RELEASE_DIR"
echo "----------------------------------------------------"

# 1. 确保核心目录就绪
mkdir -p "$DEPLOY_DIR/releases"
mkdir -p "$DEPLOY_DIR/shared/uploads"
mkdir -p "$DEPLOY_DIR/shared/logs"
mkdir -p "$NEW_RELEASE_DIR"

# 2. 如果存在初次安装遗留在根目录的旧文件，自动迁移到 shared 目录保护
for file in "config.php" ".env" "install.lock"; do
    if [ -f "$DEPLOY_DIR/$file" ] && [ ! -L "$DEPLOY_DIR/$file" ]; then
        mv "$DEPLOY_DIR/$file" "$DEPLOY_DIR/shared/$file"
        echo "🔒 已自动迁移生产文件至持久化目录: shared/$file"
    fi
done

# 3. 解压最新版本代码至新版本目录
echo "📦 正在解压新版本代码..."
tar -xzf "$RELEASE_ARCHIVE" -C "$NEW_RELEASE_DIR"

# 4. 建立持久化共享目录与配置的软链接
echo "🔗 正在关联持久化数据与生产配置文件..."
if [ -f "$DEPLOY_DIR/shared/config.php" ]; then
    ln -sfn "$DEPLOY_DIR/shared/config.php" "$NEW_RELEASE_DIR/config.php"
elif [ -f "$NEW_RELEASE_DIR/config.php.example" ]; then
    cp "$NEW_RELEASE_DIR/config.php.example" "$DEPLOY_DIR/shared/config.php"
    ln -sfn "$DEPLOY_DIR/shared/config.php" "$NEW_RELEASE_DIR/config.php"
    echo "⚠️ 自动从 example 复制生成生产 shared/config.php"
fi

if [ -f "$DEPLOY_DIR/shared/.env" ]; then
    ln -sfn "$DEPLOY_DIR/shared/.env" "$NEW_RELEASE_DIR/.env"
fi

if [ -f "$DEPLOY_DIR/shared/install.lock" ]; then
    ln -sfn "$DEPLOY_DIR/shared/install.lock" "$NEW_RELEASE_DIR/install.lock"
fi

rm -rf "$NEW_RELEASE_DIR/uploads" && ln -sfn "$DEPLOY_DIR/shared/uploads" "$NEW_RELEASE_DIR/uploads"
rm -rf "$NEW_RELEASE_DIR/logs" && ln -sfn "$DEPLOY_DIR/shared/logs" "$NEW_RELEASE_DIR/logs"

# 5. 自动探测 Web 运行用户（自适应 Ubuntu www-data、CentOS nginx、宝塔 www）
echo "🔧 自动识别操作系统环境并修正权限..."
WEB_USER=""
if id "www" &>/dev/null; then
    WEB_USER="www"
elif id "www-data" &>/dev/null; then
    WEB_USER="www-data"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
fi

if [ -n "$WEB_USER" ]; then
    echo "👤 识别到 Web 运行用户为: $WEB_USER"
    chown -R "$WEB_USER:$WEB_USER" "$NEW_RELEASE_DIR" 2>/dev/null || true
    chown -R "$WEB_USER:$WEB_USER" "$DEPLOY_DIR/shared" 2>/dev/null || true
fi

chmod -R 755 "$NEW_RELEASE_DIR" 2>/dev/null || true
chmod -R 777 "$DEPLOY_DIR/shared/uploads" "$DEPLOY_DIR/shared/logs" 2>/dev/null || true

# 6. 原子切换软链接 (零停机上线)
echo "⚡ 正在原子切换当前运行版本..."
ln -sfn "$NEW_RELEASE_DIR" "${DEPLOY_DIR}/current_tmp"
mv -Tf "${DEPLOY_DIR}/current_tmp" "${DEPLOY_DIR}/current"

# 7. 保留最近 5 个版本，清理陈旧历史版本
echo "🧹 清理旧版本（保留最近 5 个版本）..."
cd "$DEPLOY_DIR/releases"
ls -1dt * 2>/dev/null | tail -n +6 | xargs -r rm -rf -- || true

# 8. 清理临时压缩包
rm -f "$RELEASE_ARCHIVE"

# 9. 智能自适应重载 PHP-FPM / Nginx 刷新缓存
echo "🔄 正在刷新服务与 OPCache 缓存..."
for s in "php-fpm" "php8.2-fpm" "php82-php-fpm" "php8.3-fpm" "php8.1-fpm" "php7.4-fpm" "php74-php-fpm"; do
    if systemctl is-active --quiet "$s" 2>/dev/null; then
        systemctl reload "$s" 2>/dev/null || true
        echo "✅ 已热重载: $s"
        break
    fi
done

if systemctl is-active --quiet nginx 2>/dev/null; then
    systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
fi

echo "----------------------------------------------------"
echo "✅ 项目已成功原子激活上线！当前版本: ${DATE_STR}_${SHORT_SHA}"
echo "----------------------------------------------------"
