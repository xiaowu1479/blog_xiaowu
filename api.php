<?php
/**
 * api.php —— 前台数据接口
 *
 * 首页的 js/site.js 会 fetch 这个文件拿内容；拿不到就退回内置默认值，
 * 所以就算 PHP 挂了，页面也不会白屏。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/repo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

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

echo json_encode(site_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
