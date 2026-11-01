<?php
/**
 * lib/bootstrap.php —— 基础设施：常量、错误处理、输入/输出助手、鉴权、CSRF
 *
 * 设计参考 bbs1org（无框架原生 PHP）：
 *   - 鉴权用无状态 HMAC Cookie，签名密钥 = 管理员密码哈希（改密码即吊销所有登录态）
 *   - CSRF 用双提交 Cookie（不依赖 session）
 *   - 所有输入统一走 post_str() / post_int() / post_bool() 过闸
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('AUTH_COOKIE', 'zn_auth');
define('CSRF_COOKIE', 'zn_csrf');
define('FLASH_COOKIE', 'zn_flash');
define('AUTH_TTL', 60 * 60 * 24 * 7);      // 登录态 7 天
define('COOKIE_TTL', 60 * 60 * 24 * 30);
define('MAX_LEN', 2000);                    // 单字段 TEXT 上限
define('UPLOAD_MAX', 5 * 1024 * 1024);      // 图片上传上限 5MB
define('AUDIO_MAX', 20 * 1024 * 1024);      // 音频上传上限 20MB

// 首页音乐的出厂默认值（后台没保存过时兜底，和 index.html 里的静态内容一致）
define('DEFAULT_MUSIC_SRC', 'https://music.163.com/song/media/outer/url?id=2099010964.mp3');
define('DEFAULT_MUSIC_TITLE', '在你的身边');
define('DEFAULT_MUSIC_COVER', 'img/zainideshenbian.jpg');

// 浏览器标签页图标（后台没保存过时用自带的「悟」字图标）
define('DEFAULT_FAVICON', 'img/favicon.svg');

date_default_timezone_set('Asia/Shanghai');

/* mbstring 缺失时降级为按字节处理——足以让安装页跑起来并提示去开启扩展，
   总比直接白屏好。注意：正常情况下不应走到这里。 */
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
} else {
    function mb_strlen(string $s, ?string $enc = null): int { return strlen($s); }
    function mb_substr(string $s, int $start, ?int $len = null, ?string $enc = null): string {
        return $len === null ? substr($s, $start) : substr($s, $start, $len);
    }
    function mb_strimwidth(string $s, int $start, int $width, string $trim = '', ?string $enc = null): string {
        return strlen($s) > $width ? substr($s, $start, $width) . $trim : $s;
    }
}

/* ---------------- 错误处理 ---------------- */
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    $dev = (getenv('APP_DEBUG') === '1');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>出错了</title>'
        . '<div style="font:14px/1.8 system-ui;max-width:640px;margin:80px auto;padding:24px;'
        . 'border:1px solid #eee;border-radius:12px">'
        . '<h1 style="font-size:18px">服务器开小差了</h1>'
        . '<p style="color:#666">请检查 data/ 目录是否可写，或联系开发者。</p>'
        . ($dev ? '<pre style="white-space:pre-wrap;color:#c0392b">' . htmlspecialchars((string)$e, ENT_QUOTES) . '</pre>' : '')
        . '</div>';
    exit;
});

/* ---------------- 输出助手 ---------------- */
/** HTML 转义 */
function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 302 跳转并结束 */
function go(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** 业务错误：直接输出一个可读的错误页 */
function err(string $message, int $code = 400): never
{
    // 接口（fetch）调用时用 JSON 报错，否则前端拿到的是整页 HTML，没法提示
    if (defined('JSON_ERRORS')) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>提示</title>'
        . '<div style="font:14px/1.8 system-ui;max-width:520px;margin:80px auto;padding:24px;'
        . 'border:1px solid #eee;border-radius:12px">'
        . '<h1 style="font-size:17px;margin:0 0 10px">' . h($message) . '</h1>'
        . '<p><a href="javascript:history.back()" style="color:#749efc">返回上一页</a></p></div>';
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ---------------- 安装状态 ----------------
   install.lock 是唯一的"已安装"标记：
   - 没有它 → 前台守卫会把访客带去 install.php
   - 有它 → install.php 拒绝再运行，且数据库缺失时报错而不是静默重建 */
function is_installed(): bool
{
    return is_file(DATA_DIR . '/install.lock');
}

function require_installed(string $redirect = 'install.php'): void
{
    if (is_installed()) return;
    go($redirect);
}

/* ---------------- 输入助手 ---------------- */
/** 取字符串并做长度截断（对应 bbs1org 的 post($key, $maxLen)） */
function post_str(string $key, int $max = MAX_LEN, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    if (!is_string($v)) return $default;
    $v = trim($v);
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
}

function post_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $v = (int)($_POST[$key] ?? $default);
    if ($min !== null) $v = max($min, $v);
    if ($max !== null) $v = min($max, $v);
    return $v;
}

function post_bool(string $key): int
{
    return isset($_POST[$key]) && $_POST[$key] !== '0' ? 1 : 0;
}

function get_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_int(string $key, int $default = 0): int
{
    return (int)($_GET[$key] ?? $default);
}

/* ---------------- Cookie ---------------- */
function set_cookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
}

