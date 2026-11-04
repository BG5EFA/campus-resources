-- 评论审核：为 comments 表增加 status 字段
-- 0=待审核 1=已通过 2=已拒绝
ALTER TABLE comments ADD COLUMN status TINYINT NOT NULL DEFAULT 0;
-- 历史评论默认设为已通过，避免存量数据突然全部消失
UPDATE comments SET status = 1;
