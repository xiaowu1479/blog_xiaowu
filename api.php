<?php
/**
 * api.php —— 前台数据接口
 *
 * 首页的 js/site.js 会 fetch 这个文件拿内容；拿不到就退回内置默认值，
 * 所以就算 PHP 挂了，页面也不会白屏。
 */
declare(strict_types=1);

// err() 在接口里输出 JSON，而不是整页 HTML（表单提交失败时前端才好提示）
define('JSON_ERRORS', true);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/repo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

/** 统一的 JSON 输出（接口里的报错也用这个格式） */
function api_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 单行文本：去掉控制字符、合并空白、限长 */
function friend_text_clean(string $v, int $max): string
{
    $v = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v) ?? '';
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
}

/** 友链地址只允许 http / https；没写协议就补 https:// */
function friend_href_clean(string $raw): string
{
    $raw = trim(preg_replace('/[\x00-\x20\x7F]+/', '', $raw) ?? '');
    if ($raw === '') return '';
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) $raw = 'https://' . $raw;
    if (strlen($raw) > 300) return '';

    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') return '';

    return filter_var($raw, FILTER_VALIDATE_URL) ? $raw : '';
}

// 还没安装：返回 503，前台会自动退回内置默认内容，而不是白屏
if (!is_installed()) {
    http_response_code(503);
    echo json_encode(['error' => 'not-installed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 已安装但库文件丢失：同样 503，前台退回默认内容
if (db_missing()) {
    http_response_code(503);
    echo json_encode(['error' => 'db-missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 前台友链申请：POST api.php?friend_apply=1
// 只写申请队列，不会直接进 friends；后台「友链申请」审核通过后才会上前台。
if (is_post() && get_str('friend_apply') !== '') {
    csrf_check();   // 和后台一样用双提交 Cookie，防止被别的站点代提交

    // 蜜罐：正常访客看不到这个字段，填了的一律当成机器人，返回成功但不入库
    if (post_str('website') !== '') {
        api_out(['ok' => true, 'message' => '申请已提交，站长审核通过后会显示在友链里']);
    }

    $name = friend_text_clean(post_str('name', 60), 40);
    $desc = friend_text_clean(post_str('desc', 160), 100);
    $contact = friend_text_clean(post_str('contact', 160), 120);
    $href = friend_href_clean(post_str('href', 300));

    if ($name === '') api_out(['ok' => false, 'error' => '请填写站点名称'], 400);
    if ($href === '') api_out(['ok' => false, 'error' => '链接格式不对，请填写完整的 http(s):// 地址'], 400);

    // 同一个 IP 10 分钟最多 3 条、24 小时最多 10 条；够用，也不至于把正常访客挡太久
    $ip = client_ip();
    if (friend_apply_recent_count($ip, 600) >= 3) {
        api_out(['ok' => false, 'error' => '提交太频繁了，请过几分钟再试'], 429);
    }
    if (friend_apply_recent_count($ip, 86400) >= 10) {
        api_out(['ok' => false, 'error' => '今天提交的次数有点多，请明天再试'], 429);
    }

    // 同一个链接已经在排队 / 已经在友链里，就不再重复添加
    $dup = db()->prepare('SELECT 1 FROM friend_applies WHERE href = ? AND status = ? LIMIT 1');
    $dup->execute([$href, 'pending']);
    if ($dup->fetchColumn()) {
        api_out(['ok' => false, 'error' => '这个链接已经在审核队列里了，请耐心等待'], 409);
    }

    $has = db()->prepare('SELECT 1 FROM friends WHERE href = ? LIMIT 1');
    $has->execute([$href]);
    if ($has->fetchColumn()) {
        api_out(['ok' => false, 'error' => '这个站点已经在友链里了'], 409);
    }

    $id = friend_apply_create([
        'name' => $name,
        'desc' => $desc,
        'href' => $href,
        'contact' => $contact,
        'ip' => $ip,
    ]);
    if (!$id) api_out(['ok' => false, 'error' => '保存失败，请稍后再试'], 500);

    api_out(['ok' => true, 'message' => '申请已提交，站长审核通过后会显示在友链里']);
}

// 单篇文章：api.php?post=12 → 正文 + 上下篇
$postId = get_int('post', 0);
if ($postId > 0) {
    $post = post_by_id($postId);
    if (!$post) {
        http_response_code(404);
        echo json_encode(['error' => 'not-found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $post['neighbors'] = post_neighbors($postId);
    $post['siteName'] = setting_get('name', '小悟大人');
    echo json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 文章列表：api.php?posts=1&q=关键词&page=2&per=8&cat=3
if (get_str('posts') !== '') {
    $q = get_str('q');
    $len = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
    if ($len > 80) $q = function_exists('mb_substr') ? mb_substr($q, 0, 80) : substr($q, 0, 80);
    $list = posts_page($q, get_int('page', 1), get_int('per', 8), get_int('cat', 0));
    echo json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 分类列表：api.php?cats=1
if (get_str('cats') !== '') {
    $cats = categories_list();
    $total = 0;
    foreach ($cats as $c) $total += (int)$c['count'];
    echo json_encode(['items' => $cats, 'total' => $total], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 首页/普通数据：顺带下发一个 CSRF token，前台「申请友链」提交时用
$payload = site_payload();
$payload['csrf'] = csrf_token();
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
