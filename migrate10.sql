-- 更新修复日志（仅管理员可发布）
CREATE TABLE IF NOT EXISTS changelog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'fix',
    version VARCHAR(50) DEFAULT NULL,
    admin_id INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 仅在空表时写入初始记录（可重复执行）
SET @cl_count = (SELECT COUNT(*) FROM changelog);
INSERT INTO changelog (title, content, kind, version, admin_id, created_at)
SELECT t.title, t.content, t.kind, NULL, 0, t.dt FROM (
    SELECT '修复图片上传无法提交' AS title,
           '修复了发布内容带图片时提交失败的问题：服务器未安装 exif 扩展时，图片会被误判为格式错误。现已改用通用图片头校验，支持 jpg / png / gif / webp，单张上限提升到 10MB。' AS content,
           'fix' AS kind, '2026-11-06 20:05:00' AS dt
    UNION ALL SELECT '新增「更新修复日志」与「关于我们」模块',
           '首页新增两个并排模块：关于我们（联系方式与赞助）与更新修复日志。日志仅由管理员在后台发布，用来告诉大家每次修复了什么问题、新增了哪些功能。',
           'new', '2026-11-06 20:10:00'
    UNION ALL SELECT '发布内容自动屏蔽不良用语',
           '发帖、评论、问答、论坛、资料、昵称中的不良用语会自动替换为等长 *；词库可在后台增删，词间插入空格或符号的变体同样会被拦截。',
           'new', '2026-11-05 19:36:00'
    UNION ALL SELECT '上传文件体积放宽',
           '学习专区资料上传上限提升到 30MB，服务器请求体限制同步放宽，大文件上传不再被中途截断；超大文件会给出明确提示。',
           'improve', '2026-11-06 20:03:00'
    UNION ALL SELECT '学习专区支持图片与后台直接管理',
           '学习专区可上传图片并直接预览；后台新增「学习专区管理」面板，可直接上架资料或通过 / 拒绝审核，解决资料卡在审核无法操作的问题。',
           'fix', '2026-11-04 22:45:00'
    UNION ALL SELECT '评论审核与学习专区登录限制',
           '评论发布后需管理员审核通过才公开展示；学习专区需登录后才能查看与下载资料，保护校内资源。',
           'new', '2026-11-04 22:08:00'
    UNION ALL SELECT '站点统一命名为「校园墙」',
           '全站名称由「表白墙」统一改为「校园墙」，表白只是其中一个板块，另有学习专区、树洞、问答、论坛与个人中心。',
           'improve', '2026-11-04 23:17:00'
) t
WHERE @cl_count = 0;
