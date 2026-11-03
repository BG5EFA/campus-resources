USE biaobai;

-- comments.user_id  评论归属用户（可空，匿名时为 NULL）
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='biaobai' AND TABLE_NAME='comments' AND COLUMN_NAME='user_id');
SET @s = IF(@c=0, "ALTER TABLE comments ADD COLUMN user_id INT NULL AFTER post_id, ADD INDEX (user_id)", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migrate6 done' AS result;