/* ---------------- CSRF（双提交 Cookie） ---------------- */
function csrf_token(): string
{
    $t = (string)($_COOKIE[CSRF_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $t)) {
        $t = bin2hex(random_bytes(32));
        set_cookie(CSRF_COOKIE, $t, time() + COOKIE_TTL);
        $_COOKIE[CSRF_COOKIE] = $t;
    }
    return $t;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['_csrf'] ?? '');
    $have = (string)($_COOKIE[CSRF_COOKIE] ?? '');
    if ($have === '' || $sent === '' || !hash_equals($have, $sent)) {
        err('表单已过期，请刷新页面后重试', 419);
    }
}

/* ---------------- Flash 提示（走 Cookie，不用 session） ---------------- */
function flash(string $message, string $type = 'ok'): void
{
    set_cookie(FLASH_COOKIE, base64_encode(json_encode(['m' => $message, 't' => $type], JSON_UNESCAPED_UNICODE)), time() + 60);
}

function flash_take(): ?array
{
    $raw = (string)($_COOKIE[FLASH_COOKIE] ?? '');
    if ($raw === '') return null;
    set_cookie(FLASH_COOKIE, '', time() - 3600);
    unset($_COOKIE[FLASH_COOKIE]);
    $data = json_decode((string)base64_decode($raw, true), true);
    return is_array($data) ? $data : null;
}

/* ---------------- 鉴权（无状态 HMAC Cookie） ---------------- */
function auth_set(int $adminId, string $passwordHash): void
{
    $exp = time() + AUTH_TTL;
    $sig = hash_hmac('sha256', $adminId . '|' . $exp, $passwordHash);
    set_cookie(AUTH_COOKIE, $adminId . '.' . $exp . '.' . $sig, $exp);
}

function auth_clear(): void
{
    set_cookie(AUTH_COOKIE, '', time() - 3600);
    unset($_COOKIE[AUTH_COOKIE]);
}

/**
 * 校验登录态。签名密钥用的是当前库里的密码哈希，
 * 所以管理员一改密码，所有旧 Cookie 立即失效。
 */
function auth_user(): ?array
{
    $raw = (string)($_COOKIE[AUTH_COOKIE] ?? '');
    if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/D', $raw, $m)) return null;

    $id = (int)$m[1];
    $exp = (int)$m[2];
    if ($id < 1 || $exp < time()) return null;

    $hash = setting_get('admin_hash');
    if ($hash === '') return null;

    $expect = hash_hmac('sha256', $id . '|' . $exp, $hash);
    if (!hash_equals($expect, $m[3])) return null;

    return ['id' => $id, 'user' => setting_get('admin_user', 'admin')];
}

function require_login(): array
{
    $u = auth_user();
    if (!$u) go('index.php?p=login');
    return $u;
}

/* ---------------- 登录失败限流 ---------------- */
function login_attempt_file(): string
{
    return DATA_DIR . '/login-attempts.json';
}

function login_guard(string $ip): void
{
    $file = login_attempt_file();
    if (!is_file($file)) return;
    $all = json_decode((string)@file_get_contents($file), true);
    if (!is_array($all) || !isset($all[$ip])) return;
    [$count, $first] = $all[$ip];
    if ($count >= 8 && time() - $first < 900) {
        err('密码错误次数过多，请 15 分钟后再试', 429);
    }
}

