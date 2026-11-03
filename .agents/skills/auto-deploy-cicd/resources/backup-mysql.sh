#!/bin/bash
# ========================================================
# 生产 MySQL 数据库每日定时增量/全量备份脚本
# ========================================================

BACKUP_DIR="${BACKUP_DIR:-/www/backup/database}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS}"
DB_NAME="${DB_NAME}"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# 注意：MySQL 8.0+ 默认需要 PROCESS 权限，若无权限需增加 --no-tablespaces 参数
mysqldump --no-tablespaces -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null | gzip > "$BACKUP_DIR/${DB_NAME}_${DATE}.sql.gz"

# 自动保留最近 30 天的备份，清理历史归档
find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" -type f -mtime +30 -delete
