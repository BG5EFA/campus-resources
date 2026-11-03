# 温州市滨海高级中学 · 表白墙

校园表白墙 / 学习专区综合平台。纯 PHP + MySQL + Nginx 单体应用，无框架依赖。

## 功能模块

- **表白墙 / 树洞**：匿名表白、树洞倾诉，支持图片、点赞（IP 去重）、评论
- **学习专区**：学习资料上传 / 下载、分类浏览
- **问答**：提问 / 回答
- **论坛**：分类话题、发帖 / 回复
- **个人中心**：我的发布、消息通知
- **后台管理**（`/admin`，前台隐藏入口）：
  - 内容审核（通过 / 拒绝 / 删除、编辑文本、查看时间与 IP）
  - 用户管理（创建 / 编辑 / 封禁 / 删除、查看并管理其上传内容）
  - 邀请码管理、资料文件信息编辑
- **安全**：登录 / 注册图形验证码（GD）、邀请码注册、封禁强退、参数绑定防注入

## 技术栈

| 层 | 技术 |
|---|---|
| 后端 | PHP 8.2（PDO / MySQL），单文件 `api.php` 路由 |
| 前端 | 原生 HTML / CSS / JS，多页面 |
| 数据库 | MySQL（`schema.sql` + `migrate*.sql` 增量迁移） |
| 服务器 | Nginx + php-fpm，Let's Encrypt SSL |

## 目录结构

```
├── api.php              # 后端接口（全部 action 路由）
├── index.html           # 表白墙首页
├── study.html           # 学习专区
├── forum.html           # 论坛
├── qa.html              # 问答
├── treehole.html        # 树洞
├── me.html              # 个人中心
├── admin.html           # 后台管理（经 /admin 访问，前台隐藏）
├── badge.png            # 校徽
├── matte.php            # 校徽抠图小工具（一次性运维脚本）
├── config.example.php   # 数据库配置模板（复制为 config.php 并填真实值）
├── schema.sql           # 建表
├── migrate*.sql         # 增量迁移（幂等）
├── deploy.sh            # 一键部署脚本
└── nginx/               # Nginx 站点配置参考
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

## 安全说明

- `config.php` 含真实数据库密码，**已在 `.gitignore` 排除**，请勿提交
- `deploy.sh` 的 root 密码通过环境变量 `SSH_PASS` 传入，无硬编码
- 上传目录 `uploads/` 已排除，且禁止执行其中的 PHP
