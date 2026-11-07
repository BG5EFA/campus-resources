# 校园资源站

温州市滨海高级中学 · 校园资源站 —— 校园墙 / 学习专区 / 树洞 / 问答 / 论坛 综合平台。纯 PHP + MySQL + Nginx 单体应用，无框架依赖。

## 功能模块

- **校园墙**（首页）：发帖支持图片、点赞（IP 去重）、评论（审核后公开）、不良用语自动替换为等长 `*`；投稿可选分类（表白 / 寻物 / 求助 / 吐槽 / 其他），首页提供分类筛选与关键词搜索（内容 / 标题 / 昵称，可叠加）
- **内容管理闭环**：登录用户可自助删除自己发布的内容；任何访客可对内容提交举报（按 IP 限频），管理员在后台一键下架或忽略，举报同时汇总进待处理中心
- **优质内容专区**：管理员在后台一键「设优质」，内容自动通过审核并置顶，专区优先展示；取消置顶可随时撤销
- **树洞 / 问答 / 论坛**：匿名倾诉、提问回答、分类话题与回复
- **学习专区**：需登录后查看；支持资料 / 图片 / 其他文件上传与下载、分类浏览，资料由后台直接管理
- **个人中心**：我的发布、消息通知
- **关于我们 / 更新修复日志**：独立模块，日志仅由管理员在后台发布，也可在后台编辑、删除
- **内容规范公告**：首次访问弹窗提示，确认后不再重复打扰
- **后台管理**（`/admin`，前台隐藏入口）：11 个模块标签 + 「待处理中心」
  - 内容审核（通过 / 拒绝 / 删除、编辑文本，标题可留空，显示具体时间与 IP）
  - 举报处理（查看理由、下架违规内容 / 忽略，已处理记录留痕）
  - 运营概览（今日发帖 / 评论 / 新用户、近 7 日趋势、待审积压、举报数、热门内容 Top5）
  - 用户管理（创建 / 编辑 / 封禁 / 删除、查看并管理其上传内容）
  - 邀请码管理、敏感词词库增删、学习资料文件信息编辑、更新日志管理、优质内容置顶
- **上传与图片**：单张配图 10MB、学习资料 30MB；服务端自动高质量压缩（JPEG / WebP / PNG）并校正手机竖拍 EXIF 方向；前台统一小缩略图，点开看原图，加载失败自动重试
- **安全**：登录 / 注册图形验证码（GD）、邀请码注册、封禁强退、参数绑定防注入、后台接口鉴权、前后台登录凭据独立存储

## 技术栈

| 层 | 技术 |
|---|---|
| 后端 | PHP 8.2（PDO / MySQL），单文件 `api.php` 路由 |
| 前端 | 原生 HTML / CSS / JS，多页面 |
| 数据库 | MySQL（`schema.sql` + `migrate*.sql` 增量迁移，幂等） |
| 服务器 | Nginx + php-fpm，Let's Encrypt SSL（ECDSA + RSA 双证书并行，兼容老设备） |

## 目录结构

```
├── api.php              # 后端接口（全部 action 路由）
├── index.html           # 校园墙首页（优质内容专区 / 分类筛选 / 搜索 / 关于 / 更新日志入口）
├── study.html           # 学习专区
├── forum.html           # 论坛
├── qa.html              # 问答
├── treehole.html        # 树洞
├── info.html            # 关于我们 / 更新修复日志独立页
├── me.html              # 个人中心
├── admin.html           # 后台管理（经 /admin 访问，前台隐藏）
├── notice.js            # 全站首访内容规范公告
├── badge.png            # 校徽
├── matte.php            # 校徽抠图小工具（一次性运维脚本）
├── config.example.php   # 数据库配置模板（复制为 config.php 并填真实值）
├── schema.sql           # 建表
├── migrate*.sql         # 增量迁移（幂等，含 12：投稿分类 + 举报表）
├── deploy.sh            # 一键部署脚本
├── CHANGELOG.md         # 更新修复日志（与站点后台发布内容同步）
├── scripts/
│   ├── backup.sh        # 每日备份：数据库 + uploads（部署为 /root/backup_campus.sh）
│   └── restore.sh       # 一键恢复（部署为 /root/restore_campus.sh）
└── nginx/               # Nginx 站点配置参考（bingao.conf 含双证书与安全头）
```

## 快速开始

1. **准备数据库**
   ```bash
   mysql -u root -p biaobai < schema.sql
   for f in migrate*.sql; do mysql -u root -p biaobai < "$f"; done
   ```

2. **配置**
   ```bash
   cp config.example.php config.php
   # 编辑 config.php 填入数据库账号密码
   ```

3. **部署**（部署脚本通过环境变量提供 SSH 密码，不入库）
   ```bash
   SSH_PASS='你的root密码' SSH_PORT=10086 ./deploy.sh <服务器IP>
   ```

4. **访问**：前台 `https://<域名>/`，后台 `https://<域名>/admin`

更新修复日志见 [CHANGELOG.md](CHANGELOG.md)，站点内可在「关于 / 更新」模块查看。

## 运维与可靠性

- **备份**：`scripts/backup.sh` 每天 03:30 由 cron 执行，产物在 `/www/backup/campus/`，日志 `/var/log/campus_backup.log`，保留 14 天；每次备份都会做 `gzip -t` 完整性校验
- **恢复**：`scripts/restore.sh <db_YYYYMMDD_HHMMSS.sql.gz> [--yes]`，默认需交互输入 `yes`，并先自动保存一份恢复前快照
- **证书**：ECDSA + RSA 双证书由 Nginx 按客户端能力自动选择；RSA 证书用 `certbot --cert-name bingao-rsa` 独立签发，certbot.timer 自动续期
- **安全头**：HSTS（先设 1 天观察）、`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`；后台额外 `no-store`

## 安全说明

- `config.php` 含真实数据库密码，**已在 `.gitignore` 排除**，请勿提交
- `deploy.sh` 的 root 密码通过环境变量 `SSH_PASS` 传入，无硬编码
- 备份脚本从 `/root/.mysql_root` 读取数据库密码，脚本内不含明文；**备份文件含用户数据，仅存服务器本地，请勿外传或提交到公开仓库**
- 上传目录 `uploads/` 已排除，且禁止执行其中的 PHP
- 后台登录凭据使用独立存储键 `bb_admin_token`，前台用户凭据为 `bb_token`，互不干扰
