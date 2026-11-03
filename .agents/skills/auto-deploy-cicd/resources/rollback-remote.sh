#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# 一键秒级原子回滚脚本 (Instant Zero-Downtime Rollback)
# 用法:
#   bash rollback-remote.sh [TARGET_RELEASE_ID]
# 示例:
#   bash rollback-remote.sh              # 自动回滚到上一个稳定版本
#   bash rollback-remote.sh 20261103_cab5dec  # 回滚到指定版本
# ==============================================================================

DEPLOY_DIR="${DEPLOY_REMOTE_DIR:?DEPLOY_REMOTE_DIR must be specified}"
RELEASES_DIR="$DEPLOY_DIR/releases"
CURRENT_LINK="$DEPLOY_DIR/current"

if [ ! -d "$RELEASES_DIR" ]; then
    echo "❌ 错误: 未找到版本目录 $RELEASES_DIR"
    exit 1
fi

echo "=========================================================="
echo "⏪ 启动项目原子回滚机制..."
echo "📂 根目录: $DEPLOY_DIR"
echo "=========================================================="

CURRENT_TARGET=""
if [ -L "$CURRENT_LINK" ]; then
    CURRENT_TARGET=$(readlink "$CURRENT_LINK" | xargs basename)
    echo "📍 当前正在运行的版本: $CURRENT_TARGET"
fi

# 获取所有历史版本（按时间倒序排列）
ALL_RELEASES=($(ls -1dt "$RELEASES_DIR"/* | xargs -n 1 basename))

if [ ${#ALL_RELEASES[@]} -le 1 ]; then
    echo "⚠️ 仅检测到 1 个历史版本，无更早版本可供回滚！"
    exit 1
fi

TARGET_VERSION="${1:-}"

# 如果用户没有指定版本，自动选取当前版本的下一个（即上一个发布版本）
if [ -z "$TARGET_VERSION" ]; then
    for ((i=0; i<${#ALL_RELEASES[@]}; i++)); do
        if [ "${ALL_RELEASES[$i]}" == "$CURRENT_TARGET" ]; then
            NEXT_INDEX=$((i + 1))
            if [ $NEXT_INDEX -lt ${#ALL_RELEASES[@]} ]; then
                TARGET_VERSION="${ALL_RELEASES[$NEXT_INDEX]}"
                break
            fi
        fi
    done
    if [ -z "$TARGET_VERSION" ]; then
        TARGET_VERSION="${ALL_RELEASES[1]}"
    fi
fi

TARGET_PATH="$RELEASES_DIR/$TARGET_VERSION"
if [ ! -d "$TARGET_PATH" ]; then
    # 支持模糊前缀匹配（如只输入 commit sha 的前 7 位）
    MATCHED=$(ls -1d "$RELEASES_DIR"/*"$TARGET_VERSION"* 2>/dev/null | head -n 1 || true)
    if [ -n "$MATCHED" ] && [ -d "$MATCHED" ]; then
        TARGET_PATH="$MATCHED"
        TARGET_VERSION=$(basename "$MATCHED")
    else
        echo "❌ 错误: 目标版本 [$TARGET_VERSION] 在 releases/ 目录下不存在！"
        echo "📜 可选历史版本列表:"
        ls -1dt "$RELEASES_DIR"/* | xargs -n 1 basename
        exit 1
    fi
fi

echo "🎯 正在将线上流量原子切换至目标版本: [$TARGET_VERSION] ..."

# 原子切换软链接（避免中间状态被请求打到空目录）
ln -sfn "$TARGET_PATH" "${DEPLOY_DIR}/current_tmp"
mv -Tf "${DEPLOY_DIR}/current_tmp" "$CURRENT_LINK"

# 修正权限并热重载服务
if id "www" &>/dev/null; then
    chown -h www:www "$CURRENT_LINK" 2>/dev/null || true
fi

if systemctl is-active --quiet php-fpm 2>/dev/null; then
    systemctl reload php-fpm 2>/dev/null || true
elif systemctl is-active --quiet php82-php-fpm 2>/dev/null; then
    systemctl reload php82-php-fpm 2>/dev/null || true
fi

echo "=========================================================="
echo "🎉 回滚成功！当前生效版本已切换为: $TARGET_VERSION"
echo "⏱️ 切换耗时: < 1 秒 (零停机原子软链接切换)"
echo "=========================================================="
