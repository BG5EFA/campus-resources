-- 优质内容 / 管理员一键置顶（可重复执行）
-- featured: 0 普通 / 1 优质置顶；featured_at: 标记时间，用于置顶顺序（最近标记在前）
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'featured') = 0,
  'ALTER TABLE posts ADD COLUMN featured TINYINT NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'featured_at') = 0,
  'ALTER TABLE posts ADD COLUMN featured_at DATETIME NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_featured') = 0,
  'ALTER TABLE posts ADD INDEX idx_featured (featured, status)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
