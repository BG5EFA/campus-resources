-- migrate16.sql —— 图片评论 + 热门排行（幂等，可重复执行）
-- comments.image 存 /uploads/ 下的文件名（32位十六进制 + 扩展名），NULL 表示纯文字评论
-- 热门排行按 posts.likes 排序，用索引减少全表扫描
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'image') = 0,
  'ALTER TABLE comments ADD COLUMN image VARCHAR(64) NULL AFTER content',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_hot') = 0,
  'ALTER TABLE posts ADD INDEX idx_hot (status, board, likes)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
