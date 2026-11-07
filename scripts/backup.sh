#!/bin/bash
# 校园资源站每日备份：MySQL 全库 + uploads 目录
# 部署位置：/root/backup_campus.sh   定时任务：30 3 * * *
set -euo pipefail

SITE_DIR="/www/wwwroot/biaobai"
BACKUP_DIR="/www/backup/campus"
MYSQL_PWD_FILE="/root/.mysql_root"   # 只读文件，脚本内不含明文密码
DB_NAME="biaobai"
KEEP_DAYS=14
TS="$(date +%Y%m%d_%H%M%S)"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }

if [ ! -r "$MYSQL_PWD_FILE" ]; then
  log "ERROR: 读不到 $MYSQL_PWD_FILE，跳过备份"
  exit 1
fi
mkdir -p "$BACKUP_DIR"

DB_FILE="$BACKUP_DIR/db_${TS}.sql.gz"
if ! mysqldump --single-transaction --quick --routines --triggers \
      -uroot -p"$(cat "$MYSQL_PWD_FILE")" "$DB_NAME" | gzip -9 > "$DB_FILE"; then
  log "ERROR: mysqldump 失败"
  rm -f "$DB_FILE"
  exit 1
fi
if ! gzip -t "$DB_FILE"; then
  log "ERROR: 数据库备份校验失败，已删除损坏文件"
  rm -f "$DB_FILE"
  exit 1
fi

UP_FILE="$BACKUP_DIR/uploads_${TS}.tar.gz"
if [ -d "$SITE_DIR/uploads" ]; then
  tar czf "$UP_FILE" -C "$SITE_DIR" uploads
else
  log "WARN: uploads 目录不存在，仅备份数据库"
fi

find "$BACKUP_DIR" -type f \( -name 'db_*.sql.gz' -o -name 'uploads_*.tar.gz' \) \
  -mtime +"$KEEP_DAYS" -delete

UP_SIZE="-"
[ -f "$UP_FILE" ] && UP_SIZE="$(du -h "$UP_FILE" | cut -f1)"
log "OK: db=$(du -h "$DB_FILE" | cut -f1) uploads=$UP_SIZE 保留 ${KEEP_DAYS} 天"
