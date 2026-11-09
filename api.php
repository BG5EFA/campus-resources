<?php
/**
 * 校园墙 + 学习专区 + 个人中心
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
function post_categories()
{
    return ['love', 'lost', 'help', 'chat', 'other'];
}
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
// 免登录学习链接：只接受标准 http/https 网址，杜绝 javascript:/data:/file: 等伪协议
function validate_study_url($url)
{
    $url = trim((string) $url);
    if ($url === '' || mb_strlen($url, 'UTF-8') > 1000) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) return null;
    if (!preg_match('#^https?://#i', $url)) return null;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
    return $url;
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
// 公开接口脱敏：前台绝不返回作者 user_id / IP（否则「匿名」形同虚设）
// 仅向当前登录者返回 is_mine，用于显示「删除自己的内容」，不泄露他人身份
function public_posts($pdo, $rows)
{
    $viewer = require_user($pdo);
    $vid = $viewer ? (int) $viewer['id'] : 0;
    $fav = [];
    // 收藏表由 migrate14.sql 创建；未执行迁移时优雅降级，不影响公开列表
    if ($vid && $rows) {
        try {
            $ids = [];
            foreach ($rows as $r) $ids[] = (int) $r['id'];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT post_id FROM favorites WHERE user_id = ? AND post_id IN ($ph)");
            $st->execute(array_merge([$vid], $ids));
            foreach ($st->fetchAll() as $f) $fav[(int) $f['post_id']] = 1;
        } catch (Throwable $e) { $fav = []; }
    }
    foreach ($rows as &$r) {
        $r['is_mine'] = ($vid && !empty($r['user_id']) && (int) $r['user_id'] === $vid) ? 1 : 0;
        $r['is_favorited'] = isset($fav[(int) $r['id']]) ? 1 : 0;
        unset($r['user_id'], $r['ip']);
    }
    unset($r);
    return $rows;
}
function public_comments($rows)
{
    foreach ($rows as &$r) { unset($r['user_id'], $r['ip']); }
    unset($r);
    return $rows;
}
// 后台设置内容匿名状态：is_anon 与前台显示名必须同步更新，
// 否则「标记改了但 nickname 仍是昵称/匿名」，前台显示不会变化。
// 匿名 => nickname 固定「匿名」；不匿名 => 管理员自定义名 > 账号昵称 > 原昵称 > 校园用户。
// user_id / ip 一律不动，后台始终可溯源真实发布者。
function set_content_anon($pdo, $type, $id, $anon, $displayName = '')
{
    $table = $type === 'comment' ? 'comments' : 'posts';
    $id = (int) $id;
    $stmt = $pdo->prepare("SELECT c.nickname, c.user_id, u.nickname AS account_nickname
                           FROM `$table` c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_out(['ok' => false, 'msg' => '内容不存在'], 404);
    if ($anon) {
        $nickname = '匿名';
    } else {
        $custom = trim((string) $displayName);
        $nickname = $custom !== '' ? mb_substr($custom, 0, 50) : (string) ($row['account_nickname'] ?? '');
        if ($nickname === '' || $nickname === '匿名') {
            $old = (string) ($row['nickname'] ?? '');
            $nickname = ($old !== '' && $old !== '匿名') ? $old : '校园用户';
        }
        $nickname = filter_bad_words($pdo, $nickname);
    }
    $pdo->prepare("UPDATE `$table` SET is_anon = ?, nickname = ? WHERE id = ?")
        ->execute([$anon ? 1 : 0, $nickname, $id]);
    return $nickname;
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
// ---------- 上传图片自动压缩：只压图片，尽量不影响画质 ----------
// 等比缩到最长边 2000px（资料图 2400px），JPEG 质量 86 / WebP 88 / PNG 无损重编码。
// 只有体积确实变小才替换；GIF 动图、文档类文件、解码失败一律原样保留，绝不影响上传。
function img_compress_cfg()
{
    return ['edge' => 2000, 'jpeg' => 86, 'webp' => 88, 'png' => 6,
            'min_bytes' => 60 * 1024, 'max_pixels' => 30000000];
}

// 服务器没装 exif 扩展，这里直接读 JPEG 的 EXIF 方向标记（1~8），避免手机竖拍照片压完躺倒
function img_jpeg_orientation($path)
{
    $buf = @file_get_contents($path, false, null, 0, 65536);
    if ($buf === false || ($p = strpos($buf, "Exif\0\0")) === false) return 1;
    $t = $p + 6;
    $le = substr($buf, $t, 2) === 'II';
    if (!$le && substr($buf, $t, 2) !== 'MM') return 1;
    $u16 = function ($o) use ($buf, $le) {
        return $o + 2 <= strlen($buf) ? unpack($le ? 'v' : 'n', substr($buf, $o, 2))[1] : 0;
    };
    $u32 = function ($o) use ($buf, $le) {
        return $o + 4 <= strlen($buf) ? unpack($le ? 'V' : 'N', substr($buf, $o, 4))[1] : 0;
    };
    $ifd = $t + $u32($t + 4);
    $n = min($u16($ifd), 80);
    for ($i = 0; $i < $n; $i++) {
        $e = $ifd + 2 + $i * 12;
        if ($e + 12 > strlen($buf)) break;
        if ($u16($e) === 0x0112) {
            $v = $u16($e + 8);
            return ($v >= 1 && $v <= 8) ? $v : 1;
        }
    }
    return 1;
}

function img_apply_orientation($im, $ori)
{
    if ($ori < 2 || $ori > 8) return $im;
    $rot = function ($im, $deg) {
        $new = @imagerotate($im, $deg, 0);
        if ($new) { imagedestroy($im); return $new; }
        return $im;
    };
    switch ($ori) {
        case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 3: $im = $rot($im, 180); break;
        case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
        case 5: $im = $rot($im, -90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 6: $im = $rot($im, -90); break;
        case 7: $im = $rot($im, 90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 8: $im = $rot($im, 90); break;
    }
    return $im;
}

function img_compress($path, $edge = 0)
{
    if (!is_file($path) || !function_exists('imagecreatetruecolor')) return false;
    $cfg = img_compress_cfg();
    if ($edge > 0) $cfg['edge'] = $edge;
    $before = @filesize($path);
    if (!$before || $before < $cfg['min_bytes']) return false;
    $info = @getimagesize($path);
    if (!is_array($info) || !isset($info[2])) return false;
    $type = $info[2];
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return false;
    if ($type === IMAGETYPE_WEBP && !function_exists('imagecreatefromwebp')) return false;
    if ($info[0] * $info[1] > $cfg['max_pixels']) return false; // 超大图跳过，避免内存溢出

    $tmp = $path . '.cmp_' . substr(md5($path . microtime(true) . mt_rand()), 0, 10);
    $im = null;
    try {
        if ($type === IMAGETYPE_JPEG) $im = @imagecreatefromjpeg($path);
        elseif ($type === IMAGETYPE_PNG) $im = @imagecreatefrompng($path);
        else $im = @imagecreatefromwebp($path);
        if (!$im) throw new Exception('decode');

        $w = imagesx($im); $h = imagesy($im);
        if (max($w, $h) > $cfg['edge']) {
            $r = $cfg['edge'] / max($w, $h);
            $nw = max(1, (int) round($w * $r));
            $nh = max(1, (int) round($h * $r));
            $small = imagecreatetruecolor($nw, $nh);
            if (!$small) throw new Exception('canvas');
            if ($type !== IMAGETYPE_JPEG) { imagealphablending($small, false); imagesavealpha($small, true); }
            if (!imagecopyresampled($small, $im, 0, 0, 0, 0, $nw, $nh, $w, $h)) throw new Exception('resize');
            imagedestroy($im);
            $im = $small;
        }
        if ($type === IMAGETYPE_JPEG) $im = img_apply_orientation($im, img_jpeg_orientation($path));

        if ($type === IMAGETYPE_JPEG) {
            @imageinterlace($im, 1); // 渐进式 JPEG：体积更小，画质不变
            $ok = @imagejpeg($im, $tmp, $cfg['jpeg']);
        } elseif ($type === IMAGETYPE_PNG) {
            imagealphablending($im, false); imagesavealpha($im, true);
            $ok = @imagepng($im, $tmp, $cfg['png']);
        } else {
            imagealphablending($im, false); imagesavealpha($im, true);
            $ok = @imagewebp($im, $tmp, $cfg['webp']);
        }
        imagedestroy($im);
        $im = null;
        if (!$ok || !is_file($tmp) || filesize($tmp) < 1) throw new Exception('save');
        $after = filesize($tmp);
        if ($after >= $before * 0.97) { @unlink($tmp); return false; } // 省不到 3% 就别动原图
        if (!@rename($tmp, $path)) throw new Exception('replace');
        @chmod($path, 0644);
        @error_log('[img-compress] ' . basename($path) . " {$w}x{$h} {$before}->{$after}");
        return true;
    } catch (Throwable $e) {
        if ($im) @imagedestroy($im);
        @unlink($tmp);
        @error_log('[img-compress] skip ' . basename($path) . ': ' . $e->getMessage());
        return false;
    }
}

function image_max_size()
{
    return 10 * 1024 * 1024; // 帖子配图上限 10MB
}
function handle_upload($file)
{
    if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] < 1 || $file['size'] > image_max_size()) return false;
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $allowed[IMAGETYPE_WEBP] = 'webp';
    // exif 扩展并非必需：优先 exif_imagetype，缺失时退回 getimagesize 读取真实图片头
    $type = false;
    if (function_exists('exif_imagetype')) $type = @exif_imagetype($file['tmp_name']);
    if ($type === false && function_exists('getimagesize')) {
        $info = @getimagesize($file['tmp_name']);
        $type = is_array($info) && isset($info[2]) ? $info[2] : false;
    }
    if ($type === false || !isset($allowed[$type])) return false;
    $ext = $allowed[$type];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = UPLOAD_DIR . $name;
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;
    img_compress($dest); // 存盘后自动压缩一轮，失败不影响上传
    return $name;
}
function handle_material_upload($file)
{
    if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] < 1 || $file['size'] > 30 * 1024 * 1024) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allow = ['doc', 'docx', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];
    if (!in_array($ext, $allow, true)) return false;
    $dir = UPLOAD_DIR . 'materials/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) return false;
    // 只对图片压缩；文档 / PDF / 压缩包 / 视频保持原样，避免二次压缩损坏
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) img_compress($dir . $stored, 2400);
    return ['stored' => $stored, 'orig' => $file['name'],
            'size' => @filesize($dir . $stored) ?: $file['size'], 'ext' => $ext];
}
// 删除评论 / 帖子时清理评论附带图片，避免 /uploads/ 留下孤儿文件
function delete_upload_image($name)
{
    if (!is_string($name) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $name)) return;
    $path = UPLOAD_DIR . $name;
    if (is_file($path)) @unlink($path);
}
function delete_comment_images($pdo, $postId)
{
    $st = $pdo->prepare('SELECT image FROM comments WHERE post_id = ? AND image IS NOT NULL');
    $st->execute([$postId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $img) delete_upload_image($img);
}

// ---------- 学习链接图标（后台上传，前台只读静态文件）----------
function link_icon_dir()
{
    return UPLOAD_DIR . 'linkicons/';
}
function link_icon_name_ok($name)
{
    return is_string($name) && preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $name) === 1;
}
function delete_link_icon($name)
{
    if (!link_icon_name_ok($name)) return;
    $path = link_icon_dir() . $name;
    if (is_file($path)) @unlink($path);
}
function link_icon_url($name)
{
    if (!link_icon_name_ok($name) || !is_file(link_icon_dir() . $name)) return '';
    return '/uploads/linkicons/' . $name;
}
// 上传后未保存 / 已取消的临时图标，超过 1 小时自动回收（每次上传时顺带执行）
function cleanup_orphan_link_icons($pdo)
{
    $dir = link_icon_dir();
    if (!is_dir($dir)) return;
    $used = [];
    foreach ($pdo->query('SELECT icon_image FROM study_links WHERE icon_image IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $n) {
        if (link_icon_name_ok($n)) $used[$n] = true;
    }
    foreach (glob($dir . '*') ?: [] as $f) {
        if (!is_file($f)) continue;
        $n = basename($f);
        if (!link_icon_name_ok($n) || isset($used[$n])) continue;
        if (@filemtime($f) < time() - 3600) @unlink($f);
    }
}
function handle_link_icon_upload($file)
{
    if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] < 1 || $file['size'] > 2 * 1024 * 1024) return null; // 图标上限 2MB
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $allowed[constant('IMAGETYPE_WEBP')] = 'webp';
    $info = @getimagesize($file['tmp_name']); // 读真实图片头，扩展名不可信
    $t = is_array($info) && isset($info[2]) ? $info[2] : 0;
    if (!$t || !isset($allowed[$t])) return null;
    if (!isset($info[0], $info[1]) || $info[0] * $info[1] > 20000000) return null;
    $dir = link_icon_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$t];
    $dest = $dir . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    @chmod($dest, 0644);

    // 统一裁成 256×256 居中方形 PNG（保留透明）；GD 不可用或解码失败时保留原图
    if (function_exists('imagecreatetruecolor')) {
        $src = null;
        if ($t === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($dest);
        elseif ($t === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($dest);
        elseif (defined('IMAGETYPE_GIF') && $t === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) $src = @imagecreatefromgif($dest);
        elseif (defined('IMAGETYPE_WEBP') && $t === constant('IMAGETYPE_WEBP') && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($dest);
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $side = min($w, $h);
            $sx = (int) (($w - $side) / 2); $sy = (int) (($h - $side) / 2);
            $canvas = @imagecreatetruecolor(256, 256);
            if ($canvas) {
                imagealphablending($canvas, false); imagesavealpha($canvas, true);
                $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, 256, 256, $clear);
                if (@imagecopyresampled($canvas, $src, 0, 0, $sx, $sy, 256, 256, $side, $side)) {
                    $png = $dir . bin2hex(random_bytes(16)) . '.png';
                    if (@imagepng($canvas, $png, 6) && is_file($png)) {
                        @chmod($png, 0644);
                        @unlink($dest);
                        $name = basename($png);
                    } else @unlink($png);
                }
                imagedestroy($canvas);
            }
            imagedestroy($src);
        }
    }
    return $name;
}

// ---------- 路由 ----------
$action = $_REQUEST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 请求体超过 post_max_size 时 PHP 会丢弃整个 POST（$_POST/$_FILES 全空），给出明确提示
if ($action === '' && $method === 'POST' && intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_out(['ok' => false, 'msg' => '上传内容过大或被截断，请压缩后重试（图片 ≤10MB，资料 ≤30MB）'], 400);
}

// ===== 全站发布规则：所有会产生公开内容的写操作一律要求登录 =====
// 新增发布类接口时，只需把 action 加进这个数组，门禁自动生效；点赞/举报等轻互动不在此列
$publish_actions = ['create', 'comment_add', 'material_upload'];
$pub_user = null;
if (in_array($action, $publish_actions, true)) {
    $pub_user = require_user($pdo);
    if (!$pub_user) {
        json_out(['ok' => false, 'msg' => '请先登录后再发布（登录后仍可选择匿名）', 'need_login' => true], 401);
    }
}

switch ($action) {

    // ===== 帖子：列表（校园墙；支持分类与关键词）=====
    case 'list':
        $page = max(1, intval($_GET['page'] ?? 1));
        $per = 10;
        $offset = ($page - 1) * $per;
        // board 为历史字段，站点仅保留校园墙（0），忽略外部传入值
        $params = [];
        $where = 'WHERE p.status = 1 AND p.board = 0';
        $cat = trim((string) ($_GET['category'] ?? ''));
        if ($cat !== '' && in_array($cat, post_categories(), true)) {
            $where .= ' AND p.category = :cat';
            $params[':cat'] = $cat;
        }
        // LOCATE 做子串匹配，天然避免 LIKE 通配符转义问题，参数全程绑定
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
        if ($q !== '') {
            $where .= ' AND (LOCATE(:q, p.content) > 0 OR LOCATE(:q, p.title) > 0 OR LOCATE(:q, p.nickname) > 0)';
            $params[':q'] = $q;
        }
        // 首页排序切换：latest 最新（优质置顶，默认）/ hot 按点赞 / featured 只看精选
        $sort = trim((string) ($_GET['sort'] ?? 'latest'));
        if (!in_array($sort, ['latest', 'hot', 'featured'], true)) $sort = 'latest';
        if ($sort === 'featured') $where .= ' AND p.featured = 1';
        if ($sort === 'hot') {
            $order = ' ORDER BY p.likes DESC, p.created_at DESC, p.id DESC';
        } elseif ($sort === 'featured') {
            $order = ' ORDER BY p.featured_at DESC, p.created_at DESC, p.id DESC';
        } else {
            $order = ' ORDER BY p.featured DESC, p.created_at DESC, p.id DESC';
        }
        $stmt = $pdo->prepare(
            'SELECT p.*, (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
             FROM posts p ' . $where . $order . '
             LIMIT ' . intval($per) . ' OFFSET ' . intval($offset)
        );
        $stmt->execute($params);
        $posts = public_posts($pdo, $stmt->fetchAll());
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM posts p ' . $where);
        $cnt->execute($params);
        $total = $cnt->fetchColumn();
        json_out(['ok' => true, 'posts' => $posts, 'page' => $page, 'per' => $per,
                  'sort' => $sort, 'total' => (int) $total]);
        break;

    // ===== 优质内容：公开列表（首页顶部独立板块）=====
    // board >= 0 取指定板块；board < 0 取全部（后台管理用）
    case 'featured_list':
        $board = intval($_GET['board'] ?? 0);
        if ($board > 0) $board = 0; // 站点仅保留校园墙
        $whereBoard = $board >= 0 ? ' AND p.board = ' . $board : '';
        // board < 0 为后台管理入口：仅管理员登录时附带作者信息，公开访问一律脱敏
        $adm = require_admin($pdo);
        $adminMode = ($adm && $board < 0);
        $authorSelect = $adminMode ? ', u.username AS author_username, u.nickname AS author_nickname' : '';
        $authorJoin = $adminMode ? ' LEFT JOIN users u ON u.id = p.user_id' : '';
        $stmt = $pdo->query(
            'SELECT p.*' . $authorSelect . ', (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
             FROM posts p' . $authorJoin . '
             WHERE p.status = 1 AND p.featured = 1' . $whereBoard . '
             ORDER BY p.featured_at DESC, p.id DESC LIMIT 12'
        );
        $rows = $stmt->fetchAll();
        json_out(['ok' => true, 'posts' => $adminMode ? $rows : public_posts($pdo, $rows)]);
        break;

    // ===== 热门排行：按点赞数排名（公开、只读；过滤与排序都下推到 SQL）=====
    case 'hot_rank':
        $limit = intval($_GET['limit'] ?? 10);
        if ($limit < 1) $limit = 10;
        if ($limit > 20) $limit = 20;
        $stmt = $pdo->query(
            'SELECT p.*, (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
             FROM posts p
             WHERE p.status = 1 AND p.board = 0 AND p.likes > 0
             ORDER BY p.likes DESC, p.created_at DESC, p.id DESC
             LIMIT ' . $limit
        );
        json_out(['ok' => true, 'posts' => public_posts($pdo, $stmt->fetchAll())]);
        break;

    // ===== 帖子：发布（进入待审核）=====
    case 'create':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $board = 0; // 站点仅保留校园墙
        $cat = trim((string) ($_POST['category'] ?? 'other'));
        if (!in_array($cat, post_categories(), true)) $cat = 'other';
        $title = null;
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($content) < 1 || mb_strlen($content) > 500) json_out(['ok' => false, 'msg' => '内容需在 1-500 字之间'], 400);
        $nicknameRaw = trim($_POST['nickname'] ?? '');
        $anon = isset($_POST['anonymous']) && $_POST['anonymous'] === '1';
        $user = $pub_user; // 统一发布门禁已保证登录；这里只决定前台显示名
        if ($anon) {
            $nickname = '匿名'; // 前台只显示「匿名」
        } else {
            $nickname = $nicknameRaw !== '' ? mb_substr($nicknameRaw, 0, 50) : $user['nickname'];
        }
        $uid = $user['id']; // 管理员后台据此溯源；公开接口已剔除该字段
        $isAnon = $anon ? 1 : 0;
        $image = null;
        if (!empty($_FILES['image'])) {
            $f = $_FILES['image'];
            if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
                json_out(['ok' => false, 'msg' => '图片上传失败（错误码 ' . ($f['error'] ?? -1) . '），请压缩后重试'], 400);
            }
            if ($f['size'] > image_max_size()) json_out(['ok' => false, 'msg' => '图片不能超过 10MB，请压缩后重试'], 400);
            $image = handle_upload($f);
            if ($image === false) json_out(['ok' => false, 'msg' => '图片格式不支持或文件损坏，请使用 jpg/png/gif/webp'], 400);
        }
        $nickname = filter_bad_words($pdo, $nickname);
        $title = $title === null ? null : filter_bad_words($pdo, $title);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO posts (nickname,title,content,image,status,user_id,board,category,ip,is_anon) VALUES (?,?,?,?,0,?,?,?,?,?)')
            ->execute([$nickname, $title, $content, $image, $uid, $board, $cat, get_client_ip(), $isAnon]);
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

    // ===== 评论：列表（仅展示已通过审核；含楼中楼层级与点赞数）=====
    case 'comments':
        $id = intval($_GET['post_id'] ?? 0);
        $ip = get_client_ip();
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) AS like_count,
                    (SELECT COUNT(*) FROM comment_likes cl2 WHERE cl2.comment_id = c.id AND cl2.ip = :ip) AS liked
             FROM comments c WHERE c.post_id = :pid AND c.status = 1
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute([':ip' => $ip, ':pid' => $id]);
        json_out(['ok' => true, 'comments' => public_comments($stmt->fetchAll())]);
        break;

    // ===== 评论：点赞（按 IP 去重，可再次点击取消；访客也能点）=====
    case 'comment_like':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $cid = intval($_POST['comment_id'] ?? 0);
        $ip = get_client_ip();
        $c = $pdo->prepare('SELECT id FROM comments WHERE id = ? AND status = 1');
        $c->execute([$cid]);
        if (!$c->fetch()) json_out(['ok' => false, 'msg' => '评论不存在或尚未通过审核'], 400);
        $chk = $pdo->prepare('SELECT 1 FROM comment_likes WHERE comment_id = ? AND ip = ?');
        $chk->execute([$cid, $ip]);
        if ($chk->fetch()) {
            $pdo->prepare('DELETE FROM comment_likes WHERE comment_id = ? AND ip = ?')->execute([$cid, $ip]);
            $liked = 0;
        } else {
            $pdo->prepare('INSERT INTO comment_likes (comment_id, ip) VALUES (?, ?)')->execute([$cid, $ip]);
            $liked = 1;
        }
        $lc = $pdo->prepare('SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?');
        $lc->execute([$cid]);
        json_out(['ok' => true, 'likes' => (int) $lc->fetchColumn(), 'liked' => $liked]);
        break;

    // ===== 评论：发表（进入待审核，审核通过后才公开展示）=====
    case 'comment_add':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($content) > 200) json_out(['ok' => false, 'msg' => '评论不能超过 200 字'], 400);
        // 评论可带一张图片，也可纯图片（文字为空）；格式/大小沿用发帖的校验与自动压缩
        $imgFile = $_FILES['image'] ?? null;
        $imgErr = is_array($imgFile) && isset($imgFile['error']) ? (int) $imgFile['error'] : UPLOAD_ERR_NO_FILE;
        if ($imgErr !== UPLOAD_ERR_OK && $imgErr !== UPLOAD_ERR_NO_FILE) {
            json_out(['ok' => false, 'msg' => '图片上传失败（错误码 ' . $imgErr . '），请压缩后重试'], 400);
        }
        $hasImage = ($imgErr === UPLOAD_ERR_OK);
        if ($content === '' && !$hasImage) json_out(['ok' => false, 'msg' => '请写点内容或选一张图片'], 400);
        $nicknameRaw = trim($_POST['nickname'] ?? '');
        $anon = isset($_POST['anonymous']) && $_POST['anonymous'] === '1';
        $post = $pdo->prepare('SELECT id, user_id FROM posts WHERE id = ? AND status = 1');
        $post->execute([$id]);
        $prow = $post->fetch();
        if (!$prow) json_out(['ok' => false, 'msg' => '帖子不存在或未通过审核'], 400);
        // 楼中楼：parent_id 指向被回复评论；只做一层，回复某条回复时归到其所属顶层评论
        $parentId = intval($_POST['parent_id'] ?? 0);
        $replyTo = null;
        if ($parentId > 0) {
            $pst = $pdo->prepare('SELECT id, parent_id, nickname, post_id, status FROM comments WHERE id = ?');
            $pst->execute([$parentId]);
            $target = $pst->fetch();
            if (!$target || (int) $target['post_id'] !== $id || (int) $target['status'] !== 1) {
                json_out(['ok' => false, 'msg' => '要回复的评论不存在'], 400);
            }
            $replyTo = mb_substr((string) $target['nickname'], 0, 50);
            $parentId = ((int) $target['parent_id'] > 0) ? (int) $target['parent_id'] : (int) $target['id'];
        }
        $user = $pub_user; // 统一发布门禁已保证登录
        if ($anon) {
            $nickname = '匿名';
        } else {
            $nickname = $nicknameRaw !== '' ? mb_substr($nicknameRaw, 0, 50) : $user['nickname'];
        }
        $cuid = $user['id'];
        $isAnon = $anon ? 1 : 0;
        $image = null;
        if ($hasImage) {
            if ($imgFile['size'] > image_max_size()) json_out(['ok' => false, 'msg' => '评论图片不能超过 10MB，请压缩后重试'], 400);
            $image = handle_upload($imgFile);
            if ($image === false) json_out(['ok' => false, 'msg' => '图片格式不支持或文件损坏，请使用 jpg/png/gif/webp'], 400);
        }
        $nickname = filter_bad_words($pdo, $nickname);
        $content = filter_bad_words($pdo, $content);
        $pdo->prepare('INSERT INTO comments (post_id, parent_id, reply_to, nickname, content, image, user_id, ip, status, is_anon)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$id, $parentId, $replyTo, $nickname, $content, $image, $cuid, get_client_ip(), $isAnon]);
        // 评论需审核，审核通过后再通知帖子作者 / 被回复者（见 admin_comment_approve）
        json_out(['ok' => true, 'msg' => $parentId ? '回复已提交，审核通过后将公开显示' : '评论已提交，审核通过后将公开展示']);
        break;

    // ===== 评论：后台审核列表 =====
    case 'admin_comment_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $status = isset($_GET['status']) ? intval($_GET['status']) : 0;
        $stmt = $pdo->prepare('SELECT c.*, p.title AS post_title, p.board,
                    u.username AS author_username, u.nickname AS author_nickname
                    FROM comments c JOIN posts p ON p.id = c.post_id
                    LEFT JOIN users u ON u.id = c.user_id
                    WHERE c.status = ? ORDER BY c.created_at DESC');
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
        if ($st === 1) {
            $excerpt = trim((string) $row['content']) !== '' ? mb_substr($row['content'], 0, 30) : '[图片评论]';
            $replyUid = 0;
            // 楼中楼：审核通过后提醒被回复者（@ 提醒），自己回复自己不打扰
            if ((int) ($row['parent_id'] ?? 0) > 0) {
                $pu = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
                $pu->execute([$row['parent_id']]);
                $replyUid = (int) $pu->fetchColumn();
                if ($replyUid && $replyUid !== (int) ($row['user_id'] ?? 0)) {
                    add_notification($pdo, $replyUid, 'reply', $row['post_id'], '有人回复了你的评论：' . $excerpt);
                }
            }
            // 帖子作者仍收到新评论提醒；同一人已是回复对象时不重复发
            if ($row['post_uid'] && (int) $row['post_uid'] !== $replyUid) {
                add_notification($pdo, $row['post_uid'], 'comment', $row['post_id'], '你的内容有一条新评论通过审核：' . $excerpt);
            }
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
        // 注册：图形验证码 + 邀请码；登录只需要图形验证码，不需要邀请码
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
        // 敏感字段一律不留在用户数组里，杜绝后续误把整行带入响应
        unset($u['password'], $u['admin_note']);
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
        json_out(['ok' => true, 'id' => (int) $user['id'], 'username' => $user['username'], 'nickname' => $user['nickname']]);
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
        // 论坛 / 问答已下线，保留空数组以兼容旧版缓存页面
        json_out(['ok' => true, 'posts' => $posts, 'materials' => $mats, 'questions' => [], 'topics' => []]);
        break;

    // ===== 用户：收藏 / 取消收藏（仅登录本人，帖子须存在）=====
    case 'favorite_toggle':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录后收藏', 'need_login' => true], 401);
        $pid = intval($_POST['post_id'] ?? 0);
        if ($pid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $st = $pdo->prepare('SELECT id FROM posts WHERE id = ?');
        $st->execute([$pid]);
        if (!$st->fetch()) json_out(['ok' => false, 'msg' => '内容不存在'], 404);
        $uid = (int) $user['id'];
        $chk = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND post_id = ?');
        $chk->execute([$uid, $pid]);
        if ($chk->fetch()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND post_id = ?')->execute([$uid, $pid]);
            json_out(['ok' => true, 'favorited' => 0, 'msg' => '已取消收藏']);
        }
        try {
            $pdo->prepare('INSERT INTO favorites (user_id, post_id) VALUES (?, ?)')->execute([$uid, $pid]);
        } catch (Throwable $e) {
            // 唯一键冲突（并发重复提交）视为已收藏，保持幂等
            json_out(['ok' => true, 'favorited' => 1, 'msg' => '已收藏']);
        }
        json_out(['ok' => true, 'favorited' => 1, 'msg' => '已收藏']);
        break;

    // ===== 用户：我的收藏（JOIN 已通过审核的帖子，自动忽略已删除内容）=====
    case 'favorite_list':
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $uid = (int) $user['id'];
        $st = $pdo->prepare(
            'SELECT p.*, f.created_at AS favorited_at FROM favorites f
             JOIN posts p ON p.id = f.post_id
             WHERE f.user_id = ? AND p.status = 1
             ORDER BY f.created_at DESC, f.id DESC LIMIT 100'
        );
        $st->execute([$uid]);
        $rows = public_posts($pdo, $st->fetchAll());
        json_out(['ok' => true, 'list' => $rows, 'total' => count($rows)]);
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

    // ===== 学习专区：免登录公开链接（游客可读，点击直达外部网站）=====
    case 'study_links':
        $rows = $pdo->query('SELECT id, title, url, description, icon, icon_image, sort FROM study_links WHERE status = 1 ORDER BY sort ASC, id DESC')->fetchAll();
        // 即使库里被写入了非法网址，也绝不外抛给前台；图标只回传校验过的静态路径
        foreach ($rows as &$r) {
            if (!validate_study_url($r['url'])) $r['url'] = '';
            $r['icon_url'] = link_icon_url($r['icon_image']);
            unset($r['icon_image']);
        }
        unset($r);
        json_out(['ok' => true, 'links' => $rows]);
        break;

    // 公开点击计数：轻量写入，不产生内容、不需要登录
    case 'study_link_click':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $lid = intval($_POST['id'] ?? 0);
        if ($lid > 0) {
            $pdo->prepare('UPDATE study_links SET clicks = clicks + 1 WHERE id = ? AND status = 1')->execute([$lid]);
        }
        json_out(['ok' => true]);
        break;

    // ===== 资料：分类列表 =====
    case 'material_categories':
        $rows = $pdo->query('SELECT * FROM material_categories ORDER BY sort ASC, id ASC')->fetchAll();
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
        if ($up === false) json_out(['ok' => false, 'msg' => '文件需为常见格式且 ≤30MB'], 400);
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
        $sel = 'SELECT p.*, u.username AS author_username, u.nickname AS author_nickname
                FROM posts p LEFT JOIN users u ON u.id = p.user_id';
        if ($status !== null && in_array($status, [0, 1, 2], true)) {
            $stmt = $pdo->prepare($sel . ' WHERE p.status = ? ORDER BY p.created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $pdo->query($sel . ' ORDER BY p.created_at DESC');
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
        // 被拒绝的内容不再参与优质置顶，避免脏数据
        if ($st === 2) {
            $pdo->prepare('UPDATE posts SET status = ?, featured = 0, featured_at = NULL WHERE id = ?')->execute([$st, $id]);
        } else {
            $pdo->prepare('UPDATE posts SET status = ? WHERE id = ?')->execute([$st, $id]);
        }
        $p = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
        $p->execute([$id]);
        $row = $p->fetch();
        if ($row && $row['user_id']) {
            $label = '校园墙内容';
            $msg = $st === 1 ? "你的{$label}已通过审核" : "你的{$label}未通过审核";
            add_notification($pdo, $row['user_id'], 'post_' . ($st === 1 ? 'approved' : 'rejected'), $id, $msg);
        }
        json_out(['ok' => true]);
        break;

    // ===== 后台：设为 / 取消优质内容（前台自动置顶）=====
    case 'admin_post_feature':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['post_id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $on = intval($_POST['value'] ?? 1) === 1 ? 1 : 0;
        $chk = $pdo->prepare('SELECT id FROM posts WHERE id = ?');
        $chk->execute([$id]);
        if (!$chk->fetch()) json_out(['ok' => false, 'msg' => '内容不存在'], 404);
        if ($on) {
            // 一键认可：自动通过审核，并立即置顶到前台优质内容区
            $pdo->prepare('UPDATE posts SET featured = 1, featured_at = NOW(), status = 1 WHERE id = ?')->execute([$id]);
        } else {
            $pdo->prepare('UPDATE posts SET featured = 0, featured_at = NULL WHERE id = ?')->execute([$id]);
        }
        json_out(['ok' => true, 'featured' => $on, 'msg' => $on ? '已设为优质，前台自动置顶' : '已取消置顶']);
        break;

    // ===== 后台：帖子删除 =====
    case 'admin_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['post_id'] ?? 0);
        delete_comment_images($pdo, $id); // 级联删评论前先清评论配图
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

    // ===== 用户：删除自己发布的内容 =====
    case 'post_delete_own':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $user = require_user($pdo);
        if (!$user) json_out(['ok' => false, 'msg' => '请先登录'], 401);
        $id = intval($_POST['post_id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $st = $pdo->prepare('SELECT id, user_id FROM posts WHERE id = ?');
        $st->execute([$id]);
        $post = $st->fetch();
        if (!$post || (int) $post['user_id'] !== (int) $user['id']) {
            json_out(['ok' => false, 'msg' => '只能删除自己发布的内容'], 403);
        }
        $imgSt = $pdo->prepare('SELECT image FROM comments WHERE post_id = ? AND image IS NOT NULL');
        $imgSt->execute([$id]);
        $commentImages = $imgSt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM post_likes WHERE post_id = ?')->execute([$id]);
        try { $pdo->prepare('DELETE FROM reports WHERE post_id = ?')->execute([$id]); } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM comments WHERE post_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        $pdo->commit();
        foreach ($commentImages as $img) delete_upload_image($img);
        json_out(['ok' => true, 'msg' => '已删除']);
        break;

    // ===== 举报：提交（可不登录，按 IP 限频）=====
    case 'report_add':
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['post_id'] ?? 0);
        $ip = get_client_ip();
        $chk = $pdo->prepare('SELECT id FROM posts WHERE id = ? AND status = 1');
        $chk->execute([$id]);
        if (!$chk->fetch()) json_out(['ok' => false, 'msg' => '内容不存在或已下架'], 400);
        $recent = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE post_id = ? AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $recent->execute([$id, $ip]);
        if ((int) $recent->fetchColumn() > 0) json_out(['ok' => false, 'msg' => '这条内容你已经举报过啦，管理员会处理'], 400);
        $burst = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $burst->execute([$ip]);
        if ((int) $burst->fetchColumn() >= 3) json_out(['ok' => false, 'msg' => '举报太频繁了，请稍后再试'], 429);
        $reason = filter_bad_words($pdo, mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200));
        $user = require_user($pdo);
        $pdo->prepare('INSERT INTO reports (post_id, user_id, ip, reason, status) VALUES (?,?,?,?,0)')
            ->execute([$id, $user ? $user['id'] : null, $ip, $reason]);
        json_out(['ok' => true, 'msg' => '举报已提交，管理员会尽快处理，谢谢！']);
        break;

    // ===== 后台：举报列表 =====
    case 'admin_report_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $status = intval($_GET['status'] ?? 0);
        if (!in_array($status, [0, 1, 2], true)) $status = 0;
        $stmt = $pdo->prepare(
            'SELECT r.*, LEFT(p.content, 80) AS excerpt, p.board, p.status AS post_status, p.nickname,
                    u.username AS author_username, u.nickname AS author_nickname
             FROM reports r LEFT JOIN posts p ON p.id = r.post_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 100'
        );
        $stmt->execute([$status]);
        json_out(['ok' => true, 'reports' => $stmt->fetchAll()]);
        break;

    // ===== 后台：处理举报（1=下架内容并处理 2=忽略）=====
    case 'admin_report_handle':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $value = intval($_POST['value'] ?? 0);
        if ($id <= 0 || !in_array($value, [1, 2], true)) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $st = $pdo->prepare('SELECT id, post_id, status FROM reports WHERE id = ?');
        $st->execute([$id]);
        $rep = $st->fetch();
        if (!$rep) json_out(['ok' => false, 'msg' => '举报不存在'], 404);
        if ((int) $rep['status'] !== 0) json_out(['ok' => false, 'msg' => '这条举报已处理过了'], 400);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE reports SET status = ?, admin_id = ?, handled_at = NOW() WHERE id = ?')
            ->execute([$value, (int) $adm['admin_id'], $id]);
        if ($value === 1) {
            $pdo->prepare('UPDATE posts SET status = 2, featured = 0, featured_at = NULL WHERE id = ?')
                ->execute([(int) $rep['post_id']]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'msg' => $value === 1 ? '已下架内容并标记处理' : '已忽略该举报']);
        break;

    // ===== 后台：统计 =====
    case 'admin_stats':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        // 扩展统计用容错查询，新增表/字段缺失时降级为 0，不影响原有仪表盘
        $safeCount = static function ($sql) use ($pdo) {
            try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $trend = [];
        try {
            $map = [];
            foreach ($pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM posts
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(created_at)")->fetchAll() as $r) {
                $map[$r['d']] = (int) $r['n'];
            }
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime('-' . $i . ' days'));
                $trend[] = ['date' => substr($d, 5), 'count' => $map[$d] ?? 0];
            }
        } catch (Throwable $e) { $trend = []; }
        try {
            $top = $pdo->query("SELECT id, board, LEFT(content, 60) AS excerpt, likes FROM posts
                WHERE status = 1 ORDER BY likes DESC, id DESC LIMIT 5")->fetchAll();
        } catch (Throwable $e) { $top = []; }
        json_out(['ok' => true, 'stats' => [
            'pending'   => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 0")->fetchColumn(),
            'approved'  => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 1")->fetchColumn(),
            'rejected'  => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 2")->fetchColumn(),
            'mat_pending' => (int) $pdo->query("SELECT COUNT(*) FROM materials WHERE status = 0")->fetchColumn(),
            'mat_approved'=> (int) $pdo->query("SELECT COUNT(*) FROM materials WHERE status = 1")->fetchColumn(),
            'comments'  => (int) $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn(),
            'users'     => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'today_posts'    => $safeCount('SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURDATE()'),
            'today_comments' => $safeCount('SELECT COUNT(*) FROM comments WHERE DATE(created_at) = CURDATE()'),
            'today_users'    => $safeCount('SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()'),
            'week_posts'     => $safeCount('SELECT COUNT(*) FROM posts WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)'),
            'reports'        => $safeCount('SELECT COUNT(*) FROM reports WHERE status = 0'),
            'reports_all'    => $safeCount('SELECT COUNT(*) FROM reports'),
            'materials'      => $safeCount('SELECT COUNT(*) FROM materials'),
            'featured'       => $safeCount('SELECT COUNT(*) FROM posts WHERE featured = 1 AND status = 1'),
            'trend'          => $trend,
            'top_posts'      => $top,
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
            "SELECT u.id, u.username, u.nickname, u.created_at, u.status, u.banned_at, u.ban_reason, u.last_ip, u.admin_note,
                    (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS cnt_posts,
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
        $posts = $pdo->prepare("SELECT 'post' AS type, id, title, content, nickname, is_anon, NULL AS image, category, status, created_at, ip FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $posts->execute([$uid]);
        $materials = $pdo->prepare("SELECT 'material' AS type, id, title AS content, description, NULL AS nickname, 0 AS is_anon, NULL AS image, NULL AS category, status, created_at, ip FROM materials WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $materials->execute([$uid]);
        $comments = $pdo->prepare("SELECT 'comment' AS type, id, content, nickname, is_anon, image, NULL AS title, NULL AS category, NULL AS status, created_at, ip FROM comments WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $comments->execute([$uid]);
        json_out(['ok' => true, 'contents' => array_merge(
            $posts->fetchAll(), $materials->fetchAll(), $comments->fetchAll()
        )]);
        break;

    // ===== 后台：删除评论 =====
    case 'admin_comment_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT image FROM comments WHERE id = ?');
        $st->execute([$id]);
        $img = $st->fetchColumn();
        $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
        delete_upload_image($img);
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

    // ===== 后台：设置用户内部备注（仅后台可见，前台所有接口均不返回） =====
    case 'admin_user_note':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        if (!isset($_POST['note'])) json_out(['ok' => false, 'msg' => '缺少备注内容'], 400);
        $note = trim((string) $_POST['note']);
        if (mb_strlen($note) > 500) json_out(['ok' => false, 'msg' => '备注最多 500 字'], 400);
        $chk = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $chk->execute([$uid]);
        if (!$chk->fetch()) json_out(['ok' => false, 'msg' => '用户不存在'], 404);
        $pdo->prepare('UPDATE users SET admin_note = ? WHERE id = ?')
            ->execute([$note === '' ? null : $note, $uid]);
        json_out(['ok' => true, 'note' => $note]);
        break;

    // ===== 后台：免登录学习链接管理（仅管理员可读写）=====
    case 'admin_study_links':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $rows = $pdo->query('SELECT * FROM study_links ORDER BY sort ASC, id DESC')->fetchAll();
        foreach ($rows as &$r) $r['icon_url'] = link_icon_url($r['icon_image']);
        unset($r);
        json_out(['ok' => true, 'links' => $rows]);
        break;

    case 'admin_study_link_icon_upload':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $iconDir = link_icon_dir();
        if ((!is_dir($iconDir) && !@mkdir($iconDir, 0755, true)) || !is_writable($iconDir)) {
            json_out(['ok' => false, 'msg' => '服务器图标目录不可写，请联系管理员'], 500);
        }
        cleanup_orphan_link_icons($pdo);
        $name = handle_link_icon_upload($_FILES['icon'] ?? null);
        if (!$name) json_out(['ok' => false, 'msg' => '请上传 2MB 以内的 JPG / PNG / GIF / WEBP 图片'], 400);
        json_out(['ok' => true, 'name' => $name, 'url' => '/uploads/linkicons/' . $name]);
        break;

    case 'admin_study_link_save':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $lid = intval($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        if (mb_strlen($title, 'UTF-8') < 2 || mb_strlen($title, 'UTF-8') > 150) json_out(['ok' => false, 'msg' => '名称需 2-150 字'], 400);
        $url = validate_study_url($_POST['url'] ?? '');
        if (!$url) json_out(['ok' => false, 'msg' => '网址需以 http:// 或 https:// 开头且格式正确'], 400);
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
        $icon = mb_substr(trim((string) ($_POST['icon'] ?? '')), 0, 8);
        if ($icon === '') $icon = '🔗';
        // 图标文件名只接受本接口生成的随机名，杜绝路径穿越与引用任意文件
        $iconImage = trim((string) ($_POST['icon_image'] ?? ''));
        if ($iconImage !== '' && (!link_icon_name_ok($iconImage) || !is_file(link_icon_dir() . $iconImage))) {
            json_out(['ok' => false, 'msg' => '图标文件不存在，请重新上传'], 400);
        }
        $dbIcon = $iconImage === '' ? null : $iconImage;
        $sort = intval($_POST['sort'] ?? 0);
        $status = (($_POST['status'] ?? '1') === '0') ? 0 : 1;
        $title = filter_bad_words($pdo, $title);
        $desc = filter_bad_words($pdo, $desc);
        if ($lid > 0) {
            $chk = $pdo->prepare('SELECT id, icon_image FROM study_links WHERE id = ?');
            $chk->execute([$lid]);
            $old = $chk->fetch();
            if (!$old) json_out(['ok' => false, 'msg' => '链接不存在'], 404);
            $pdo->prepare('UPDATE study_links SET title = ?, url = ?, description = ?, icon = ?, icon_image = ?, sort = ?, status = ? WHERE id = ?')
                ->execute([$title, $url, $desc, $icon, $dbIcon, $sort, $status, $lid]);
            if ($old['icon_image'] && $old['icon_image'] !== $dbIcon) delete_link_icon($old['icon_image']);
        } else {
            $pdo->prepare('INSERT INTO study_links (title, url, description, icon, icon_image, sort, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$title, $url, $desc, $icon, $dbIcon, $sort, $status]);
            $lid = (int) $pdo->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $lid, 'icon_url' => link_icon_url($dbIcon), 'msg' => '已保存']);
        break;

    case 'admin_study_link_status':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $lid = intval($_POST['id'] ?? 0);
        $value = (string) ($_POST['value'] ?? '');
        if ($lid <= 0 || !in_array($value, ['0', '1'], true)) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $pdo->prepare('UPDATE study_links SET status = ? WHERE id = ?')->execute([(int) $value, $lid]);
        json_out(['ok' => true, 'msg' => $value === '1' ? '已启用' : '已停用']);
        break;

    case 'admin_study_link_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $lid = intval($_POST['id'] ?? 0);
        if ($lid <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $st = $pdo->prepare('SELECT icon_image FROM study_links WHERE id = ?');
        $st->execute([$lid]);
        $oldIcon = $st->fetchColumn();
        $pdo->prepare('DELETE FROM study_links WHERE id = ?')->execute([$lid]);
        if ($oldIcon) delete_link_icon($oldIcon);
        json_out(['ok' => true, 'msg' => '已删除']);
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
        if ($up === false) json_out(['ok' => false, 'msg' => '文件需为常见格式且 ≤30MB（支持图片/文档/压缩包等）'], 400);
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

    // ===== 后台：一键设置发布内容 / 评论的匿名状态 =====
    case 'admin_anon_set':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        $type = (($_POST['type'] ?? 'post') === 'comment') ? 'comment' : 'post';
        $value = (string) ($_POST['value'] ?? '');
        if ($id <= 0 || !in_array($value, ['0', '1'], true)) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $nick = set_content_anon($pdo, $type, $id, $value === '1', (string) ($_POST['display_name'] ?? ''));
        json_out(['ok' => true, 'nickname' => $nick, 'is_anon' => (int) $value,
                  'msg' => $value === '1' ? '已设为匿名（前台显示「匿名」）' : '已取消匿名（前台显示昵称）']);
        break;

    // ===== 后台：编辑文本内容（帖子 / 评论）=====
    case 'admin_post_edit':
    case 'admin_comment_edit':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $title = filter_bad_words($pdo, trim($_POST['title'] ?? ''));
        $content = filter_bad_words($pdo, trim($_POST['content'] ?? ''));
        $act = $action;
        // 后台编辑弹窗可同时修改投稿分类标签（仅 posts 表有该字段）
        $cat = null;
        if ($act === 'admin_post_edit' && isset($_POST['category'])) {
            $cat = trim((string) $_POST['category']);
            if (!in_array($cat, post_categories(), true)) json_out(['ok' => false, 'msg' => '分类标签无效'], 400);
        }
        if ($act === 'admin_comment_edit') {
            if ($content === '') json_out(['ok' => false, 'msg' => '评论内容不能为空'], 400);
            $pdo->prepare('UPDATE comments SET content = ? WHERE id = ?')->execute([$content, $id]);
        } else {
            $tbl = 'posts';
            // 标题可留空：校园墙帖子本来就没有标题，编辑时只改正文即可
            if ($content === '') json_out(['ok' => false, 'msg' => '正文不能为空'], 400);
            if ($cat !== null) {
                $pdo->prepare('UPDATE posts SET title = ?, content = ?, category = ? WHERE id = ?')->execute([$title, $content, $cat, $id]);
            } else {
                $pdo->prepare("UPDATE `$tbl` SET title = ?, content = ? WHERE id = ?")->execute([$title, $content, $id]);
            }
        }
        // 编辑弹窗里同时可切换匿名状态；未传该参数时保持原状态，兼容旧调用
        if (isset($_POST['anonymous']) && in_array((string) $_POST['anonymous'], ['0', '1'], true)) {
            $ctype = ($act === 'admin_comment_edit') ? 'comment' : 'post';
            set_content_anon($pdo, $ctype, $id, $_POST['anonymous'] === '1', (string) ($_POST['display_name'] ?? ''));
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

    // ===== 更新修复日志：前台公开只读 =====
    case 'changelog_list':
        $limit = min(100, max(1, intval($_GET['limit'] ?? 30)));
        try {
            $rows = $pdo->query('SELECT id, title, content, kind, version, created_at FROM changelog ORDER BY created_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        json_out(['ok' => true, 'list' => $rows]);
        break;

    // ===== 后台：更新修复日志管理（仅管理员）=====
    case 'admin_changelog_list':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        try {
            $rows = $pdo->query('SELECT id, title, content, kind, version, created_at FROM changelog ORDER BY created_at DESC, id DESC LIMIT 200')->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        json_out(['ok' => true, 'list' => $rows]);
        break;

    case 'admin_changelog_add':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '标题需 2-150 字'], 400);
        if (mb_strlen($content) < 1 || mb_strlen($content) > 2000) json_out(['ok' => false, 'msg' => '内容需 1-2000 字'], 400);
        $kind = $_POST['kind'] ?? 'fix';
        if (!in_array($kind, ['fix', 'new', 'improve', 'notice'], true)) $kind = 'fix';
        $version = mb_substr(trim($_POST['version'] ?? ''), 0, 50);
        $pdo->prepare('INSERT INTO changelog (title, content, kind, version, admin_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([filter_bad_words($pdo, $title), filter_bad_words($pdo, $content), $kind, $version, intval($adm['admin_id'])]);
        json_out(['ok' => true, 'msg' => '已发布']);
        break;

    case 'admin_changelog_edit':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) json_out(['ok' => false, 'msg' => '标题需 2-150 字'], 400);
        if (mb_strlen($content) < 1 || mb_strlen($content) > 2000) json_out(['ok' => false, 'msg' => '内容需 1-2000 字'], 400);
        $kind = $_POST['kind'] ?? 'fix';
        if (!in_array($kind, ['fix', 'new', 'improve', 'notice'], true)) $kind = 'fix';
        $version = mb_substr(trim($_POST['version'] ?? ''), 0, 50);
        $pdo->prepare('UPDATE changelog SET title = ?, content = ?, kind = ?, version = ? WHERE id = ?')
            ->execute([filter_bad_words($pdo, $title), filter_bad_words($pdo, $content), $kind, $version, $id]);
        json_out(['ok' => true, 'msg' => '已保存']);
        break;

    case 'admin_changelog_delete':
        $adm = require_admin($pdo);
        if (!$adm) json_out(['ok' => false, 'msg' => '未授权'], 401);
        if ($method !== 'POST') json_out(['ok' => false, 'msg' => '方法错误'], 405);
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'msg' => '参数错误'], 400);
        $pdo->prepare('DELETE FROM changelog WHERE id = ?')->execute([$id]);
        json_out(['ok' => true, 'msg' => '已删除']);
        break;

    default:
        json_out(['ok' => false, 'msg' => '未知操作'], 404);
}
