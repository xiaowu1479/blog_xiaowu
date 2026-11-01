<?php
/**
 * lib/media.php —— 媒体库（img/uploads 里那批图片）
 *
 * 为什么不建表：图片的唯一真相就是磁盘上的文件——后台表单、Markdown 编辑器、
 * 远程抓图三条路最后都落在 img/uploads/，扫目录就能看到全部（包括以前传的旧图），
 * 不需要额外的入库步骤，也不会出现「文件在、记录没了」的偏差。
 *
 * 「谁在用这张图」靠扫描：数据库里的所有文本字段 + 模板里写死的路径。
 * 删图前会过一遍这个检查，被引用的直接拒绝删除，避免前台裂图。
 */
declare(strict_types=1);

/** 图片存放目录（唯一一个，和 save_upload() 的默认子目录保持一致） */
function media_dir(): string
{
    return APP_ROOT . '/img/uploads';
}

/** 媒体库认的扩展名（对应 save_upload() 的白名单，另加 avif / bmp 这类历史文件） */
function media_exts(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'avif', 'bmp'];
}

/** 文件大小 → 人话（946 B / 128 KB / 1.4 MB） */
function media_size_text(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 0) . ' KB';
    return number_format($bytes / 1024 / 1024, 1) . ' MB';
}

/**
 * 列图片，按修改时间倒序（新传的在最前）。
 * $q 按文件名匹配（不区分大小写）；$withUsage = false 时跳过引用检测（选图弹窗不需要）。
 *
 * @return array<int, array{rel: string, name: string, size: int, mtime: int, usage: array<int, string>}>
 */
function media_list(string $q = '', bool $withUsage = true): array
{
    $dir = media_dir();
    if (!is_dir($dir)) return [];

    $q = trim($q);
    $exts = media_exts();
    $items = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $abs = $dir . '/' . $name;
        if (!is_file($abs)) continue;
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $exts, true)) continue;
        if ($q !== '' && stripos($name, $q) === false) continue;

        $items[] = [
            'rel'   => 'img/uploads/' . $name,
            'name'  => $name,
            'size'  => (int)(@filesize($abs) ?: 0),
            'mtime' => (int)(@filemtime($abs) ?: 0),
            'usage' => [],
        ];
    }

    usort($items, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    if ($withUsage && $items) {
        $map = media_usage_map(array_column($items, 'name'));
        foreach ($items as &$it) {
            $it['usage'] = $map[$it['name']] ?? [];
        }
        unset($it);
    }

    return $items;
}

/**
 * 文件名 → 被哪儿引用了（最多记 4 条，够提示用）。
 * 扫两处：库里所有业务表的文本字段、模板里写死的文件名（和 _preview/clean-uploads.php 同一套判断）。
 *
 * @param array<int, string> $names
 * @return array<string, array<int, string>>
 */
function media_usage_map(array $names): array
{
    $names = array_values(array_unique(array_filter(array_map('strval', $names), static fn(string $s): bool => $s !== '')));
    if (!$names) return [];

    $map = [];
    $probe = static function (string $haystack, string $label) use (&$map, $names): void {
        if ($haystack === '') return;
        foreach ($names as $name) {
            if (isset($map[$name]) && count($map[$name]) >= 4) continue;
            if (str_contains($haystack, $name)) $map[$name][] = $label;
        }
    };

    $pdo = db();

    // 1) 业务表：文章正文、相册、作品图标、社交图标……
    $tables = [
        'posts'      => '博客',
        'works'      => '作品',
        'photos'     => '相册',
        'friends'    => '友链',
        'categories' => '分类',
        'contacts'   => '社交图标',
    ];
    foreach ($tables as $table => $label) {
        foreach ($pdo->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
            $title = trim((string)($row['title'] ?? ($row['name'] ?? '')));
            $tag = $label . ($title !== '' ? '「' . mb_strimwidth($title, 0, 18, '…') . '」' : '');
            foreach ($row as $v) {
                if (is_string($v)) $probe($v, $tag);
            }
        }
    }

    // 2) 设置项：头像、标签页图标、赞赏码、音乐封面
    $settingLabels = [
        'avatar'     => '头像',
        'favicon'    => '标签页图标',
        'pay_wechat' => '微信赞赏图',
        'pay_alipay' => '支付宝图',
        'music_cover' => '音乐封面',
    ];
    foreach ($pdo->query('SELECT name, value FROM settings') as $row) {
        $name = (string)$row['name'];
        $probe((string)$row['value'], '设置「' . ($settingLabels[$name] ?? $name) . '」');
    }

    // 3) 模板里写死的图（不经过数据库的那部分）
    foreach (['*.html', '*.php', 'js/*.js', 'css/*.css', 'lib/*.php', 'admin/*.php', 'admin/assets/*'] as $pattern) {
        foreach (glob(APP_ROOT . '/' . $pattern) ?: [] as $file) {
            if (!is_file($file)) continue;
            $probe((string)@file_get_contents($file), '模板 ' . basename($file));
        }
    }

    foreach ($map as $name => $labels) {
        $map[$name] = array_values(array_unique($labels));
    }
    return $map;
}

/**
 * img/uploads/xxx.jpg → 磁盘绝对路径；路径不合法 / 文件不存在 / 跑出目录范围一律返回 null。
 * 这是删文件前的唯一入口，别绕过它拼路径。
 */
function media_abs(string $rel): ?string
{
    $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
    if ($rel === '' || str_contains($rel, '..')) return null;
    if (!preg_match('~^img/uploads/[A-Za-z0-9._-]+$~', $rel)) return null;
    if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), media_exts(), true)) return null;

    $real = realpath(APP_ROOT . '/' . $rel);
    $base = realpath(media_dir());
    if ($real === false || $base === false || !is_file($real)) return null;
    if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) return null;

    return $real;
}

/** 删除一个文件；路径不合法或删不掉返回 false */
function media_delete(string $rel): bool
{
    $abs = media_abs($rel);
    if ($abs === null) return false;
    return @unlink($abs);
}

/**
 * 批量保存上传的图片（表单字段用 name="files[]"）。
 * 单张失败只记错误，不影响其它张。
 *
 * @return array{saved: array<int, string>, errors: array<int, string>}
 */
function media_save_many(string $field = 'files'): array
{
    $saved = [];
    $errors = [];

    foreach (upload_field_items($field) as $item) {
        $r = save_upload_file($item, 'img/uploads');
        if (($r['error'] ?? '') !== '') {
            $errors[] = ($item['name'] !== '' ? $item['name'] : '文件') . '：' . $r['error'];
            continue;
        }
        if (!empty($r['path'])) $saved[] = (string)$r['path'];
    }

    return ['saved' => $saved, 'errors' => $errors];
}
