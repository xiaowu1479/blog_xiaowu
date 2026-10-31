<?php
/**
 * index.php —— 前台守卫
 *
 * nginx / Apache 的 DirectoryIndex 都是 index.php 优先于 index.html，
 * 所以访问站点根目录时会先落到这里：
 *   - 没装 → 跳安装页
 *   - 装过 → 原样输出静态的 index.html
 *
 * 前台本身仍是纯静态页面，这里只做一次状态判断，不参与渲染。
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (!is_installed()) {
    go('install.php');
}

header('Content-Type: text/html; charset=utf-8');
// 和 nginx.htaccess 里那条保持一致：主页也每次回源校验，别让浏览器留着旧壳子
header('Cache-Control: no-cache, must-revalidate');

// 给主页引用的 css/js 自动加 ?v=文件时间：更新后浏览器/CDN 不可能再拿旧文件。
// 只改本地相对路径，外链（字体、CDN）原样保留。
$html = (string) @file_get_contents(__DIR__ . '/index.html');
$versioned = preg_replace_callback(
    '~\b(href|src)="((?:css|js)/[^"?#]+\.(?:css|js))(?:\?v=[^"#]*)?"~i',
    static function (array $m): string {
        $rel = $m[2];
        $abs = __DIR__ . '/' . $rel;
        if (!is_file($abs)) {
            return $m[1] . '="' . $rel . '"';
        }
        return $m[1] . '="' . $rel . '?v=' . (int) filemtime($abs) . '"';
    },
    $html
);
if (is_string($versioned)) {
    $html = $versioned;
}
echo $html;
