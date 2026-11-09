-- migrate19.sql —— 学习专区「免登录公开链接」（幂等，可重复执行）
-- 由管理员在后台录入的网页链接（如网课 / 题库 / 官方学习平台），
-- 前台 study.html 顶部常驻展示，访客无需登录即可查看并点击跳转。
-- 公开接口只返回 id/title/url/description/icon/sort，不含任何用户数据。
CREATE TABLE IF NOT EXISTS study_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL COMMENT '链接名称',
    url VARCHAR(1000) NOT NULL COMMENT '目标网址，仅允许 http/https',
    description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '一句话说明',
    icon VARCHAR(16) NOT NULL DEFAULT '🔗' COMMENT '卡片图标（emoji）',
    sort INT NOT NULL DEFAULT 0 COMMENT '排序值，越小越靠前',
    status TINYINT NOT NULL DEFAULT 1 COMMENT '1=显示 0=停用',
    clicks INT NOT NULL DEFAULT 0 COMMENT '累计点击次数',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_sort (status, sort, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
