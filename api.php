<?php
/**
 * 校园墙 + 学习专区 + 树洞 + 问答 + 论坛 + 个人中心
 * 单文件路由，action 驱动。部署：/www/wwwroot/biaobai/api.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

// ---------- 数据库 ----------
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => '数据库错误']);
    exit;
}

// ---------- 工具 ----------
function json_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function get_client_ip()
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function get_bearer_token()
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/', $h, $m)) return $m[1];
    return trim($_POST['token'] ?? ($_GET['token'] ?? '')) ?: null;
}
function require_admin($pdo)
{
    $token = get_bearer_token();
    if (!$token) return null;
    $stmt = $pdo->prepare('SELECT t.admin_id, t.expires_at, a.username FROM admin_tokens t JOIN admin a ON a.id = t.admin_id WHERE t.token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || strtotime($row['expires_at']) < time()) return null;
    return $row;
}
function require_user($pdo)
{
    $token = get_bearer_token();
    if (!$token) return null;
    $stmt = $pdo->prepare('SELECT t.user_id, t.expires_at, u.id, u.username, u.nickname FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || strtotime($row['expires_at']) < time()) return null;
    return $row;
}
function add_notification($pdo, $user_id, $type, $ref_id, $message)
{
    if (!$user_id) return;
    $pdo->prepare('INSERT INTO notifications (user_id,type,ref_id,message) VALUES (?,?,?,?)')
        ->execute([$user_id, $type, $ref_id, filter_bad_words($pdo, $message)]);
}

// 不良用语过滤：命中词替换为等长 *，兼容词间插入空格/标点的变体
function filter_bad_words($pdo, $text)
{
    if ($text === null || $text === '') return $text;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT word FROM bad_words')->fetchAll(PDO::FETCH_COLUMN) as $w) {
                $w = trim((string) $w);
                if ($w !== '' && mb_strlen($w, 'UTF-8') >= 2) $cache[] = $w;
            }
        } catch (Throwable $e) {
            $cache = []; // 词库表未建立时降级，避免影响正常发布
        }
    }
    if (!$cache) return $text;
    foreach ($cache as $w) {
        $chars = preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars || count($chars) < 2) continue;
        $parts = array_map(static function ($c) { return preg_quote($c, '/'); }, $chars);
        $isAscii = (bool) preg_match('/^[\x00-\x7F]+$/', $w);
        $boundary = $isAscii ? '\b' : '';
        $pattern = '/' . $boundary . implode('[\s\p{P}\p{S}_]{0,2}', $parts) . $boundary . '/iu';
        $out = preg_replace_callback($pattern, static function ($m) {
            return str_repeat('*', mb_strlen($m[0], 'UTF-8'));
        }, $text);
        if ($out !== null) $text = $out;
    }
    return $text;
}

// 校验图形验证码（一次性）
function verify_captcha($pdo, $cid, $code)
{
    if (!$cid || !$code) return false;
    $cid = trim($cid);
    $code = strtolower(trim($code));
    $pdo->prepare('DELETE FROM captcha WHERE expires_at < NOW()')->execute();
    $stmt = $pdo->prepare('SELECT code FROM captcha WHERE id = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$cid]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if (strtolower($row['code']) !== $code) return false;
    $pdo->prepare('DELETE FROM captcha WHERE id = ?')->execute([$cid]);
    return true;
}

// 校验并消费邀请码
function consume_invite($pdo, $code)
{
    if (!$code) return [false, '请填写邀请码'];
    $code = trim($code);
    $stmt = $pdo->prepare('SELECT * FROM invite_codes WHERE code = ? AND status = 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) return [false, '邀请码无效'];
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) return [false, '邀请码已过期'];
    if ($row['used_count'] >= $row['max_uses']) return [false, '邀请码已被用完'];
    return [true, $row];
}
function handle_upload($file)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > 2 * 1024 * 1024) return false;
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!function_exists('exif_imagetype') || ($type = exif_imagetype($file['tmp_name'])) === false) return false;
    if (!isset($allowed[$type])) return false;
    $ext = $allowed[$type];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = UPLOAD_DIR . $name;
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;
    return $name;
}
function handle_material_upload($file)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > 20 * 1024 * 1024) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allow = ['doc', 'docx', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];
    if (!in_array($ext, $allow, true)) return false;
    $dir = UPLOAD_DIR . 'materials/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) return false;
    return ['stored' => $stored, 'orig' => $file['name'], 'size' => $file['size'], 'ext' => $ext];
}

// ---------- 路由 ----------
$action = $_REQUEST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ===== 帖子：列表（board: 0校园墙 1树洞 2论坛）=====
    case 'list':
        $page = max(1, intval($_GET['page'] ?? 1));
        $per = 10;
        $offset = ($page - 1) * $per;
        $board = intval($_GET['board'] ?? 0);
        $where = 'WHERE p.status = 1 AND p.board = ' . intval($board);
        $stmt = $pdo->query(
            'SELECT p.*, (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
             FROM posts p ' . $where . ' ORDER BY p.created_at DESC
             LIMIT ' . intval($per) . ' OFFSET ' . intval($offset)
        );
        $posts = $stmt->fetchAll();
        $total = $pdo->query('SELECT COUNT(*) FROM posts p ' . $where)->fetchColumn();
        json_out(['ok' => true, 'posts' => $posts, 'page' => $page, 'per' => $per, 'total' => (int) $total]);
        break;

    // ===== 帖子：发布（进入待审核）=====
    case 'create':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $board = intval($_POST['board'] ?? 0);
        if (!in_array($board, [0, 1, 2], true)) $board = 0;
        $title = null;
        if ($board === 2) {
            $title = trim($_POST['title'] ?? '');
            if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '论坛标题需 2-150 字'], 400);
        }
        $content = trim($_POST['content'] ?? '');
        $max = $board === 2 ? 2000 : 500;
        if (mb_strlen($content) < 1 || mb_strlen($content) > $max) json_out(['ok' => false, 'msg' => '内容需在 1-' . $max . ' 字之间'], 400);
        $nicknameRaw = trim($_POST['nickname'] ?? '');
        $anon = isset($_POST['anonymous']) && $_POST['anonymous'] === '1';
        if ($board === 1) $anon = true; // 树洞强制匿名
        $user = require_user($pdo);
        if ($user && !$anon) {
            $nickname = $nicknameRaw !== '' ? mb_substr($nicknameRaw, 0, 50) : $user['nickname'];
            $uid = $user['id'];
        } else {
            $nickname = '匿名';
            $uid = $user ? $user['id'] : null;
        }
        $image = null;
        if (!empty($_FILES['image'])) {
            $image = handle_upload($_FILES['image']);
            if ($image === false) json_out(['ok' => false, 'msg' => '图片需为 jpg/png/gif/webp 且 ≤2MB'], 400);
        }
        $nickname = filter_bad_words($pdo, $nickname);
        $title = $title === null ? null : filter_bad_words($pdo, $title);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO posts (nickname,title,content,image,status,user_id,board,ip) VALUES (?,?,?,?,0,?,?,?)')
            ->execute([$nickname, $title, $content, $image, $uid, $board, get_client_ip()]);
        json_out(['ok' => true, 'msg' => '提交成功，等待审核通过后展示']);
        break;

    // ===== 帖子：点赞（IP 去重）=====
    case 'like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['post_id'] ?? 0);
        $ip = get_client_ip();
        $p = $pdo->prepare('SELECT id FROM posts WHERE id = ? AND status = 1');
        $p->execute([$id]);
        if (!$p->fetch()) json_out(['ok' => false, 'msg' => '帖子不存在或未通过审核'], 400);
        $chk = $pdo->prepare('SELECT 1 FROM post_likes WHERE post_id = ? AND ip = ?');
        $chk->execute([$id, $ip]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '你已经点过赞啦']);
        $pdo->prepare('INSERT INTO post_likes (post_id, ip) VALUES (?, ?)')->execute([$id, $ip]);
        $pdo->prepare('UPDATE posts SET likes = likes + 1 WHERE id = ?')->execute([$id]);
        $likes = $pdo->prepare('SELECT likes FROM posts WHERE id = ?');
        $likes->execute([$id]);
        json_out(['ok' => true, 'likes' => (int) $likes->fetchColumn()]);
        break;

    // ===== 评论：列表（仅展示已通过审核）=====
    case 'comments':
        $id = intval($_GET['post_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM comments WHERE post_id = ? AND status = 1 ORDER BY created_at ASC');
        $stmt->execute([$id]);
        json_out(['ok' => true, 'comments' => $stmt->fetchAll()]);
        break;

    // ===== 评论：发表（进入待审核，审核通过后才公开展示）=====
    case 'comment_add':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($content) < 1 || mb_strlen($content) > 200) json_out(['ok' => false, 'msg' => '评论需在 1-200 字之间'], 400);
        $nicknameRaw = trim($_POST['nickname'] ?? '');
        $anon = isset($_POST['anonymous']) && $_POST['anonymous'] === '1';
        $post = $pdo->prepare('SELECT id, user_id, board FROM posts WHERE id = ? AND status = 1');
        $post->execute([$id]);
        $prow = $post->fetch();
        if (!$prow) json_out(['ok' => false, 'msg' => '帖子不存在或未通过审核'], 400);
        if ($prow['board'] == 1) $anon = true; // 树洞评论强制匿名
        $user = require_user($pdo);
        if ($user && !$anon) {
            $nickname = $nicknameRaw !== '' ? mb_substr($nicknameRaw, 0, 50) : $user['nickname'];
            $cuid = $user['id'];
        } else {
            $nickname = '匿名';
            $cuid = $user ? $user['id'] : null;
        }
        $nickname = filter_bad_words($pdo, $nickname);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO comments (post_id, nickname, content, user_id, ip, status) VALUES (?, ?, ?, ?, ?, 0)')
            ->execute([$id, $nickname, $content, $cuid, get_client_ip()]);
        // 评论需审核，审核通过后再通知作者（见 admin_comment_approve）
        json_out(['ok' => true, 'msg' => '评论已提交，审核通过后将公开展示']);
        break;

    // ===== 评论：后台审核列表 =====
    case 'admin_comment_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $status = isset($_GET['status']) ? intval($_GET['status']) : 0;
        $stmt = $pdo->prepare('SELECT c.*, p.title AS post_title, p.board FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.status = ? ORDER BY c.created_at DESC');
        $stmt->execute([$status]);
        json_out(['ok' => true, 'comments' => $stmt->fetchAll()]);
        break;

    // ===== 评论：后台通过 / 拒绝 =====
    case 'admin_comment_approve':
    case 'admin_comment_reject':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        $st = $action === 'admin_comment_approve' ? 1 : 2;
        $cm = $pdo->prepare('SELECT c.*, p.user_id AS post_uid, p.board FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.id = ?');
        $cm->execute([$id]);
        $row = $cm->fetch();
        if (!$row) json_out(['ok' => false, 'msg' => '评论不存在'], 404);
        $pdo->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute([$st, $id]);
        if ($st === 1 && $row['post_uid']) {
            add_notification($pdo, $row['post_uid'], 'comment', $row['post_id'], '你的' . ($row['board'] == 2 ? '帖子' : '表白') . '有一条新评论通过审核：' . mb_substr($row['content'], 0, 30));
        }
        json_out(['ok' => true, 'msg' => $st === 1 ? '已通过' : '已拒绝']);
        break;

    // ===== 图形验证码（登录/注册防机器人）=====
    case 'captcha':
        $cid = bin2hex(random_bytes(16));
        $code = (string) random_int(1000, 9999);
        $w = 110; $h = 40;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 245, 249, 255);
        $fg = imagecolorallocate($img, 20, 35, 63);
        $noise = imagecolorallocate($img, 47, 123, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        for ($i = 0; $i < 6; $i++) {
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $noise);
        }
        for ($i = 0; $i < 4; $i++) {
            imagestring($img, 5, 12 + $i * 24, random_int(6, 16), $code[$i], $fg);
        }
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);
        $exp = date('Y-m-d H:i:s', time() + 300);
        $pdo->prepare('INSERT INTO captcha (id, code, expires_at) VALUES (?, ?, ?)')->execute([$cid, $code, $exp]);
        json_out(['ok' => true, 'cid' => $cid, 'image' => 'data:image/png;base64,' . base64_encode($png)]);
        break;

    // ===== 用户：注册 =====
    case 'register':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        if (!verify_captcha($pdo, $_POST['cid'] ?? '', $_POST['captcha'] ?? '')) {
            json_out(['ok' => false, 'msg' => '验证码错误或已过期'], 400);
        }
        list($invOk, $inv) = consume_invite($pdo, $_POST['invite'] ?? '');
        if (!$invOk) json_out(['ok' => false, 'msg' => $inv], 400);
        $inviteCode = trim($_POST['invite'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $username)) json_out(['ok' => false, 'msg' => '用户名需 3-20 位（中英文/数字/下划线）'], 400);
        if (mb_strlen($password) < 6) json_out(['ok' => false, 'msg' => '密码至少 6 位'], 400);
        if ($nickname === '') $nickname = $username;
        $nickname = filter_bad_words($pdo, mb_substr($nickname, 0,50));
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $chk->execute([$username]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '该用户名已被注册'], 400);
        $pdo->prepare('INSERT INTO users (username, password, nickname, invite_code, last_ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $nickname, $inviteCode, get_client_ip()]);
        $uid = $pdo->lastInsertId();
        $pdo->prepare('UPDATE invite_codes SET used_count = used_count + 1 WHERE code = ?')->execute([$inviteCode]);
        $token = bin2hex(random_bytes(32));
        $exp = date('Y-m-d H:i:s', time() + 86400 * 30);
        $pdo->prepare('INSERT INTO user_tokens (token, user_id, expires_at) VALUES (?, ?, ?)')->execute([$token, $uid, $exp]);
        json_out(['ok' => true, 'msg' => '注册成功', 'token' => $token, 'username' => $username, 'nickname' => $nickname]);
        break;

    // ===== 用户：登录 =====
    case 'login':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        if (!verify_captcha($pdo, $_POST['cid'] ?? '', $_POST['captcha'] ?? '')) {
            json_out(['ok' => false, 'msg' => '验证码错误或已过期'], 400);
        }
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($password, $u['password'])) json_out(['ok' => false, 'msg' => '账号或密码错误'], 401);
        if (isset($u['status']) && $u['status'] == 0) json_out(['ok' => false, 'msg' => '该账号已被封禁，请联系管理员'], 403);
        $token = bin2hex(random_bytes(32));
        $exp = date('Y-m-d H:i:s', time() + 86400 * 30);
        $pdo->prepare('INSERT INTO user_tokens (token, user_id, expires_at) VALUES (?, ?, ?)')->execute([$token, $u['id'], $exp]);
        $pdo->prepare('UPDATE users SET last_ip = ? WHERE id = ?')->execute([get_client_ip(), $u['id']]);
        json_out(['ok' => true, 'token' => $token, 'username' => $u['username'], 'nickname' => $u['nickname']]);
        break;

    // ===== 用户：当前信息 =====
    case 'me':
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '未登录'], 401);
        json_out(['ok' => true, 'username' => $user['username'], 'nickname' => $user['nickname']]);
        break;

    // ===== 用户：退出 =====
    case 'logout':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $token = get_bearer_token();
        if ($token) $pdo->prepare('DELETE FROM user_tokens WHERE token = ?')->execute([$token]);
        json_out(['ok' => true]);
        break;

    // ===== 用户：修改资料 / 密码 =====
    case 'profile_update':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '未登录'], 401);
        $nick = trim($_POST['nickname'] ?? '');
        if ($nick !== '') {
            if (mb_strlen($nick) > 50) json_out(['ok' => false, 'msg' => '昵称过长'], 400);
            $nick = filter_bad_words($pdo, $nick);
            $pdo->prepare('UPDATE users SET nickname = ? WHERE id = ?')->execute([$nick, $user['id']]);
        }
        $old = trim($_POST['old_password'] ?? '');
        $new = trim($_POST['new_password'] ?? '');
        if ($new !== '') {
            if (mb_strlen($new) < 6) json_out(['ok' => false, 'msg' => '新密码至少 6 位'], 400);
            $u = $pdo->prepare('SELECT password FROM users WHERE id = ?');
            $u->execute([$user['id']]);
            $row = $u->fetch();
            if ($old === '' || !password_verify($old, $row['password'])) json_out(['ok' => false, 'msg' => '原密码错误'], 400);
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
        }
        $m = $pdo->prepare('SELECT username, nickname FROM users WHERE id = ?');
        $m->execute([$user['id']]);
        $me = $m->fetch();
        json_out(['ok' => true, 'msg' => '已更新', 'username' => $me['username'], 'nickname' => $me['nickname']]);
        break;

    // ===== 用户：我的内容（个人中心）=====
    case 'my_content':
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '未登录'], 401);
        $uid = $user['id'];
        $posts = $pdo->query("SELECT id, board, title, content, status, created_at FROM posts WHERE user_id = $uid ORDER BY created_at DESC LIMIT 20")->fetchAll();
        $mats = $pdo->query("SELECT id, title, status, downloads, created_at FROM materials WHERE user_id = $uid ORDER BY created_at DESC LIMIT 20")->fetchAll();
        $qs = $pdo->query("SELECT id, title, answer_count, created_at FROM questions WHERE user_id = $uid ORDER BY created_at DESC LIMIT 20")->fetchAll();
        $ts = $pdo->query("SELECT id, title, reply_count, created_at FROM forum_topics WHERE user_id = $uid ORDER BY created_at DESC LIMIT 20")->fetchAll();
        json_out(['ok' => true, 'posts' => $posts, 'materials' => $mats, 'questions' => $qs, 'topics' => $ts]);
        break;

    // ===== 消息：列表 + 未读 =====
    case 'notifications':
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '未登录'], 401);
        $list = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
        $list->execute([$user['id']]);
        $unread = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $unread->execute([$user['id']]);
        json_out(['ok' => true, 'notifications' => $list->fetchAll(), 'unread' => (int) $unread->fetchColumn()]);
        break;

    // ===== 消息：标记已读 =====
    case 'notifications_read':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '未登录'], 401);
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
        else $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
        json_out(['ok' => true]);
        break;

    // ===== 问答：列表 =====
    case 'qa_list':
        $page = max(1, intval($_GET['page'] ?? 1));
        $per = 10;
        $offset = ($page - 1) * $per;
        $q = trim($_GET['q'] ?? '');
        $where = 'WHERE q.status = 1';
        $params = [];
        if ($q !== '') { $where .= ' AND (q.title LIKE ? OR q.content LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        $sql = "SELECT q.*, u.nickname AS asker FROM questions q LEFT JOIN users u ON u.id = q.user_id $where ORDER BY q.created_at DESC LIMIT $per OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $c = $pdo->prepare("SELECT COUNT(*) FROM questions q $where");
        $c->execute($params);
        json_out(['ok' => true, 'questions' => $rows, 'page' => $page, 'per' => $per, 'total' => (int) $c->fetchColumn()]);
        break;

    // ===== 问答：详情 =====
    case 'qa_detail':
        $id = intval($_GET['id'] ?? 0);
        $q = $pdo->prepare('SELECT q.*, u.nickname AS asker FROM questions q LEFT JOIN users u ON u.id = q.user_id WHERE q.id = ? AND q.status = 1');
        $q->execute([$id]);
        $qq = $q->fetch();
        if (!$qq) json_out(['ok' => false, 'msg' => '问题不存在'], 404);
        $pdo->prepare('UPDATE questions SET views = views + 1 WHERE id = ?')->execute([$id]);
        $a = $pdo->prepare('SELECT a.*, u.nickname AS answerer FROM answers a LEFT JOIN users u ON u.id = a.user_id WHERE a.question_id = ? AND a.status = 1 ORDER BY a.accepted DESC, a.likes DESC, a.created_at ASC');
        $a->execute([$id]);
        json_out(['ok' => true, 'question' => $qq, 'answers' => $a->fetchAll()]);
        break;

    // ===== 问答：提问（需登录）=====
    case 'qa_ask':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '标题需 2-150 字'], 400);
        if (mb_strlen($content) < 1 || mb_strlen($content) > 1000) json_out(['ok' => false, 'msg' => '内容需 1-1000 字'], 400);
        $title = filter_bad_words($pdo, $title);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO questions (user_id, title, content, ip) VALUES (?, ?, ?, ?)')->execute([$user['id'], $title, $content, get_client_ip()]);
        json_out(['ok' => true, 'msg' => '发布成功']);
        break;

    // ===== 问答：回答（需登录）=====
    case 'qa_answer':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $id = intval($_POST['question_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($content) < 1 || mb_strlen($content) > 800) json_out(['ok' => false, 'msg' => '回答需 1-800 字'], 400);
        $q = $pdo->prepare('SELECT id, user_id FROM questions WHERE id = ? AND status = 1');
        $q->execute([$id]);
        $qq = $q->fetch();
        if (!$qq) json_out(['ok' => false, 'msg' => '问题不存在'], 404);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO answers (question_id, user_id, content) VALUES (?, ?, ?)')->execute([$id, $user['id'], $content]);
        $pdo->prepare('UPDATE questions SET answer_count = answer_count + 1 WHERE id = ?')->execute([$id]);
        if ($qq['user_id'] && $qq['user_id'] != $user['id']) {
            add_notification($pdo, $qq['user_id'], 'answer', $id, '有人回答了你的问题：' . mb_substr($content, 0, 30));
        }
        json_out(['ok' => true, 'msg' => '回答成功']);
        break;

    // ===== 问答：问题点赞（IP 去重）=====
    case 'qa_like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $ip = get_client_ip();
        $chk = $pdo->prepare('SELECT 1 FROM likes WHERE entity_type = "question" AND entity_id = ? AND ip = ?');
        $chk->execute([$id, $ip]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '已点赞']);
        $pdo->prepare('INSERT INTO likes (entity_type, entity_id, ip) VALUES ("question", ?, ?)')->execute([$id, $ip]);
        $pdo->prepare('UPDATE questions SET likes = likes + 1 WHERE id = ?')->execute([$id]);
        $l = $pdo->prepare('SELECT likes FROM questions WHERE id = ?');
        $l->execute([$id]);
        json_out(['ok' => true, 'likes' => (int) $l->fetchColumn()]);
        break;

    // ===== 问答：回答点赞 =====
    case 'qa_answer_like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $ip = get_client_ip();
        $chk = $pdo->prepare('SELECT 1 FROM likes WHERE entity_type = "answer" AND entity_id = ? AND ip = ?');
        $chk->execute([$id, $ip]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '已点赞']);
        $pdo->prepare('INSERT INTO likes (entity_type, entity_id, ip) VALUES ("answer", ?, ?)')->execute([$id, $ip]);
        $pdo->prepare('UPDATE answers SET likes = likes + 1 WHERE id = ?')->execute([$id]);
        $l = $pdo->prepare('SELECT likes FROM answers WHERE id = ?');
        $l->execute([$id]);
        json_out(['ok' => true, 'likes' => (int) $l->fetchColumn()]);
        break;

    // ===== 问答：采纳回答（仅题主）=====
    case 'qa_answer_accept':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $aid = intval($_POST['answer_id'] ?? 0);
        $a = $pdo->prepare('SELECT a.id, a.question_id, q.user_id FROM answers a JOIN questions q ON q.id = a.question_id WHERE a.id = ?');
        $a->execute([$aid]);
        $row = $a->fetch();
        if (!$row) json_out(['ok' => false, 'msg' => '回答不存在'], 404);
        if ($row['user_id'] != $user['id']) json_out(['ok' => false, 'msg' => '只能采纳自己问题的回答'], 403);
        $pdo->prepare('UPDATE answers SET accepted = 0 WHERE question_id = ?')->execute([$row['question_id']]);
        $pdo->prepare('UPDATE answers SET accepted = 1 WHERE id = ?')->execute([$aid]);
        json_out(['ok' => true]);
        break;

    // ===== 论坛：列表 =====
    case 'forum_list':
        $page = max(1, intval($_GET['page'] ?? 1));
        $per = 10;
        $offset = ($page - 1) * $per;
        $cat = intval($_GET['category'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        $where = 'WHERE t.status = 1';
        $params = [];
        if ($cat > 0) { $where .= ' AND t.category_id = ?'; $params[] = $cat; }
        if ($q !== '') { $where .= ' AND (t.title LIKE ? OR t.content LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        $sql = "SELECT t.*, c.name AS category_name, u.nickname AS author FROM forum_topics t LEFT JOIN forum_categories c ON c.id = t.category_id LEFT JOIN users u ON u.id = t.user_id $where ORDER BY t.created_at DESC LIMIT $per OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $c = $pdo->prepare("SELECT COUNT(*) FROM forum_topics t $where");
        $c->execute($params);
        json_out(['ok' => true, 'topics' => $rows, 'page' => $page, 'per' => $per, 'total' => (int) $c->fetchColumn()]);
        break;

    // ===== 论坛：详情 =====
    case 'forum_detail':
        $id = intval($_GET['id'] ?? 0);
        $t = $pdo->prepare('SELECT t.*, c.name AS category_name, u.nickname AS author FROM forum_topics t LEFT JOIN forum_categories c ON c.id = t.category_id LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.status = 1');
        $t->execute([$id]);
        $tt = $t->fetch();
        if (!$tt) json_out(['ok' => false, 'msg' => '帖子不存在'], 404);
        $pdo->prepare('UPDATE forum_topics SET views = views + 1 WHERE id = ?')->execute([$id]);
        $r = $pdo->prepare('SELECT r.*, u.nickname AS replier FROM forum_replies r LEFT JOIN users u ON u.id = r.user_id WHERE r.topic_id = ? AND r.status = 1 ORDER BY r.created_at ASC');
        $r->execute([$id]);
        json_out(['ok' => true, 'topic' => $tt, 'replies' => $r->fetchAll()]);
        break;

    // ===== 论坛：发帖（需登录）=====
    case 'forum_post':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $cat = intval($_POST['category_id'] ?? 0);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '标题需 2-150 字'], 400);
        if (mb_strlen($content) < 1 || mb_strlen($content) > 2000) json_out(['ok' => false, 'msg' => '内容需 1-2000 字'], 400);
        $title = filter_bad_words($pdo, $title);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO forum_topics (user_id, category_id, title, content, ip) VALUES (?, ?, ?, ?, ?)')->execute([$user['id'], $cat, $title, $content, get_client_ip()]);
        json_out(['ok' => true, 'msg' => '发布成功']);
        break;

    // ===== 论坛：回复（需登录）=====
    case 'forum_reply':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $id = intval($_POST['topic_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($content) < 1 || mb_strlen($content) > 800) json_out(['ok' => false, 'msg' => '回复需 1-800 字'], 400);
        $t = $pdo->prepare('SELECT id, user_id FROM forum_topics WHERE id = ? AND status = 1');
        $t->execute([$id]);
        $tt = $t->fetch();
        if (!$tt) json_out(['ok' => false, 'msg' => '帖子不存在'], 404);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO forum_replies (topic_id, user_id, content) VALUES (?, ?, ?)')->execute([$id, $user['id'], $content]);
        $pdo->prepare('UPDATE forum_topics SET reply_count = reply_count + 1 WHERE id = ?')->execute([$id]);
        if ($tt['user_id'] && $tt['user_id'] != $user['id']) {
            add_notification($pdo, $tt['user_id'], 'reply', $id, '有人回复了你的帖子：' . mb_substr($content, 0, 30));
        }
        json_out(['ok' => true, 'msg' => '回复成功']);
        break;

    // ===== 论坛：帖子点赞 =====
    case 'forum_like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $ip = get_client_ip();
        $chk = $pdo->prepare('SELECT 1 FROM likes WHERE entity_type = "forum_topic" AND entity_id = ? AND ip = ?');
        $chk->execute([$id, $ip]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '已点赞']);
        $pdo->prepare('INSERT INTO likes (entity_type, entity_id, ip) VALUES ("forum_topic", ?, ?)')->execute([$id, $ip]);
        $pdo->prepare('UPDATE forum_topics SET likes = likes + 1 WHERE id = ?')->execute([$id]);
        $l = $pdo->prepare('SELECT likes FROM forum_topics WHERE id = ?');
        $l->execute([$id]);
        json_out(['ok' => true, 'likes' => (int) $l->fetchColumn()]);
        break;

    // ===== 论坛：回复点赞 =====
    case 'forum_reply_like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $ip = get_client_ip();
        $chk = $pdo->prepare('SELECT 1 FROM likes WHERE entity_type = "forum_reply" AND entity_id = ? AND ip = ?');
        $chk->execute([$id, $ip]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '已点赞']);
        $pdo->prepare('INSERT INTO likes (entity_type, entity_id, ip) VALUES ("forum_reply", ?, ?)')->execute([$id, $ip]);
        $pdo->prepare('UPDATE forum_replies SET likes = likes + 1 WHERE id = ?')->execute([$id]);
        $l = $pdo->prepare('SELECT likes FROM forum_replies WHERE id = ?');
        $l->execute([$id]);
        json_out(['ok' => true, 'likes' => (int) $l->fetchColumn()]);
        break;

    // ===== 资料：分类列表 =====
    case 'material_categories':
        $rows = $pdo->query('SELECT * FROM material_categories ORDER BY sort ASC, id ASC')->fetchAll();
        json_out(['ok' => true, 'categories' => $rows]);
        break;

    // ===== 论坛：分类列表 =====
    case 'forum_categories':
        $rows = $pdo->query('SELECT * FROM forum_categories ORDER BY sort ASC, id ASC')->fetchAll();
        json_out(['ok' => true, 'categories' => $rows]);
        break;

    // ===== 资料：列表（学习专区，需登录后才能查看）=====
    case 'material_list':
        $viewer = require_user($pdo);
        if (!$viewer) json_out(['ok' => false, 'msg' => '请先登录后查看学习专区'], 401);
        $page = max(1, intval($_GET['page'] ?? 1));
        $per = 12;
        $offset = ($page - 1) * $per;
        $cat = intval($_GET['category'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        $where = 'WHERE m.status = 1';
        $params = [];
        if ($cat > 0) { $where .= ' AND m.category_id = ?'; $params[] = $cat; }
        if ($q !== '') { $where .= ' AND (m.title LIKE ? OR m.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        $sql = "SELECT m.*, c.name AS category_name, u.nickname AS uploader FROM materials m LEFT JOIN material_categories c ON c.id = m.category_id LEFT JOIN users u ON u.id = m.user_id $where ORDER BY m.created_at DESC LIMIT " . intval($per) . ' OFFSET ' . intval($offset);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $c = $pdo->prepare("SELECT COUNT(*) FROM materials m $where");
        $c->execute($params);
        json_out(['ok' => true, 'materials' => $rows, 'page' => $page, 'per' => $per, 'total' => (int) $c->fetchColumn()]);
        break;

    // ===== 资料：上传（需登录）=====
    case 'material_upload':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录后再上传'], 401);
        $title = trim($_POST['title'] ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '标题需 2-150 字'], 400);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 1000);
        $cat = intval($_POST['category_id'] ?? 0);
        $up = handle_material_upload($_FILES['file'] ?? []);
        if ($up === false) json_out(['ok' => false, 'msg' => '文件需为常见文档格式且 ≤20MB'], 400);
        $title = filter_bad_words($pdo, $title);
        $desc = filter_bad_words($pdo, $desc);
        $orig = filter_bad_words($pdo, $up['orig']);
        $pdo->prepare('INSERT INTO materials (user_id, title, description, category_id, file_path, orig_name, file_size, file_type, status, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$user['id'], $title, $desc, $cat, $up['stored'], $orig, $up['size'], $up['ext'], get_client_ip()]);
        json_out(['ok' => true, 'msg' => '上传成功，等待审核后开放下载']);
        break;

    // ===== 资料：下载 =====
    case 'material_download':
        $viewer = require_user($pdo);
        if (!$viewer) json_out(['ok' => false, 'msg' => '请先登录后下载'], 401);
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM materials WHERE id = ? AND status = 1');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_out(['ok' => false, 'msg' => '资源不存在或未通过审核'], 404);
        $path = UPLOAD_DIR . 'materials/' . $m['file_path'];
        if (!is_file($path)) json_out(['ok' => false, 'msg' => '文件已缺失'], 404);
        $pdo->prepare('UPDATE materials SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($m['orig_name']) . '"');
        header('Content-Length: ' . $m['file_size']);
        readfile($path);
        exit;
        break;

    // ===== 资料：预览/查看（需登录，图片内联显示，其他文件下载）=====
    case 'material_view':
        $viewer = require_user($pdo);
        if (!$viewer) json_out(['ok' => false, 'msg' => '请先登录后查看'], 401);
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM materials WHERE id = ? AND status = 1');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_out(['ok' => false, 'msg' => '资源不存在或未通过审核'], 404);
        $path = UPLOAD_DIR . 'materials/' . $m['file_path'];
        if (!is_file($path)) json_out(['ok' => false, 'msg' => '文件已缺失'], 404);
        $imgTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $ext = strtolower($m['file_type']);
        if (isset($imgTypes[$ext])) {
            header('Content-Type: ' . $imgTypes[$ext]);
            header('Content-Length: ' . $m['file_size']);
            readfile($path);
            exit;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($m['orig_name']) . '"');
        header('Content-Length: ' . $m['file_size']);
        readfile($path);
        exit;
        break;

    // ===== 资料：点赞 =====
    case 'material_like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $ip = get_client_ip();
        $p = $pdo->prepare('SELECT id FROM materials WHERE id = ? AND status = 1');
        $p->execute([$id]);
        if (!$p->fetch()) json_out(['ok' => false, 'msg' => '资源不存在或未通过审核'], 400);
        $chk = $pdo->prepare('SELECT 1 FROM material_likes WHERE material_id = ? AND ip = ?');
        $chk->execute([$id, $ip]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '你已经点过赞啦']);
        $pdo->prepare('INSERT INTO material_likes (material_id, ip) VALUES (?, ?)')->execute([$id, $ip]);
        $pdo->prepare('UPDATE materials SET likes = likes + 1 WHERE id = ?')->execute([$id]);
        $l = $pdo->prepare('SELECT likes FROM materials WHERE id = ?');
        $l->execute([$id]);
        json_out(['ok' => true, 'likes' => (int) $l->fetchColumn()]);
        break;

    // ===== 后台：登录 =====
    case 'admin_login':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $u = trim($_POST['username'] ?? '');
        $pw = trim($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM admin WHERE username = ?');
        $stmt->execute([$u]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($pw, $admin['password'])) json_out(['ok' => false, 'msg' => '账号或密码错误'], 401);
        $token = bin2hex(random_bytes(32));
        $exp = date('Y-m-d H:i:s', time() + 86400 * 7);
        $pdo->prepare('INSERT INTO admin_tokens (token, admin_id, expires_at) VALUES (?, ?, ?)')->execute([$token, $admin['id'], $exp]);
        json_out(['ok' => true, 'token' => $token, 'username' => $admin['username']]);
        break;

    // ===== 后台：帖子列表 =====
    case 'admin_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $status = isset($_GET['status']) ? intval($_GET['status']) : null;
        if ($status !== null && in_array($status, [0, 1, 2], true)) {
            $stmt = $pdo->prepare('SELECT * FROM posts WHERE status = ? ORDER BY created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $pdo->query('SELECT * FROM posts ORDER BY created_at DESC');
        }
        json_out(['ok' => true, 'posts' => $stmt->fetchAll()]);
        break;

    // ===== 后台：帖子通过/拒绝 =====
    case 'admin_approve':
    case 'admin_reject':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['post_id'] ?? 0);
        $st = $action === 'admin_approve' ? 1 : 2;
        $pdo->prepare('UPDATE posts SET status = ? WHERE id = ?')->execute([$st, $id]);
        $p = $pdo->prepare('SELECT user_id, board FROM posts WHERE id = ?');
        $p->execute([$id]);
        $row = $p->fetch();
        if ($row && $row['user_id']) {
            $label = $row['board'] == 2 ? '论坛帖子' : '表白';
            $msg = $st === 1 ? "你的{$label}已通过审核" : "你的{$label}未通过审核";
            add_notification($pdo, $row['user_id'], 'post_' . ($st === 1 ? 'approved' : 'rejected'), $id, $msg);
        }
        json_out(['ok' => true]);
        break;

    // ===== 后台：帖子删除 =====
    case 'admin_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['post_id'] ?? 0);
        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
        break;

    // ===== 后台：资料列表 =====
    case 'admin_material_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $status = isset($_GET['status']) ? intval($_GET['status']) : null;
        if ($status !== null && in_array($status, [0, 1, 2], true)) {
            $stmt = $pdo->prepare('SELECT * FROM materials WHERE status = ? ORDER BY created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $pdo->query('SELECT * FROM materials ORDER BY created_at DESC');
        }
        json_out(['ok' => true, 'materials' => $stmt->fetchAll()]);
        break;

    // ===== 后台：资料通过/拒绝 =====
    case 'admin_material_approve':
    case 'admin_material_reject':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        $st = $action === 'admin_material_approve' ? 1 : 2;
        $pdo->prepare('UPDATE materials SET status = ? WHERE id = ?')->execute([$st, $id]);
        $m = $pdo->prepare('SELECT user_id, title FROM materials WHERE id = ?');
        $m->execute([$id]);
        $row = $m->fetch();
        if ($row && $row['user_id']) {
            $msg = $st === 1 ? '你的资料《' . mb_substr($row['title'], 0, 20) . '》已通过审核，可开放下载' : '你的资料《' . mb_substr($row['title'], 0, 20) . '》未通过审核';
            add_notification($pdo, $row['user_id'], 'material_' . ($st === 1 ? 'approved' : 'rejected'), $id, $msg);
        }
        json_out(['ok' => true]);
        break;

    // ===== 后台：资料删除 =====
    case 'admin_material_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        $m = $pdo->prepare('SELECT file_path FROM materials WHERE id = ?');
        $m->execute([$id]);
        $row = $m->fetch();
        if ($row) {
            $fp = UPLOAD_DIR . 'materials/' . $row['file_path'];
            if (is_file($fp)) @unlink($fp);
        }
        $pdo->prepare('DELETE FROM materials WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
        break;

    // ===== 后台：问答/论坛删除 =====
    case 'admin_qa_delete':
    case 'admin_forum_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        if ($action === 'admin_qa_delete') $pdo->prepare('UPDATE questions SET status = 2 WHERE id = ?')->execute([$id]);
        else $pdo->prepare('UPDATE forum_topics SET status = 2 WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
        break;

    // ===== 后台：统计 =====
    case 'admin_stats':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        json_out(['ok' => true, 'stats' => [
            'pending'   => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 0")->fetchColumn(),
            'approved'  => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 1")->fetchColumn(),
            'rejected'  => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 2")->fetchColumn(),
            'mat_pending' => (int) $pdo->query("SELECT COUNT(*) FROM materials WHERE status = 0")->fetchColumn(),
            'mat_approved'=> (int) $pdo->query("SELECT COUNT(*) FROM materials WHERE status = 1")->fetchColumn(),
            'questions' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE status = 1")->fetchColumn(),
            'topics'    => (int) $pdo->query("SELECT COUNT(*) FROM forum_topics WHERE status = 1")->fetchColumn(),
            'comments'  => (int) $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn(),
            'users'     => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        ]]);
        break;

    // ===== 管理员：生成邀请码 =====
    case 'admin_invite_create':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $count = max(1, min(50, (int) ($_POST['count'] ?? 1)));
        $max   = max(1, min(100, (int) ($_POST['max_uses'] ?? 1)));
        $days  = (int) ($_POST['expires_days'] ?? 0);
        $exp   = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $chk = $pdo->prepare('SELECT 1 FROM invite_codes WHERE code = ?');
            $chk->execute([$c]);
            if ($chk->fetch()) { $i--; continue; }
            $pdo->prepare('INSERT INTO invite_codes (code, created_by, max_uses, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$c, $adm['admin_id'], $max, $exp]);
            $codes[] = $c;
        }
        json_out(['ok' => true, 'codes' => $codes]);
        break;

    // ===== 管理员：邀请码列表 =====
    case 'admin_invite_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $rows = $pdo->query('SELECT id, code, max_uses, used_count, status, created_at, expires_at
                             FROM invite_codes ORDER BY id DESC LIMIT 200')->fetchAll();
        json_out(['ok' => true, 'invites' => $rows]);
        break;

    // ===== 后台：用户列表（含上传统计） =====
    case 'admin_users':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $filter = isset($_GET['status']) ? intval($_GET['status']) : -1;
        $where  = ($filter === 0 || $filter === 1) ? 'WHERE u.status = ' . $filter : '';
        $rows = $pdo->query(
            "SELECT u.id, u.username, u.nickname, u.created_at, u.status, u.banned_at, u.ban_reason, u.last_ip,
                    (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS cnt_posts,
                    (SELECT COUNT(*) FROM questions q WHERE q.user_id = u.id) AS cnt_questions,
                    (SELECT COUNT(*) FROM forum_topics t WHERE t.user_id = u.id) AS cnt_topics,
                    (SELECT COUNT(*) FROM materials m WHERE m.user_id = u.id) AS cnt_materials,
                    (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS cnt_comments
             FROM users u $where ORDER BY u.created_at DESC LIMIT 500"
        )->fetchAll();
        json_out(['ok' => true, 'users' => $rows]);
        break;

    // ===== 后台：封禁 / 解封用户 =====
    case 'admin_user_ban':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $uid = intval($_POST['user_id'] ?? 0);
        $ban  = intval($_POST['ban'] ?? 1);
        $reason = trim($_POST['reason'] ?? '');
        if ($uid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        if ($ban) {
            $pdo->prepare('UPDATE users SET status = 0, banned_at = NOW(), ban_reason = ? WHERE id = ?')
                ->execute([$reason, $uid]);
            $pdo->prepare('DELETE FROM user_tokens WHERE user_id = ?')->execute([$uid]); // 强制下线
        } else {
            $pdo->prepare('UPDATE users SET status = 1, banned_at = NULL, ban_reason = NULL WHERE id = ?')
                ->execute([$uid]);
        }
        json_out(['ok' => true, 'banned' => (bool) $ban]);
        break;

    // ===== 后台：删除用户（级联删除其上传内容 + 资料文件） =====
    case 'admin_user_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        // 删除用户上传的资料文件
        $mats = $pdo->prepare('SELECT file_path FROM materials WHERE user_id = ?');
        $mats->execute([$uid]);
        foreach ($mats->fetchAll() as $m) {
            $fp = UPLOAD_DIR . 'materials/' . $m['file_path'];
            if (is_file($fp)) @unlink($fp);
        }
        // 删除该用户帖子下的评论（防止孤儿评论）
        $pids = $pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
        $pids->execute([$uid]);
        $postIds = $pids->fetchAll(PDO::FETCH_COLUMN);
        if ($postIds) {
            $ph = str_repeat('?,', count($postIds) - 1) . '?';
            $pdo->prepare("DELETE FROM comments WHERE post_id IN ($ph)")->execute($postIds);
        }
        // 级联删除用户相关的所有内容与会话
        $pdo->prepare('DELETE FROM user_tokens WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM materials WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM forum_topics WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM questions WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM comments WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        json_out(['ok' => true]);
        break;

    // ===== 后台：查看某用户上传的所有内容 =====
    case 'admin_user_contents':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $posts = $pdo->prepare("SELECT 'post' AS type, id, title, content, status, created_at, ip FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $posts->execute([$uid]);
        $questions = $pdo->prepare("SELECT 'question' AS type, id, title, content, status, created_at, ip FROM questions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $questions->execute([$uid]);
        $topics = $pdo->prepare("SELECT 'topic' AS type, id, title, content, status, created_at, ip FROM forum_topics WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $topics->execute([$uid]);
        $materials = $pdo->prepare("SELECT 'material' AS type, id, title AS content, description, status, created_at, ip FROM materials WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $materials->execute([$uid]);
        $comments = $pdo->prepare("SELECT 'comment' AS type, id, content, NULL AS title, NULL AS status, created_at, ip FROM comments WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $comments->execute([$uid]);
        json_out(['ok' => true, 'contents' => array_merge(
            $posts->fetchAll(), $questions->fetchAll(), $topics->fetchAll(), $materials->fetchAll(), $comments->fetchAll()
        )]);
        break;

    // ===== 后台：删除评论 =====
    case 'admin_comment_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
        break;

    // ===== 后台：创建用户 =====
    case 'admin_user_create':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        if ($username === '' || $password === '') json_out(['ok' => false, 'msg' => '用户名和密码必填'], 400);
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) json_out(['ok' => false, 'msg' => '用户名需3-20位字母/数字/下划线'], 400);
        if (strlen($password) < 6) json_out(['ok' => false, 'msg' => '密码至少6位'], 400);
        $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $chk->execute([$username]);
        if ($chk->fetch()) json_out(['ok' => false, 'msg' => '用户名已存在'], 400);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($nickname === '') $nickname = $username;
        $nickname = filter_bad_words($pdo, $nickname);
        $pdo->prepare('INSERT INTO users (username, password, nickname, status) VALUES (?, ?, ?, 1)')
            ->execute([$username, $hash, $nickname]);
        json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);
        break;

    // ===== 后台：编辑用户（昵称 / 密码 / 状态） =====
    case 'admin_user_edit':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $nickname = trim($_POST['nickname'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $status   = isset($_POST['status']) ? intval($_POST['status']) : null;
        $reason   = trim($_POST['reason'] ?? '');
        if ($nickname !== '') {
            $nickname = filter_bad_words($pdo, $nickname);
            $pdo->prepare('UPDATE users SET nickname = ? WHERE id = ?')->execute([$nickname, $uid]);
        }
        if ($password !== '') {
            if (strlen($password) < 6) json_out(['ok' => false, 'msg' => '密码至少6位'], 400);
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_BCRYPT), $uid]);
            $pdo->prepare('DELETE FROM user_tokens WHERE user_id = ?')->execute([$uid]); // 改密后强制重新登录
        }
        if ($status === 0 || $status === 1) {
            if ($status === 0) {
                $pdo->prepare('UPDATE users SET status = 0, banned_at = NOW(), ban_reason = ? WHERE id = ?')
                    ->execute([$reason, $uid]);
                $pdo->prepare('DELETE FROM user_tokens WHERE user_id = ?')->execute([$uid]);
            } else {
                $pdo->prepare('UPDATE users SET status = 1, banned_at = NULL, ban_reason = NULL WHERE id = ?')
                    ->execute([$uid]);
            }
        }
        json_out(['ok' => true]);
        break;

    // ===== 后台：编辑资料文件信息（标题 / 描述 / 状态） =====
    // ===== 后台：直接添加学习资料（免审核，直接上架）=====
    case 'admin_material_add':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $title = trim($_POST['title'] ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '标题需 2-150 字'], 400);
        $description = mb_substr(trim($_POST['description'] ?? ''), 0, 1000);
        $cat = intval($_POST['category_id'] ?? 0);
        if (empty($_FILES['file'])) json_out(['ok' => false, 'msg' => '请选择要上传的文件'], 400);
        $up = handle_material_upload($_FILES['file']);
        if ($up === false) json_out(['ok' => false, 'msg' => '文件需为常见格式且 ≤20MB（支持图片/文档/压缩包等）'], 400);
        $title = filter_bad_words($pdo, $title);
        $description = filter_bad_words($pdo, $description);
        $orig = filter_bad_words($pdo, $up['orig']);
        $pdo->prepare('INSERT INTO materials (user_id, title, description, category_id, file_path, orig_name, file_size, file_type, status, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
            ->execute([0, $title, $description, $cat, $up['stored'], $orig, $up['size'], $up['ext'], get_client_ip()]);
        json_out(['ok' => true, 'msg' => '已直接添加并上架']);
        break;

    // ===== 后台：预览资料文件（管理员，任意状态均可）=====
    case 'admin_material_view':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM materials WHERE id = ?');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_out(['ok' => false, 'msg' => '资源不存在'], 404);
        $path = UPLOAD_DIR . 'materials/' . $m['file_path'];
        if (!is_file($path)) json_out(['ok' => false, 'msg' => '文件已缺失'], 404);
        $imgTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $ext = strtolower($m['file_type']);
        if (isset($imgTypes[$ext])) {
            header('Content-Type: ' . $imgTypes[$ext]);
            header('Content-Length: ' . $m['file_size']);
            readfile($path);
            exit;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($m['orig_name']) . '"');
        header('Content-Length: ' . $m['file_size']);
        readfile($path);
        exit;
        break;

    case 'admin_material_edit':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? intval($_POST['status']) : null;
        if ($title === '') json_out(['ok' => false, 'msg' => '标题必填'], 400);
        $title = filter_bad_words($pdo, $title);
        $description = filter_bad_words($pdo, $description);
        $sql = 'UPDATE materials SET title = ?, description = ?';
        $params = [$title, $description];
        if ($status === 0 || $status === 1) { $sql .= ', status = ?'; $params[] = $status; }
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        $pdo->prepare($sql)->execute($params);
        json_out(['ok' => true]);
        break;

    // ===== 后台：编辑文本内容（帖子/问答/论坛/评论）=====
    case 'admin_post_edit':
    case 'admin_question_edit':
    case 'admin_forum_edit':
    case 'admin_comment_edit':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $title = filter_bad_words($pdo, trim($_POST['title'] ?? ''));
        $content = filter_bad_words($pdo, trim($_POST['content'] ?? ''));
        $act = $action;
        if ($act === 'admin_comment_edit') {
            if ($content === '') json_out(['ok' => false, 'msg' => '评论内容不能为空'], 400);
            $pdo->prepare('UPDATE comments SET content = ? WHERE id = ?')->execute([$content, $id]);
        } else {
            $tbl = ['admin_post_edit' => 'posts', 'admin_question_edit' => 'questions', 'admin_forum_edit' => 'forum_topics'][$act];
            if ($title === '' || $content === '') json_out(['ok' => false, 'msg' => '标题和正文不能为空'], 400);
            $pdo->prepare("UPDATE `$tbl` SET title = ?, content = ? WHERE id = ?")->execute([$title, $content, $id]);
        }
        json_out(['ok' => true]);
        break;

    // ===== 后台：不良用语词库（发布时自动替换为 *）=====
    case 'admin_badword_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        try {
            $rows = $pdo->query('SELECT id, word, created_at FROM bad_words ORDER BY id DESC')->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        json_out(['ok' => true, 'words' => $rows]);
        break;

    case 'admin_badword_add':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $raw = trim($_POST['words'] ?? '');
        if ($raw === '') json_out(['ok' => false, 'msg' => '请输入要添加的词'], 400);
        $items = preg_split('/[,，;；\r\n]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $added = 0;
        $stmt = $pdo->prepare('INSERT IGNORE INTO bad_words (word) VALUES (?)');
        foreach ($items as $it) {
            $w = trim($it);
            $len = mb_strlen($w, 'UTF-8');
            if ($w === '' || $len < 2 || $len > 50) continue;
            $stmt->execute([$w]);
            $added += $stmt->rowCount();
        }
        json_out(['ok' => true, 'added' => $added, 'msg' => '已添加 ' . $added . ' 个词']);
        break;

    case 'admin_badword_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $wid = intval($_POST['id'] ?? 0);
        if ($wid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $pdo->prepare('DELETE FROM bad_words WHERE id = ?')->execute([$wid]);
        json_out(['ok' => true]);
        break;

    default:
        json_out(['ok' => false, 'msg' => '未知操作'], 404);
}
