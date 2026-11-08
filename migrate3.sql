-- 第三阶段扩展：个人中心/消息、树洞、问答、论坛（增量迁移，可重复执行）

-- 帖子上扩展：board(0校园墙 1树洞 2论坛) 与 title(论坛用)
ALTER TABLE posts ADD COLUMN board TINYINT NOT NULL DEFAULT 0 COMMENT '0校园墙 1树洞 2论坛';
ALTER TABLE posts ADD COLUMN title VARCHAR(150) NULL COMMENT '论坛主题标题';
ALTER TABLE posts ADD INDEX idx_board (board);

-- 消息通知
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(24) NOT NULL,
    ref_id INT NOT NULL DEFAULT 0,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 问答
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    title VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    views INT NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,
    answer_count INT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 2已删除',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    user_id INT NULL,
    content TEXT NOT NULL,
    likes INT NOT NULL DEFAULT 0,
    accepted TINYINT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_q (question_id),
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 论坛
CREATE TABLE IF NOT EXISTS forum_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO forum_categories (id, name, sort) VALUES
 (1, '校园生活', 1), (2, '学习交流', 2), (3, '情感树洞', 3), (4, '失物招领', 4), (5, '其他', 9);

CREATE TABLE IF NOT EXISTS forum_topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    category_id INT NULL,
    title VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    likes INT NOT NULL DEFAULT 0,
    reply_count INT NOT NULL DEFAULT 0,
    views INT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 2已删除',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_time (created_at),
    INDEX idx_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forum_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    user_id INT NULL,
    content TEXT NOT NULL,
    likes INT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_t (topic_id),
    FOREIGN KEY (topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 通用点赞（问答/论坛）
CREATE TABLE IF NOT EXISTS likes (
    entity_type VARCHAR(24) NOT NULL,
    entity_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entity_type, entity_id, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
