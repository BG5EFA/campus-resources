-- migrate17.sql —— 楼中楼回复 + 评论点赞 + 列表排序（幂等，可重复执行）
-- comments.parent_id：0 表示顶层评论；回复统一挂到所属顶层评论下（只做一层楼中楼）
-- comments.reply_to：被回复者当时的前台显示名，避免对方后续改匿名导致历史楼层失真
-- comment_likes：按 IP 去重的评论点赞，删除评论 / 帖子时由外键级联清理
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE comments ADD COLUMN parent_id INT NOT NULL DEFAULT 0 AFTER post_id',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'reply_to') = 0,
  'ALTER TABLE comments ADD COLUMN reply_to VARCHAR(50) NULL AFTER parent_id',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comments' AND INDEX_NAME = 'idx_parent') = 0,
  'ALTER TABLE comments ADD INDEX idx_parent (post_id, parent_id, status)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS comment_likes (
    comment_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id, ip),
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
