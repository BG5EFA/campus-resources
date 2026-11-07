#!/bin/bash
# 从备份恢复数据库（危险操作，默认需交互确认）
# 用法：/root/restore_campus.sh <db_YYYYMMDD_HHMMSS.sql.gz> [--yes]
set -euo pipefail

BACKUP_DIR="/www/backup/campus"
MYSQL_PWD_FILE="/root/.mysql_root"
DB_NAME="biaobai"
SRC="${1:-}"
ASSUME_YES="${2:-}"

if [ -z "$SRC" ] || [ ! -f "$SRC" ]; then
  echo "用法: $0 <db_YYYYMMDD_HHMMSS.sql.gz> [--yes]" >&2
  echo "可用备份（最新在前）:" >&2
  ls -1t "$BACKUP_DIR"/db_*.sql.gz 2>/dev/null | head -10 >&2
  exit 1
fi

if [ ! -r "$MYSQL_PWD_FILE" ]; then
  echo "读不到 $MYSQL_PWD_FILE" >&2
  exit 1
fi
if ! gzip -t "$SRC"; then
  echo "备份文件损坏，已中止" >&2
  exit 1
fi

if [ "$ASSUME_YES" != "--yes" ]; then
  printf '确认用 %s 覆盖当前 %s 数据库？输入 yes 继续: ' "$SRC" "$DB_NAME"
  read -r ans
  if [ "$ans" != "yes" ]; then
    echo "已取消"
    exit 1
  fi
fi

# 恢复前先给当前库做一份保护性快照，误操作可回退
SNAP="$BACKUP_DIR/pre_restore_$(date +%Y%m%d_%H%M%S).sql.gz"
if ! mysqldump --single-transaction --quick -uroot -p"$(cat "$MYSQL_PWD_FILE")" \
      "$DB_NAME" | gzip -9 > "$SNAP"; then
  echo "恢复前快照失败，已中止（不会动现有数据）" >&2
  rm -f "$SNAP"
  exit 1
fi
echo "已保存恢复前快照: $SNAP"

gunzip -c "$SRC" | mysql -uroot -p"$(cat "$MYSQL_PWD_FILE")" "$DB_NAME"
echo "恢复完成: $SRC"
