<?php
/**
 * admin/media.php —— 媒体库列表接口（给各表单里的「从媒体库选择」弹窗用）
 *
 * GET ?list=1&q=关键词 → { items: [{ rel, name, size, mtime }], total, limit }
 *
 * 只读接口：上传和删除都走 admin/index.php?p=media（POST + CSRF + PRG），
 * 这里不碰文件系统以外的任何东西，只把 img/uploads 的文件列出来。
 */
declare(strict_types=1);

// err() 默认吐整页 HTML，这里是给 fetch 用的接口，所以切成 JSON
define('JSON_ERRORS', true);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';
require __DIR__ . '/../lib/media.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (auth_user() === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '登录已过期，请重新登录后台'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_post()) err('只接受 GET', 405);

$q = get_str('q', '');
$items = media_list($q, false);          // 弹窗只要缩略图和名字，不做引用检测

// 一次最多返回 300 张：再多也没人靠翻弹窗找，用搜索更快
$limit = 300;
$out = [];
foreach (array_slice($items, 0, $limit) as $it) {
    $out[] = [
        'rel'   => $it['rel'],
        'name'  => $it['name'],
        'size'  => $it['size'],
        'mtime' => $it['mtime'],
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['items' => $out, 'total' => count($items), 'limit' => $limit], JSON_UNESCAPED_UNICODE);
