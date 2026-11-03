#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 车辆管理系统 (vehicle-saas) · 远程自动化部署脚本
# 运行环境：服务器端 Bash (由 GitHub Actions 远程调用)
# ==============================================================================

DEPLOY_DIR="${DEPLOY_REMOTE_DIR:-/www/wwwroot/vehicle-saas}"
RELEASE_ARCHIVE="/tmp/vehicle-saas-release-${RELEASE_ID}.tar.gz"

echo "----------------------------------------------------"
echo "🚀 开始在服务器执行发布激活..."
echo "📂 目标目录: $DEPLOY_DIR"
echo "📦 发布文件: $RELEASE_ARCHIVE"
echo "----------------------------------------------------"

# 1. 确保目标目录及持久化目录存在
mkdir -p "$DEPLOY_DIR"
mkdir -p "$DEPLOY_DIR/uploads"
mkdir -p "$DEPLOY_DIR/logs"

# 2. 如果存在旧的生产 config.php，进行临时安全备份，确保解压绝不冲掉数据库账号
if [ -f "$DEPLOY_DIR/config.php" ]; then
    cp "$DEPLOY_DIR/config.php" "/tmp/vehicle-saas-config.php.bak"
fi

# 3. 解压最新代码到目标目录
echo "📦 正在解压新版本代码..."
tar -xzf "$RELEASE_ARCHIVE" -C "$DEPLOY_DIR"

# 4. 恢复持久化的生产 config.php（若存在）
if [ -f "/tmp/vehicle-saas-config.php.bak" ]; then
    mv "/tmp/vehicle-saas-config.php.bak" "$DEPLOY_DIR/config.php"
    echo "🔒 已保留原生产环境 config.php 配置"
elif [ ! -f "$DEPLOY_DIR/config.php" ] && [ -f "$DEPLOY_DIR/config.php.example" ]; then
    cp "$DEPLOY_DIR/config.php.example" "$DEPLOY_DIR/config.php"
    echo "⚠️ 未找到生产 config.php，已由 example 复制创建，请记得填入真实数据库密码"
fi

# 5. 修正目录归属与读写权限（兼容宝塔 www 或原生 nginx/apache，忽略 .user.ini 免疫锁）
echo "🔧 配置目录与读写权限..."
if id "www" &>/dev/null; then
    chown -R www:www "$DEPLOY_DIR" 2>/dev/null || true
elif id "nginx" &>/dev/null; then
    chown -R nginx:nginx "$DEPLOY_DIR" 2>/dev/null || true
fi

chmod -R 755 "$DEPLOY_DIR" 2>/dev/null || true
chmod -R 777 "$DEPLOY_DIR/uploads" "$DEPLOY_DIR/logs" 2>/dev/null || true

# 6. 清理临时压缩包
rm -f "$RELEASE_ARCHIVE"

# 7. 重载 PHP-FPM（如果服务存在，加速 OPCache 刷新）
if systemctl is-active --quiet php-fpm 2>/dev/null; then
    systemctl reload php-fpm 2>/dev/null || true
elif systemctl is-active --quiet php82-php-fpm 2>/dev/null; then
    systemctl reload php82-php-fpm 2>/dev/null || true
fi

echo "----------------------------------------------------"
echo "✅ 部署已成功激活完成！"
echo "----------------------------------------------------"
