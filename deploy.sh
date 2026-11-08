#!/usr/bin/env bash
# 温州市滨海高级中学校园墙 —— 一键部署脚本（需从本机运行，可访问目标服务器）
# 用法：
#   SSH_PASS='你的root密码' ./deploy.sh 38.244.14.74
# 说明：
#   - 仅上传本地源码中“安全可覆盖”的文件，绝不覆盖服务器上的 config.php（含真实数据库密码）
#   - 数据库迁移：全新服务器跑 schema.sql + migrate*.sql；已有数据的服务器只跑 migrate3.sql（脚本会判断）
#   - 需服务器已装 PHP8.2 + MySQL + Nginx + php-fpm；如未装请先 apt/dnf 安装
set -euo pipefail

SERVER_IP="${1:?用法: $0 <服务器IP>}"
SSH_PORT="${SSH_PORT:-22}"
SSH_USER="${SSH_USER:-root}"
SSH_PASS="${SSH_PASS:?请通过环境变量 SSH_PASS 提供 root 密码}"
REMOTE_DIR="/www/wwwroot/biaobai"
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

# ---- SSH 密码包装（无 sshpass 时用 SSH_ASKPASS）----
ASKPASS="$(mktemp)"
printf '#!/bin/bash\necho %q\n' "$SSH_PASS" > "$ASKPASS"
chmod +x "$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

# 注意：不要在此处加 `</dev/null`，否则会覆盖“base64 文件 | ssh” 的数据管道，导致上传/迁移内容丢失（已踩坑）
run_ssh() { SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force ssh -o StrictHostKeyChecking=no -o ConnectTimeout=20 -p "$SSH_PORT" "$SSH_USER@$SERVER_IP" "$@"; }
run_scp_base64() { # 本地文件 -> 远端路径
  local localfile="$1" remotefile="$2"
  base64 -w0 "$localfile" | run_ssh "base64 -d > '$remotefile'"
}

echo "📋 任务分解：部署前端+后端+数据库迁移+Nginx"
echo "⚡ 目标: $SSH_USER@$SERVER_IP:$SSH_PORT  $REMOTE_DIR"

# 1) 远端建目录
run_ssh "mkdir -p '$REMOTE_DIR' '$REMOTE_DIR/uploads/materials' && chmod 755 '$REMOTE_DIR'"

# 2) 上传源码（不覆盖 config.php）
echo "⬆️  上传文件…"
for f in api.php index.html study.html me.html admin.html; do
  run_scp_base64 "$LOCAL_DIR/$f" "$REMOTE_DIR/$f"
  echo "   ✓ $f"
done
# 校徽抠图（/badge.png 直接放 web 根）
if [ -f "$LOCAL_DIR/assets/badge.png" ]; then
  run_scp_base64 "$LOCAL_DIR/assets/badge.png" "$REMOTE_DIR/badge.png"
  echo "   ✓ badge.png"
fi

# 3) 数据库迁移（在服务器本地读取 root 密码，避免明文过 SSH）
echo "🗄️  执行数据库迁移…"
run_ssh "test -f /root/.mysql_root && echo '   MySQL root 密码文件存在' || echo '   ⚠️ 未找到 /root/.mysql_root，迁移可能失败'"

# 在服务器侧读取密码执行 mysql 的封装
MQL='mysql -uroot -p"$(cat /root/.mysql_root 2>/dev/null)" biaobai'
# 判断某张表是否存在（返回 1 表示存在，0 表示不存在）
has_tbl() {
  local t="$1"
  run_ssh "$MQL -N -e \"SHOW TABLES LIKE '$t'\" 2>/dev/null" 2>/dev/null | grep -c . || true
}
run_sql_file() {
  local sql="$1"
  [ -f "$LOCAL_DIR/$sql" ] || { echo "   ⚠️ 缺少 $sql，跳过"; return; }
  # 关键：远端必须用 base64 -d 解码后再交给 mysql（之前漏了 -d 导致把编码串当 SQL 执行）
  base64 -w0 "$LOCAL_DIR/$sql" | run_ssh "base64 -d | $MQL" && echo "   ✓ $sql 已应用"
}

# 每张表独立判断：已存在的迁移自动跳过，未应用的才执行（可重入、安全）
[ "$(has_tbl posts)" = "0" ] && { echo "   全新库 → 先建库结构"; run_sql_file schema.sql; }
[ "$(has_tbl users)" = "0" ] && [ -f "$LOCAL_DIR/migrate.sql" ] && { echo "   增量 → users"; run_sql_file migrate.sql; }
[ "$(has_tbl material_categories)" = "0" ] && [ -f "$LOCAL_DIR/migrate2.sql" ] && { echo "   增量 → 学习资料"; run_sql_file migrate2.sql; }
[ "$(has_tbl notifications)" = "0" ] && [ -f "$LOCAL_DIR/migrate3.sql" ] && { echo "   增量 → 消息通知"; run_sql_file migrate3.sql; }
[ "$(has_tbl captcha)" = "0" ] && [ -f "$LOCAL_DIR/migrate4.sql" ] && { echo "   增量 → 验证码/邀请码"; run_sql_file migrate4.sql; }
echo "   迁移检查完成"

# 4) Nginx 站点（端口 8080，独立站点，非子目录）
echo "🌐 配置 Nginx (8080)…"
NGINX_CONF="$(cat <<'EOF'
server {
    listen 8080;
    server_name _;
    root /www/wwwroot/biaobai;
    index index.html;
    location / { try_files $uri $uri/ /index.html; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/tmp/php-cgi-82.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location = /config.php { deny all; }
    location ~ ^/uploads/.*\.(php|phtml)$ { deny all; }
    client_max_body_size 20m;
}
EOF
)"
printf '%s' "$NGINX_CONF" | run_ssh "cat > /www/server/panel/vhost/nginx/biaobai.conf 2>/dev/null || cat > /etc/nginx/conf.d/biaobai.conf"
run_ssh "nginx -t 2>&1 | tail -3; (nginx -s reload 2>/dev/null || systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || true)"

# 5) 权限 & 重启 php-fpm
echo "🔧 权限 & 重启 PHP-FPM…"
run_ssh "chown -R www:www '$REMOTE_DIR' 2>/dev/null || chown -R nobody:nobody '$REMOTE_DIR' 2>/dev/null || true; (systemctl restart php-fpm 2>/dev/null || systemctl restart php8.2-fpm 2>/dev/null || service php-fpm restart 2>/dev/null || true)"

# 6) 冒烟测试
echo "🚀 冒烟测试 (list)…"
curl -s -m 10 "http://$SERVER_IP:8080/api.php?action=list" | head -c 300; echo

echo "✅ 部署脚本执行完毕。后台默认账号 admin/admin123 请尽快修改！"
