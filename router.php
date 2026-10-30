<?php
/**
 * router.php —— 给 PHP 内置服务器用的路由脚本
 *
 *   php -S 127.0.0.1:8099 router.php
 *
 * 内置服务器不读 .htaccess，所以 data/ 里的数据库会变成可下载文件。
 * 这里把 data/、lib/ 以及 .db/.json 之类一律拦掉；正式环境用 Apache 时走的是 .htaccess，用不到本文件。
 */
declare(strict_types=1);

$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

$blockedDir = (bool)preg_match('#^/(data|lib)(/|$)#i', $path);
$blockedExt = (bool)preg_match('#\.(db|db-wal|db-shm|sqlite|sqlite3|json|lock|md)$#i', $path);

if ($blockedDir || $blockedExt) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('403 Forbidden');
}

return false;   // 其余请求交回内置服务器处理
