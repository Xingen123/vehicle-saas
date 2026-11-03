#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 全新 Linux 服务器一键极速初始化底座脚本 (New Server Bootstrap)
# 支持: Ubuntu, Debian, CentOS, Rocky Linux, Alibaba Cloud Linux
# 用法:
#   curl -fsSL https://raw.githubusercontent.com/Xingen123/agent-skills/main/skills/auto-deploy-cicd/resources/init-new-server.sh | bash -s -- "<YOUR_SSH_PUBLIC_KEY>"
# ==============================================================================

SSH_PUB_KEY="${1:-}"

echo "=========================================================="
echo "🚀 开始初始化新服务器自动化部署运行底座..."
echo "=========================================================="

# 1. 自动注入专用部署 SSH 公钥
if [ -n "$SSH_PUB_KEY" ]; then
    echo "🔑 正在配置专属自动化部署公钥..."
    mkdir -p ~/.ssh
    chmod 700 ~/.ssh
    if ! grep -q "$SSH_PUB_KEY" ~/.ssh/authorized_keys 2>/dev/null; then
        echo "$SSH_PUB_KEY" >> ~/.ssh/authorized_keys
    fi
    chmod 600 ~/.ssh/authorized_keys
    echo "✅ SSH 部署免密公钥配置成功！"
fi

# 2. 识别包管理器并安装必备自动化运维工具
echo "📦 正在安装必备运维工具链 (git, curl, tar, gzip, rsync, socat)..."
if command -v apt-get &>/dev/null; then
    apt-get update -y
    apt-get install -y git curl wget tar gzip rsync socat openssl ca-certificates
elif command -v dnf &>/dev/null; then
    dnf install -y git curl wget tar gzip rsync socat openssl ca-certificates
elif command -v yum &>/dev/null; then
    yum install -y git curl wget tar gzip rsync socat openssl ca-certificates
fi

# 3. 安装 acme.sh 自动化 SSL 证书申请工具
if [ ! -f "$HOME/.acme.sh/acme.sh" ]; then
    echo "🔒 正在安装 acme.sh 自动化证书管理器..."
    curl -fsSL https://get.acme.sh | sh -s email=admin@moxunlink.cn || true
fi

# 4. 确保基础部署目录就绪
mkdir -p /www/wwwroot /var/www /www/backup/database /var/backup/database

echo "=========================================================="
echo "🎉 新服务器底座初始化完成！"
echo "  - 现在您可以将新服务器 IP 填入 GitHub Repository Secrets;"
echo "  - 也可以直接使用 auto-setup-vhost.sh 为新域名建站！"
echo "=========================================================="
