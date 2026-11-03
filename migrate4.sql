-- migrate4：验证码 + 邀请码（幂等，可重复执行）
USE biaobai;

-- 图形验证码（一次性，5 分钟有效）
CREATE TABLE IF NOT EXISTS captcha (
    id         VARCHAR(40)  NOT NULL,
    code       VARCHAR(10)  NOT NULL,
    expires_at DATETIME     NOT NULL,
    used       TINYINT      NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 邀请码
CREATE TABLE IF NOT EXISTS invite_codes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(32)  NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    max_uses   INT UNSIGNED NOT NULL DEFAULT 1,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    status     TINYINT      NOT NULL DEFAULT 1 COMMENT '1有效 0失效',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     DEFAULT NULL,
    UNIQUE KEY uq_code (code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- users 表增加 invite_code 字段（记录注册所用邀请码）
SET @db = 'biaobai';
SELECT COUNT(*) INTO @has_col FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'users' AND column_name = 'invite_code';
SET @sql = IF(@has_col = 0,
    'ALTER TABLE users ADD COLUMN invite_code VARCHAR(32) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