function login_record_fail(string $ip): void
{
    $file = login_attempt_file();
    $fh = @fopen($file, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $all = json_decode((string)$raw, true);
    if (!is_array($all)) $all = [];
    $now = time();
    // 清理 15 分钟前的记录
    foreach ($all as $k => $v) {
        if (!is_array($v) || $now - (int)($v[1] ?? 0) > 900) unset($all[$k]);
    }
    $entry = $all[$ip] ?? [0, $now];
    $all[$ip] = [(int)$entry[0] + 1, (int)$entry[1]];
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($all));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function login_clear_fail(string $ip): void
{
    $file = login_attempt_file();
    if (!is_file($file)) return;
    $all = json_decode((string)@file_get_contents($file), true);
    if (is_array($all) && isset($all[$ip])) {
        unset($all[$ip]);
        @file_put_contents($file, json_encode($all), LOCK_EX);
    }
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* ---------------- 上传 ---------------- */

/**
 * 读 JPEG 的 EXIF 方向标记（1–8）。
 *
 * 为什么自己解析：这台机器没装 exif 扩展，而 GD 重新编码会把 EXIF 丢掉——
 * 手机竖拍的照片（像素是横的 + orientation=6）压完就变成躺着的了。
 * 只读 APP1 里的 IFD0 / tag 0x0112，够用且不依赖扩展。读不出就当 1（正常方向）。
 */
function jpeg_orientation(string $path): int
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return 1;
    }
    try {
        if (fread($fh, 2) !== "\xFF\xD8") {
            return 1;
        }
        $budget = 262144;   // APP1 一定在文件很靠前的位置，看 256KB 足够
        $read = 2;
        while ($read < $budget) {
            $marker = fread($fh, 2);
            if ($marker === false || strlen($marker) < 2) {
                return 1;
            }
            $read += 2;
            if ($marker[0] !== "\xFF") {
                return 1;   // 结构不对，不猜
            }
            $type = ord($marker[1]);
            if ($type === 0x01 || ($type >= 0xD0 && $type <= 0xD9)) {
                continue;   // 这些段没有长度字段
            }
            $lenBytes = fread($fh, 2);
            if ($lenBytes === false || strlen($lenBytes) < 2) {
                return 1;
            }
            $read += 2;
            $len = unpack('n', $lenBytes)[1];
            if ($len < 2) {
                return 1;
            }
            $payload = (string)fread($fh, $len - 2);
            $read += $len - 2;
            if ($type !== 0xE1 || strncmp($payload, "Exif\0\0", 6) !== 0) {
                continue;
            }
            return tiff_orientation(substr($payload, 6));
        }
    } finally {
        fclose($fh);
    }
    return 1;
}

/** 在 Exif 的 TIFF 结构里找 orientation（0x0112），找不到返回 1 */
function tiff_orientation(string $tiff): int
{
    if (strlen($tiff) < 8) {
        return 1;
    }
    $order = substr($tiff, 0, 2);
    $u16 = $order === 'II' ? 'v' : ($order === 'MM' ? 'n' : '');
    $u32 = $order === 'II' ? 'V' : ($order === 'MM' ? 'N' : '');
    if ($u16 === '') {
        return 1;
    }
    $ifd = unpack($u32, substr($tiff, 4, 4))[1] ?? 0;
    if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
        return 1;
    }
    $count = unpack($u16, substr($tiff, $ifd, 2))[1] ?? 0;
    for ($i = 0; $i < $count; $i++) {
        $at = $ifd + 2 + $i * 12;
        if ($at + 12 > strlen($tiff)) {
            return 1;
        }
        if ((unpack($u16, substr($tiff, $at, 2))[1] ?? 0) !== 0x0112) {
            continue;
        }
        $v = unpack($u16, substr($tiff, $at + 8, 2))[1] ?? 1;
        return ($v >= 1 && $v <= 8) ? $v : 1;
    }
    return 1;
}

/** 按方向标记把图像转正（GD 的 imagerotate 正角度是逆时针） */
function rotate_by_orientation(GdImage $im, int $o): GdImage
{
    switch ($o) {
        case 2:
            imageflip($im, IMG_FLIP_HORIZONTAL);
            return $im;
        case 4:
            imageflip($im, IMG_FLIP_VERTICAL);
            return $im;
        case 5:
            $r = imagerotate($im, -90, 0);
            if ($r) {
                imageflip($r, IMG_FLIP_HORIZONTAL);
            }
            break;
        case 6:
            $r = imagerotate($im, -90, 0);
            break;
        case 7:
            $r = imagerotate($im, 90, 0);
            if ($r) {
                imageflip($r, IMG_FLIP_HORIZONTAL);
            }
            break;
        case 8:
            $r = imagerotate($im, 90, 0);
            break;
        case 3:
            $r = imagerotate($im, 180, 0);
            break;
        default:
            return $im;
    }
    if ($r) {
        imagedestroy($im);
        return $r;
    }
    return $im;
}

