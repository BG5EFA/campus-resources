-- migrate20.sql —— 学习专区免登录链接「上传图标照片」（幂等，可重复执行）
-- icon_image 只存 uploads/linkicons/ 下的文件名，由后台上传接口生成；
-- 前台公开接口只额外回传 icon_url（静态图片路径），不含任何用户数据。
SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'study_links' AND COLUMN_NAME = 'icon_image') = 0,
  'ALTER TABLE study_links ADD COLUMN icon_image VARCHAR(80) NULL DEFAULT NULL COMMENT ''后台上传的图标图片文件名'' AFTER icon',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
