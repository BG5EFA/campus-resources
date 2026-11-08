-- 发布需登录 + 可选匿名 + 管理员可溯源（可重复执行）
-- is_anon: 0 显示昵称（前台可见作者昵称） 1 前台只显示「匿名」，后台仍能看到真实账号
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'is_anon') = 0,
  'ALTER TABLE posts ADD COLUMN is_anon TINYINT NOT NULL DEFAULT 0 COMMENT ''0实名/昵称 1前台匿名''',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'is_anon') = 0,
  'ALTER TABLE comments ADD COLUMN is_anon TINYINT NOT NULL DEFAULT 0 COMMENT ''0实名/昵称 1前台匿名''',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 历史数据回填：已有账号且显示为匿名的内容（树洞一律匿名）
UPDATE posts SET is_anon = 1
 WHERE is_anon = 0 AND user_id IS NOT NULL AND (nickname = '匿名' OR board = 1);
UPDATE comments SET is_anon = 1
 WHERE is_anon = 0 AND user_id IS NOT NULL AND nickname = '匿名';
