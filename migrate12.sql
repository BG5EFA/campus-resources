-- 投稿分类 + 内容举报（可重复执行）
-- posts.category: love 寻人 / lost 寻物 / help 求助 / chat 吐槽 / other 其他
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'category') = 0,
  'ALTER TABLE posts ADD COLUMN category VARCHAR(16) NOT NULL DEFAULT ''other''',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_cat') = 0,
  'ALTER TABLE posts ADD INDEX idx_cat (board, category, status)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    reason VARCHAR(200) NOT NULL DEFAULT '',
    status TINYINT NOT NULL DEFAULT 0 COMMENT '0待处理 1已处理(内容下架) 2已忽略',
    admin_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME NULL,
    INDEX idx_report_status (status, created_at),
    INDEX idx_report_post (post_id, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
