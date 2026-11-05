-- 不良用语词库：发布内容时自动将命中词替换为等长 *
CREATE TABLE IF NOT EXISTS bad_words (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  word VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_word (word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始词库（管理员可在后台「敏感词管理」中自行增删）
INSERT IGNORE INTO bad_words (word) VALUES
('傻逼'), ('傻B'), ('煞笔'), ('沙比'), ('狗逼'), ('贱逼'),
('尼玛'), ('泥马'), ('妈的'), ('他妈的'), ('草泥马'), ('卧槽'),
('操你'), ('日你'), ('去死'), ('死全家'), ('滚蛋'),
('贱人'), ('婊子'), ('畜生'), ('狗东西'), ('王八蛋'), ('混蛋'),
('白痴'), ('智障'), ('脑残'), ('蠢货'), ('废物'),
('约炮'), ('包夜'), ('一夜情'), ('援交'),
('cnm'), ('nmsl'), ('sb'), ('fuck'), ('shit'), ('bitch');
