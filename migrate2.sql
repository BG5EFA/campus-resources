-- 学习专区 / 资料分享 模块（增量迁移，可重复执行）

CREATE TABLE IF NOT EXISTS material_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO material_categories (id, name, sort) VALUES
 (1, '笔记课件', 1),
 (2, '试卷真题', 2),
 (3, '学习经验', 3),
 (4, '考研升学', 4),
 (5, '其他', 9);

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
