<?php
/**
 * lib/http.php —— 出站 HTTP 的薄封装（curl）
 *
 * 只干三件事：取文本、取 JSON、直接落盘成文件。统一处理：
 *   · CA 证书：php.ini 里没配（Windows 上很常见）就用仓库自带的 lib/cacert.pem
 *   · 大小上限：下载到一半超了就中断，不会把内存或磁盘吃满
 *   · 跳转跟随最多 3 次，超时、连接超时分开设
 *
 * 只给「后台主动去取东西」的两处用：远程图片导入（admin/upload.php）、系统更新（lib/updater.php）。
 */
declare(strict_types=1);

/** 可用的 CA 证书路径；找不到返回空串（curl 会用系统默认，Windows 上多半会失败） */
function curl_ca_file(): string
{
    $ca = trim((string) ini_get('curl.cainfo'));
    if ($ca === '') {
        $ca = trim((string) ini_get('openssl.cafile'));
    }
    if ($ca !== '' && is_file($ca)) {
        return $ca;
    }

    $base = is_file(__DIR__ . '/cacert.pem') ? __DIR__ . '/cacert.pem' : '';

    // 公司代理 / 本机加速器会替换 TLS 证书，这类根证书只能自己放：
    // 丢到 data/cacert.local.pem，这里会把它拼到官方证书包后面一起用。
    $dataDir = defined('DATA_DIR') ? DATA_DIR : dirname(__DIR__) . '/data';
    $extra   = $dataDir . '/cacert.local.pem';
    if (is_file($extra) && filesize($extra) > 0) {
        return curl_ca_merge($base, $extra);
    }
    return $base;
}

/** 把官方证书包和本机额外证书拼成一个包（按大小+时间戳缓存，变了才重拼） */
function curl_ca_merge(string $base, string $extra): string
{
    $dataDir = defined('DATA_DIR') ? DATA_DIR : dirname(__DIR__) . '/data';
    $out     = $dataDir . '/cacert.bundle.pem';
    $sig     = ($base !== '' ? filesize($base) . '@' . filemtime($base) : '0')
        . '|' . filesize($extra) . '@' . filemtime($extra);

    if (is_file($out) && (string) @file_get_contents($out . '.sig') === $sig) {
        return $out;
    }
    $body = ($base !== '' ? (string) @file_get_contents($base) : '') . "\n"
        . (string) @file_get_contents($extra) . "\n";
    if (@file_put_contents($out, $body) === false) {
        return $extra;                            // 拼不出来就先用额外那份
    }
    @file_put_contents($out . '.sig', $sig);
    return $out;
}

/**
 * 发一个 GET。
 *
 * @param string[] $headers   额外请求头，形如 ['Accept: application/json']
 * @param int      $maxBytes  响应体上限（字节），0 表示不限制
 * @param int      $timeout   总超时（秒）
 * @param ?string  $toFile    不为 null 时把响应体写进这个文件，不占内存
 * @return array{ok: bool, status: int, body: string, bytes: int, error: string}
 */
function http_get(
    string $url,
    array $headers = [],
    int $maxBytes = 8388608,
    int $timeout = 20,
    ?string $toFile = null
): array {
    $out = ['ok' => false, 'status' => 0, 'body' => '', 'bytes' => 0, 'error' => ''];

    if (!function_exists('curl_init')) {
        $out['error'] = 'PHP 没装 curl 扩展';
        return $out;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $out['error'] = '只支持 http/https 地址';
        return $out;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        $out['error'] = 'curl 初始化失败';
        return $out;
    }

    $fh = null;
    if ($toFile !== null) {
        $fh = @fopen($toFile, 'wb');
        if ($fh === false) {
            curl_close($ch);
            $out['error'] = '写不了文件：' . basename($toFile);
            return $out;
        }
        curl_setopt($ch, CURLOPT_FILE, $fh);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: zhs-update/1.0'], $headers),
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => static function ($ch2, $dlTotal, $dlNow) use ($maxBytes): int {
            return ($maxBytes > 0 && $dlNow > $maxBytes) ? 1 : 0;   // 非 0 即中断
        },
    ]);
    if ($maxBytes > 0) {
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
    }
    $ca = curl_ca_file();
    if ($ca !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $out['bytes']  = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    if ($fh !== null) {
        fclose($fh);
    }

    if ($toFile === null) {
        if (is_string($body)) {
            $out['body']  = $body;
            $out['bytes'] = strlen($body);
        }
    } elseif (is_file($toFile)) {
        clearstatcache(true, $toFile);
        $out['bytes'] = (int) filesize($toFile);
    }

    if ($errno !== 0) {
        $out['error'] = $errno === 28 ? '超时了（' . $timeout . ' 秒）' : ($error !== '' ? $error : 'curl 错误 ' . $errno);
        if ($toFile !== null) {
            @unlink($toFile);
        }
        return $out;
    }
    if ($out['status'] < 200 || $out['status'] > 299) {
        $out['error'] = 'HTTP ' . $out['status'];
        if ($toFile !== null) {
            @unlink($toFile);
        }
        return $out;
    }
    if ($maxBytes > 0 && $out['bytes'] > $maxBytes) {
        $out['error'] = '响应太大（' . round($out['bytes'] / 1048576, 1) . 'MB，上限 '
            . round($maxBytes / 1048576, 1) . 'MB）';
        if ($toFile !== null) {
            @unlink($toFile);
        }
        return $out;
    }

    $out['ok'] = true;
    return $out;
}

/**
 * 取 JSON。成功时 json 字段是解出来的数组。
 *
 * @return array{ok: bool, status: int, json: mixed, error: string}
 */
function http_get_json(string $url, array $headers = [], int $maxBytes = 4194304, int $timeout = 20): array
{
    $r = http_get($url, array_merge(['Accept: application/vnd.github+json'], $headers), $maxBytes, $timeout);
    $json = null;
    if ($r['body'] !== '') {
        $decoded = json_decode($r['body'], true);
        if (is_array($decoded)) {
            $json = $decoded;
        } elseif ($r['ok']) {
            $r['ok']    = false;
            $r['error'] = '返回的不是 JSON（可能被代理/登录页拦了）';
        }
    }
    return ['ok' => $r['ok'], 'status' => $r['status'], 'json' => $json, 'error' => $r['error']];
}

/** 递归删除目录（只用在 data/update-tmp 这种自己建的目录上） */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
