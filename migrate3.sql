-- 第三阶段扩展：消息通知（增量迁移，可重复执行）
-- 说明：posts.board / posts.title 及索引已由 schema.sql 建好；
--       树洞 / 论坛 / 问答板块已下线，相关表由 migrate15.sql 清理，本文件不再创建。

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(24) NOT NULL,
    ref_id INT NOT NULL DEFAULT 0,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
