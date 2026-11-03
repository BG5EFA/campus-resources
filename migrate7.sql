-- migrate7：内容表记录发布者 IP + 用户最近登录 IP（幂等）
-- posts / questions / forum_topics / comments / materials 加 ip；users 加 last_ip

DELIMITER $$

DROP PROCEDURE IF EXISTS add_col_ip $$
CREATE PROCEDURE add_col_ip(IN tbl VARCHAR(64), IN col VARCHAR(64), IN typ VARCHAR(64))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', typ, ' NULL');
    PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

DELIMITER ;

CALL add_col_ip('posts',         'ip', 'VARCHAR(45) NULL');
CALL add_col_ip('questions',     'ip', 'VARCHAR(45) NULL');
CALL add_col_ip('forum_topics',  'ip', 'VARCHAR(45) NULL');
CALL add_col_ip('comments',      'ip', 'VARCHAR(45) NULL');
CALL add_col_ip('materials',     'ip', 'VARCHAR(45) NULL');
CALL add_col_ip('users',         'last_ip', 'VARCHAR(45) NULL');

DROP PROCEDURE IF EXISTS add_col_ip;

-- 显示结果
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME IN ('posts','questions','forum_topics','comments','materials') AND COLUMN_NAME='ip')
    OR (TABLE_NAME='users' AND COLUMN_NAME='last_ip'))
ORDER BY TABLE_NAME;
