USE biaobai;

-- users.status  1=正常 0=封禁
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='biaobai' AND TABLE_NAME='users' AND COLUMN_NAME='status');
SET @s = IF(@c=0, "ALTER TABLE users ADD COLUMN status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0封禁'", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- users.banned_at
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='biaobai' AND TABLE_NAME='users' AND COLUMN_NAME='banned_at');
SET @s = IF(@c=0, "ALTER TABLE users ADD COLUMN banned_at DATETIME DEFAULT NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- users.ban_reason
SET @c = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='biaobai' AND TABLE_NAME='users' AND COLUMN_NAME='ban_reason');
SET @s = IF(@c=0, "ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) DEFAULT NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migrate5 done' AS result;
