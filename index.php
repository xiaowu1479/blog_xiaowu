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
readfile(__DIR__ . '/index.html');
