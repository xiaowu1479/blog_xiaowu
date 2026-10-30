<?php
/**
 * admin/upload.php —— 给 Markdown 编辑器用的图片接口
 *
 * 两种入参，返回都是 { path: 'img/uploads/xxx.jpg' }：
 *
 *   file  multipart 文件     编辑器里点「图片」选文件、截图粘贴、拖拽都走这条
 *   url   远程图片地址       从网页/公众号里「连文字带图」复制时，图片是 <img src="http…">，
 *                            由服务端抓回来存成本地图（避免图床挂掉、防盗链、https 混用）
 *
 * 安全：必须登录 + CSRF；类型用 finfo 嗅探真实类型（不看扩展名）；一律 ≤5MB；存完自动压缩。
 * 远程抓取额外做 SSRF 防护——只允许公网 http/https，本机/内网/链路本地地址直接拒。
 */
declare(strict_types=1);

// err() 默认吐整页 HTML，这里是给 fetch 用的接口，所以切成 JSON
define('JSON_ERRORS', true);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';
require __DIR__ . '/../lib/http.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (auth_user() === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '登录已过期，请重新登录后台'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_post()) err('只接受 POST', 405);
csrf_check();

/* ---------------- 1. 直接上传 ---------------- */
$path = save_upload('file');

/* ---------------- 2. 远程图片导入 ---------------- */
if ($path === null && isset($_POST['url'])) {
    $path = import_remote_image((string)$_POST['url']);
}

if ($path === null) err('没有收到文件', 400);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['path' => $path, 'size' => filesize(APP_ROOT . '/' . $path) ?: 0], JSON_UNESCAPED_UNICODE);

/* ------------------------------------------------------------------ */

/**
 * 把远程图片抓回本地 img/uploads，返回站点根路径。
 * 失败一律 err()（JSON 模式），前端会退回到「保留原始链接」。
 */
function import_remote_image(string $url): string
{
    $url = trim($url);
    if (!preg_match('~^https?://~i', $url)) {
        err('只支持 http / https 链接', 400);
    }

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') err('链接里没有主机名', 400);

    // SSRF 防护：域名解析出来的每个地址都必须是公网
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) err('域名解析失败：' . $host, 400);
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            err('不允许抓取内网 / 本机地址', 400);
        }
    }

    $tmp = (string)tempnam(sys_get_temp_dir(), 'zhs-dl-');
    $fh = fopen($tmp, 'wb');
    if (!$fh) err('无法写临时文件', 500);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_MAXFILESIZE => UPLOAD_MAX,
        CURLOPT_USERAGENT => 'zhanshiye-blog/1.0 (+admin image import)',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    // Windows 版 PHP 默认没有根证书，https 会报 "unable to get local issuer certificate"；
    // 优先用 php.ini 里配的，没配就用仓库自带的 lib/cacert.pem（和 lib/http.php 共用一处逻辑）
    $caFile = curl_ca_file();
    if ($caFile !== '') curl_setopt($ch, CURLOPT_CAINFO, $caFile);

    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr = curl_error($ch);
    unset($ch);
    fclose($fh);

    if ($ok === false || $code >= 400) {
        @unlink($tmp);
        err('抓取失败：' . ($curlErr !== '' ? $curlErr : 'HTTP ' . $code), 400);
    }

    $size = filesize($tmp) ?: 0;
    if ($size <= 0) { @unlink($tmp); err('抓到的内容是空的', 400); }
    if ($size > UPLOAD_MAX) { @unlink($tmp); err('图片超过 5MB', 400); }

    $allow = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset($allow[$mime])) {
        @unlink($tmp);
        err('抓到的不是图片（' . $mime . '）', 400);
    }

    $dir = APP_ROOT . '/img/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) err('无法创建目录 img/uploads', 500);

    $name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allow[$mime];
    if (!@copy($tmp, $dir . '/' . $name)) {
        @unlink($tmp);
        err('写入文件失败，请检查目录权限', 500);
    }
    @unlink($tmp);

    // 和本地上传同一条压缩路径
    $final = optimize_upload($dir . '/' . $name, $mime);

    return 'img/uploads/' . basename($final);
}