/** 把 php.ini 的 128M / -1 这种写法换算成字节（-1 = 不限） */
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $unit = strtolower(substr($v, -1));
    $num = (int)$v;
    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

/** PNG 有没有透明通道：直接看 IHDR 的 color type（4=灰度+alpha，6=RGBA，3=调色板可能带 tRNS） */
function png_has_alpha(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    try {
        $head = (string)fread($fh, 26);
        if (strlen($head) < 26 || strncmp($head, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return false;
        }
        return in_array(ord($head[25]), [3, 4, 6], true);
    } finally {
        fclose($fh);
    }
}

/**
 * 上传后压缩，返回**最终**文件路径（可能因为换格式而改名）。
 *
 * 规则：最长边超过 1920 或体积超过 400KB 才动手。
 *   - JPEG：按 EXIF 方向转正后重编码 q82
 *   - PNG 有透明通道：保持 PNG，只缩尺寸 + 最高压缩级别
 *   - PNG 没有透明通道：改存 JPEG（照片类 PNG 通常能小一个数量级），文件名后缀跟着变
 *   - WebP：重编码 q80
 *   - GIF（可能是动图）、SVG（矢量）：不碰
 *
 * 原则是**宁可压不动也不能弄坏**：没 GD、格式不认、写不进去、压完反而更大，
 * 一律返回原路径，上传流程照常成功。
 */
function optimize_upload(string $path, string $mime): string
{
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagescale')) {
        return $path;
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return $path;
    }
    $info = @getimagesize($path);
    if (!$info) {
        return $path;
    }
    [$w, $h] = $info;
    $before = (int)@filesize($path);
    $maxSide = 1920;
    $keepBytes = 400 * 1024;
    if ($w <= $maxSide && $h <= $maxSide && $before <= $keepBytes) {
        return $path;   // 本来就够小
    }

    // 先算解码要多少内存：原图 + 缩放副本各占 宽×高×4，再留 8MB 余量。
    // 不够就跳过——硬解会在 memory_limit 上直接 OOM（后台白屏），比压不动严重得多。
    $limit = ini_bytes((string)ini_get('memory_limit'));
    if ($limit > 0 && $w * $h * 8 + 8 * 1024 * 1024 > $limit - memory_get_usage(true)) {
        return $path;
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
    };
    if (!$src) {
        return $path;
    }

    if ($mime === 'image/jpeg') {
        $src = rotate_by_orientation($src, jpeg_orientation($path));
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $maxSide / max($w, $h));
    if ($scale < 1.0) {
        $scaled = imagescale($src, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)), IMG_BICUBIC);
        if ($scaled !== false) {
            imagedestroy($src);
            $src = $scaled;
        }
    }

    // PNG 没有 alpha 通道时改走 JPEG：照片用 PNG 存本身就浪费
    $hasAlpha = $mime === 'image/png' && png_has_alpha($path);
    $target = $mime === 'image/png' && !$hasAlpha ? 'image/jpeg' : $mime;

    $final = $path;
    if ($target !== $mime) {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$target];
        $final = preg_replace('/\.[a-z0-9]+$/i', '.' . $ext, $path);
    }
    $tmp = $final . '.' . bin2hex(random_bytes(3)) . '.tmp';

    if ($target === 'image/png') {
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);   // 调色板图缩放过会糊
        }
        imagealphablending($src, false);     // 否则透明区变黑
        imagesavealpha($src, true);
        // 用 6 不用 9：9 只再小 2-3%，耗时却是好几倍——上传是同步请求，别让后台卡住
        $ok = @imagepng($src, $tmp, 6);
    } elseif ($target === 'image/webp') {
        $ok = @imagewebp($src, $tmp, 80);
    } else {
        $ok = @imagejpeg($src, $tmp, 82);
    }
    imagedestroy($src);

    $after = $ok ? (int)@filesize($tmp) : 0;
    if ($ok && $after > 0 && $after < $before) {
        if (@rename($tmp, $final)) {
            if ($final !== $path) {
                @unlink($path);   // 换了格式，旧文件删掉
            }
            return $final;
        }
    }
    @unlink($tmp);
    return $path;
}

/**
 * 取 $_FILES 里的单个文件；没传文件返回 null。
 * 多文件（name="files[]"）用 upload_field_items() 拆成一项一个文件。
 */
