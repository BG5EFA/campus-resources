-- migrate15.sql —— 彻底下线「树洞 / 论坛 / 问答」板块（幂等，可重复执行）
-- 站点保留：校园墙 / 学习专区 / 个人中心 / 关于·更新
-- ⚠️ 执行前请先完整备份：/root/backup_campus.sh（或 scripts/backup.sh）

-- 1) 清理 posts.board=1(树洞) / 2(论坛) 的全部关联数据后删帖
--    posts.board 列保留为历史字段，值固定 0（校园墙），后端不再按板块分流
DELETE FROM comments   WHERE post_id IN (SELECT id FROM posts WHERE board IN (1, 2));
DELETE FROM post_likes WHERE post_id IN (SELECT id FROM posts WHERE board IN (1, 2));
DELETE FROM reports    WHERE post_id IN (SELECT id FROM posts WHERE board IN (1, 2));
DELETE FROM favorites  WHERE post_id IN (SELECT id FROM posts WHERE board IN (1, 2));
DELETE FROM posts      WHERE board IN (1, 2);

-- 2) 删除三个板块的专属表（先子表后主表，避免外键约束）
DROP TABLE IF EXISTS forum_replies;
DROP TABLE IF EXISTS forum_topics;
DROP TABLE IF EXISTS forum_categories;
DROP TABLE IF EXISTS answers;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS likes;

-- 3) 清理指向已下线板块的历史通知（如有）
DELETE FROM notifications WHERE type IN ('answer', 'reply', 'question', 'topic', 'qanswer', 'forum_reply');
