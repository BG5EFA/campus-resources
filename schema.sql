-- 校园表白墙 数据库结构（全新安装用）
CREATE DATABASE IF NOT EXISTS biaobai DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE biaobai;

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nickname VARCHAR(50) NOT NULL DEFAULT '匿名',
    title VARCHAR(150) DEFAULT NULL COMMENT '论坛主题标题',
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    status TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1已通过 2已拒绝',
    featured TINYINT NOT NULL DEFAULT 0 COMMENT '0普通 1优质置顶',
    featured_at DATETIME NULL,
    category VARCHAR(16) NOT NULL DEFAULT 'other' COMMENT 'love表白 lost寻物 help求助 chat吐槽 other其他',
    board TINYINT NOT NULL DEFAULT 0 COMMENT '0校园墙 1树洞 2论坛',
    likes INT NOT NULL DEFAULT 0,
    user_id INT NULL COMMENT '发布者账号（发布必须登录；前台接口不返回）',
    is_anon TINYINT NOT NULL DEFAULT 0 COMMENT '0显示昵称 1前台匿名，后台仍可溯源',
    ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_time (status, created_at),
    INDEX idx_featured (featured, status),
    INDEX idx_cat (board, category, status),
    INDEX idx_board (board),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NULL,
    nickname VARCHAR(50) NOT NULL DEFAULT '匿名',
    content TEXT NOT NULL,
    status TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1已通过 2已拒绝',
    is_anon TINYINT NOT NULL DEFAULT 0 COMMENT '0显示昵称 1前台匿名，后台仍可溯源',
    ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post (post_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    reason VARCHAR(200) NOT NULL DEFAULT '',
    status TINYINT NOT NULL DEFAULT 0 COMMENT '0待处理 1已处理(内容下架) 2已忽略',
    admin_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME NULL,
    INDEX idx_report_status (status, created_at),
    INDEX idx_report_post (post_id, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_likes (
    post_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, ip),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nickname VARCHAR(50) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_tokens (
    token VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_tokens (
    token VARCHAR(64) NOT NULL PRIMARY KEY,
    admin_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 学习专区 / 资料分享
CREATE TABLE IF NOT EXISTS material_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO material_categories (id, name, sort) VALUES
 (1, '笔记课件', 1), (2, '试卷真题', 2), (3, '学习经验', 3), (4, '考研升学', 4), (5, '其他', 9);

CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    category_id INT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    orig_name VARCHAR(255) DEFAULT NULL,
    file_size INT NOT NULL DEFAULT 0,
    file_type VARCHAR(20) DEFAULT NULL,
    downloads INT NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1已通过 2已拒绝',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_time (status, created_at),
    INDEX idx_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS material_likes (
    material_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (material_id, ip),
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