function upload_field(string $field): ?array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (!isset($f['error']) || is_array($f['error'])) return null;
    if ((int)$f['error'] === UPLOAD_ERR_NO_FILE) return null;
    return $f;
}

/**
 * 把 $_FILES[$field] 规范成「一项一个文件」的数组，单文件和多文件都能吃。
 * 没有文件时返回空数组；错误码原样放在每项的 error 里，由调用方决定怎么提示。
 *
 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
 */
function upload_field_items(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [];
    $f = $_FILES[$field];
    if (!isset($f['error'])) return [];

    if (!is_array($f['error'])) {
        if ((int)$f['error'] === UPLOAD_ERR_NO_FILE) return [];
        return [[
            'name'     => (string)($f['name'] ?? ''),
            'type'     => (string)($f['type'] ?? ''),
            'tmp_name' => (string)($f['tmp_name'] ?? ''),
            'error'    => (int)$f['error'],
            'size'     => (int)($f['size'] ?? 0),
        ]];
    }

    $items = [];
    foreach (array_keys($f['error']) as $i) {
        $error = (int)$f['error'][$i];
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        $items[] = [
            'name'     => (string)($f['name'][$i] ?? ''),
            'type'     => (string)($f['type'][$i] ?? ''),
            'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
            'error'    => $error,
            'size'     => (int)($f['size'][$i] ?? 0),
        ];
    }
    return $items;
}

/**
 * 保存一个已上传的图片文件（$_FILES 里的单项）。
 * 校验顺序：上传错误 → 大小 → finfo 真实 MIME → 从白名单反查扩展名；落盘后自动压缩。
 *
 * 返回 ['path' => ?string, 'error' => string]：失败不输出、不退出，
 * 这样批量上传可以「能传的传、传不了的逐条报」，而不是一张坏图让整批失败。
 *
 * @return array{path: ?string, error: string}
 */
function save_upload_file(array $f, string $subdir = 'img/uploads'): array
{
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => '上传失败，错误码 ' . (int)($f['error'] ?? 0)];
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > UPLOAD_MAX) {
        return ['path' => null, 'error' => '图片大小需在 5MB 以内'];
    }

    $allow = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/vnd.microsoft.icon' => 'ico',
        'image/x-icon' => 'ico',
    ];
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return ['path' => null, 'error' => '临时文件不见了，请重试'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!isset($allow[$mime])) {
        return ['path' => null, 'error' => '只允许 jpg / png / gif / webp / svg / ico，当前是 ' . $mime];
    }

    $dir = APP_ROOT . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['path' => null, 'error' => '无法创建目录 ' . trim($subdir, '/')];
    }

    $name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allow[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['path' => null, 'error' => '写入文件失败，请检查目录权限'];
    }

    // 压缩失败不影响上传：内部全部兜底，最差就是原图。
    // 注意返回值可能是改名后的路径（大的无透明 PNG 会转存成 JPEG）
    $final = optimize_upload($dir . '/' . $name, $mime);

    return ['path' => trim($subdir, '/') . '/' . basename($final), 'error' => ''];
}

/**
 * 保存上传文件，返回相对站点根目录的路径（如 img/uploads/20261029-ab12cd34.jpg）。
 * 单文件的便捷包装：校验不过就直接 err()（表单提交场景要的就是这个行为）。
 */
function save_upload(string $field, string $subdir = 'img/uploads'): ?string
{
    $f = upload_field($field);
    if ($f === null) return null;

    $r = save_upload_file($f, $subdir);
    if ($r['error'] !== '') err($r['error']);
    return $r['path'];
}

/**
 * 保存上传的音频，返回相对站点根目录的路径（如 music/20261101-ab12cd34.mp3）。
 * 只按 finfo 嗅探真实类型，不做转码；上限 20MB（PHP 的 upload_max_filesize 也要够）。
 */
function save_audio(string $field, string $subdir = 'music'): ?string
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) err('上传失败，错误码 ' . (int)$f['error']);
    if ($f['size'] <= 0 || $f['size'] > AUDIO_MAX) err('音频大小需在 20MB 以内');

    $allow = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/webm' => 'webm',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($f['tmp_name']);
    if (!isset($allow[$mime])) err('只允许 mp3 / m4a / aac / ogg / wav / flac，当前是 ' . h($mime));

    $dir = APP_ROOT . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) err('无法创建目录 ' . h($subdir));

    $name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allow[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) err('写入文件失败，请检查目录权限');

    return trim($subdir, '/') . '/' . $name;
}
