#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 通用自动化部署与激活脚本 (Auto-Deploy Remote Activator)
# 运行环境：远程 Linux 服务器 (Bash)
# ==============================================================================

DEPLOY_DIR="${DEPLOY_REMOTE_DIR:?DEPLOY_REMOTE_DIR must be specified}"
RELEASE_ARCHIVE="/tmp/release-${RELEASE_ID:?RELEASE_ID must be specified}.tar.gz"

echo "----------------------------------------------------"
echo "🚀 开始在服务器执行版本发布激活..."
echo "📂 目标部署目录: $DEPLOY_DIR"
echo "📦 发布归档文件: $RELEASE_ARCHIVE"
echo "----------------------------------------------------"

# 1. 确保目标部署目录存在
mkdir -p "$DEPLOY_DIR"

# 2. 安全备份生产配置文件（保证 CI/CD 更新绝不会冲掉生产数据库连接密码与环境变量）
BACKUP_TMP="/tmp/deploy-config-backup-${RELEASE_ID}"
mkdir -p "$BACKUP_TMP"
for cfg in "config.php" ".env" "install.lock"; do
    if [ -f "$DEPLOY_DIR/$cfg" ]; then
        cp "$DEPLOY_DIR/$cfg" "$BACKUP_TMP/$cfg"
    fi
done

# 3. 解压最新版本代码至部署根目录
echo "📦 正在解压新版本代码..."
tar -xzf "$RELEASE_ARCHIVE" -C "$DEPLOY_DIR"

# 4. 恢复持久化的生产配置文件
for cfg in "config.php" ".env" "install.lock"; do
    if [ -f "$BACKUP_TMP/$cfg" ]; then
        mv "$BACKUP_TMP/$cfg" "$DEPLOY_DIR/$cfg"
        echo "🔒 已恢复生产配置: $cfg"
    fi
done
rm -rf "$BACKUP_TMP"

# 5. 修正目录归属与安全读写权限
# 注意：宝塔环境默认对 .user.ini 加了免疫属性 (chattr +i)，此处必须静默容错 (|| true) 避免流水线中断
echo "🔧 配置目录归属与读写权限..."
if id "www" &>/dev/null; then
    chown -R www:www "$DEPLOY_DIR" 2>/dev/null || true
elif id "nginx" &>/dev/null; then
    chown -R nginx:nginx "$DEPLOY_DIR" 2>/dev/null || true
fi

chmod -R 755 "$DEPLOY_DIR" 2>/dev/null || true
if [ -d "$DEPLOY_DIR/uploads" ]; then
    chmod -R 777 "$DEPLOY_DIR/uploads" 2>/dev/null || true
fi
if [ -d "$DEPLOY_DIR/logs" ]; then
    chmod -R 777 "$DEPLOY_DIR/logs" 2>/dev/null || true
fi

# 6. 清理临时压缩包
rm -f "$RELEASE_ARCHIVE"

# 7. 重载 Web / PHP / Node 服务刷新缓存
if systemctl is-active --quiet php-fpm 2>/dev/null; then
    systemctl reload php-fpm 2>/dev/null || true
elif systemctl is-active --quiet php82-php-fpm 2>/dev/null; then
    systemctl reload php82-php-fpm 2>/dev/null || true
fi

echo "----------------------------------------------------"
echo "✅ 项目已成功激活上线！"
echo "----------------------------------------------------"
