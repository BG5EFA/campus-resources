-- migrate18.sql —— 后台用户内部备注（幂等，可重复执行）
-- admin_note 只允许 require_admin() 保护的后台接口读写；
-- 前台所有接口均不查询、不返回该字段，前台页面也不会渲染。
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_note') = 0,
  'ALTER TABLE users ADD COLUMN admin_note VARCHAR(500) NULL DEFAULT NULL COMMENT ''管理员内部备注，仅后台可见''',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
