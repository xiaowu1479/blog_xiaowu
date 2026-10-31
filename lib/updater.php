<?php
/**
 * lib/updater.php —— 系统更新（从 GitHub 仓库拉最新代码，覆盖站点文件）
 *
 * 设计取舍，先说清楚：
 *   1. 不用 git。服务器上不一定有 git，也不希望因为「本地改过」导致 pull 冲突。
 *      这里直接下载 GitHub 的 zipball，解压后按文件比对、按文件覆盖。
 *   2. 只覆盖、不乱删。删除只针对「上一次是我们装的、这次上游没有了」的文件，
 *      而且要它们不在保护目录里。第一次更新没有清单，所以一个都不会删。
 *   3. data/（数据库、安装锁）、img/uploads/（你传的图）、_backup/、_preview/ 永不覆盖。
 *   4. 覆盖之前先把被覆盖的文件复制到 _backup/update-<时间>/，出问题能手工拿回来。
 *   5. 覆盖完按上游内容重算一份清单存进 data/update-manifest.json，下次才知道
 *      「哪个文件是本地自己改的、会被这次更新冲掉」。
 *
 * 状态文件 data/update.json（data/ 有 .htaccess/nginx 规则，外部拿不到）：
 *   repo / branch / token / localZip / current / lastCheck / lastRun / history
 */
declare(strict_types=1);

require_once __DIR__ . '/http.php';

const UPDATE_REPO_DEFAULT   = 'xiaowu1479/blog_xiaowu';
const UPDATE_BRANCH_DEFAULT = 'main';
const UPDATE_ZIP_MAX        = 33554432;   // 压缩包上限 32MB
const UPDATE_FILE_MAX       = 5000;       // 解压文件数上限
const UPDATE_KEEP_BACKUPS   = 3;          // 保留最近几份更新备份

/** 永不覆盖/删除的目录（相对站点根） */
function update_protected_prefixes(): array
{
    return ['data/', 'img/uploads/', '_backup/', '_preview/', '.git/'];
}

function update_is_protected(string $rel): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    foreach (update_protected_prefixes() as $prefix) {
        if ($prefix === '' ) {
            continue;
        }
        if ($rel === rtrim($prefix, '/') || str_starts_with($rel, $prefix)) {
            return true;
        }
    }
    return false;
}

/** 状态/清单/临时目录的路径 */
function update_file(string $what): string
{
    return match ($what) {
        'state'    => DATA_DIR . '/update.json',
        'manifest' => DATA_DIR . '/update-manifest.json',
        'tmp'      => DATA_DIR . '/update-tmp',
        default    => DATA_DIR . '/update.json',
    };
}

function update_defaults(): array
{
    return [
        'repo'      => UPDATE_REPO_DEFAULT,
        'branch'    => UPDATE_BRANCH_DEFAULT,
        'token'     => '',
        'localZip'  => '',
        'current'   => null,
        'lastCheck' => null,
        'lastRun'   => null,
        'history'   => [],
    ];
}

function update_state(): array
{
    $state = update_defaults();
    $file  = update_file('state');
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw)) {
            $state = array_replace($state, $raw);
        }
    }
    $state['repo']   = trim((string) $state['repo']);
    $state['branch'] = trim((string) $state['branch']);
    if ($state['repo'] === '') {
        $state['repo'] = UPDATE_REPO_DEFAULT;
    }
    if ($state['branch'] === '') {
        $state['branch'] = UPDATE_BRANCH_DEFAULT;
    }
    return $state;
}

function update_state_save(array $state): void
{
    update_dir(DATA_DIR);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    $file = update_file('state');
    $tmp  = $file . '.tmp';
    if (@file_put_contents($tmp, $json) === false) {
        return;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        @file_put_contents($file, $json);
    }
}

function update_manifest(): array
{
    $file = update_file('manifest');
    if (!is_file($file)) {
        return [];
    }
    $raw = json_decode((string) @file_get_contents($file), true);
    return is_array($raw) ? $raw : [];
}

function update_manifest_save(array $manifest): void
{
    ksort($manifest);
    $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $file = update_file('manifest');
    $tmp  = $file . '.tmp';
    if (@file_put_contents($tmp, $json) !== false && !@rename($tmp, $file)) {
        @unlink($tmp);
        @file_put_contents($file, $json);
    }
}

/**
 * 用「安装台账」校验本地文件是否被改过 / 删过。
 * 版本号相同不代表文件没被动过：手动恢复、从备份拷文件、部分写入失败都会造成漂移。
 *
 * @return array{ok: bool, total: int, changed: array<int, array{path: string, state: string}>}
 */
function update_local_drift(): array
{
    $manifest = update_manifest();
    $out = ['ok' => false, 'total' => 0, 'changed' => []];
    if ($manifest === []) {
        return $out;
    }

    $out['ok'] = true;
    $out['total'] = count($manifest);
    foreach ($manifest as $rel => $sha1) {
        $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        $abs = APP_ROOT . '/' . $rel;
        if (!is_file($abs)) {
            $out['changed'][] = ['path' => $rel, 'state' => 'missing'];
            continue;
        }
        if (@sha1_file($abs) !== (string) $sha1) {
            $out['changed'][] = ['path' => $rel, 'state' => 'modified'];
        }
    }
    return $out;
}

function update_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0755, true) || is_dir($dir);
}

/* ============================ GitHub ============================ */

/** 调 GitHub API；配了 token 就带上（私有仓库必须有） */
function update_api(string $path, int $maxBytes = 4194304): array
{
    $state   = update_state();
    $headers = ['X-GitHub-Api-Version: 2022-11-28'];
    if ($state['token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $state['token'];
    }
    $r = http_get_json('https://api.github.com/' . ltrim($path, '/'), $headers, $maxBytes);
    if (!$r['ok']) {
        $r['error'] = match (true) {
            $r['status'] === 404 => '仓库或分支不存在'
                . ($state['token'] === '' ? '（如果是私有仓库，需要配 token）' : ''),
            $r['status'] === 401 => 'token 不对或过期了',
            $r['status'] === 403 => '被 GitHub 限流或没权限，过几分钟再试',
            $r['status'] === 0   => '连不上 GitHub：' . $r['error'],
            default              => 'GitHub 返回 ' . $r['status'] . '：' . $r['error'],
        };
    }
    return $r;
}

/**
 * 取远端最新一次提交。
 * @return array{ok: bool, sha: string, short: string, message: string, author: string,
 *               date: string, url: string, error: string}
 */
function update_remote_head(): array
{
    $state = update_state();
    $out   = ['ok' => false, 'sha' => '', 'short' => '', 'message' => '',
              'author' => '', 'date' => '', 'url' => '', 'error' => ''];
    $r = update_api('/repos/' . $state['repo'] . '/commits?sha=' . rawurlencode($state['branch']) . '&per_page=1');
    if (!$r['ok']) {
        $out['error'] = $r['error'];
        return $out;
    }
    $list = $r['json'];
    if (!is_array($list) || !isset($list[0]['sha'])) {
        $out['error'] = '分支 ' . $state['branch'] . ' 还没有提交';
        return $out;
    }
    $c = $list[0];
    $out['ok']      = true;
    $out['sha']     = (string) $c['sha'];
    $out['short']   = substr((string) $c['sha'], 0, 7);
    $out['message'] = trim((string) ($c['commit']['message'] ?? ''));
    $out['author']  = (string) ($c['commit']['author']['name'] ?? ($c['author']['login'] ?? ''));
    $out['date']    = (string) ($c['commit']['author']['date'] ?? '');
    $out['url']     = (string) ($c['html_url'] ?? '');
    return $out;
}

/**
 * 取「该更新到哪一版」。
 *
 * 配了本地包就以本地包为准：这是明确的「用这个包」指令，版本号也用包的哈希。
 * 不能先问 GitHub 拿到远端版本号、下载时却装本地旧包——那样会出现
 * 「后台显示已更新到最新提交，实际文件还是旧包」的假成功。
 * 想从 GitHub 更新，先把 localZip 清空（后台更新页有按钮）。
 */
function update_head(): array
{
    $state = update_state();

    if ($state['localZip'] !== '') {
        if (!is_file($state['localZip'])) {
            return [
                'ok'      => false,
                'sha'     => '',
                'short'   => '',
                'message' => '',
                'author'  => '',
                'date'    => '',
                'url'     => '',
                'error'   => '本地包不存在：' . $state['localZip'] . '（可以在更新页点「改回从 GitHub 更新」）',
            ];
        }

        $sha = 'local-' . substr((string) sha1_file($state['localZip']), 0, 8);
        return [
            'ok'      => true,
            'sha'     => $sha,
            'short'   => $sha,
            'message' => '本地包 · ' . basename($state['localZip']),
            'author'  => '本地',
            'date'    => date('c', (int) filemtime($state['localZip'])),
            'url'     => '',
            'error'   => '',
        ];
    }

    return update_remote_head();
}

/** 最近 N 条提交，给页面上的更新日志用 */
function update_recent_commits(int $n = 5): array
{
    $state = update_state();
    $n     = max(1, min(20, $n));
    $r = update_api('/repos/' . $state['repo'] . '/commits?sha=' . rawurlencode($state['branch']) . '&per_page=' . $n);
    if (!$r['ok'] || !is_array($r['json'])) {
        return [];
    }
    $out = [];
    foreach ($r['json'] as $c) {
        if (!isset($c['sha'])) {
            continue;
        }
        $out[] = [
            'sha'     => substr((string) $c['sha'], 0, 7),
            'message' => (string) strtok(trim((string) ($c['commit']['message'] ?? '')), "\n"),
            'author'  => (string) ($c['commit']['author']['name'] ?? ''),
            'date'    => (string) ($c['commit']['author']['date'] ?? ''),
        ];
    }
    return $out;
}

/* ============================ 下载 / 解压 ============================ */

/**
 * 把指定提交的源码包下载到 data/update-tmp。
 * 配了 localZip（本地离线包）就优先用它，方便没外网的服务器。
 *
 * @return array{ok: bool, zip: string, sha: string, bytes: int, source: string, error: string}
 */
function update_download(string $sha, string $refPath = '', bool $forceRemote = false): array
{
    $state = update_state();
    $out   = ['ok' => false, 'zip' => '', 'sha' => $sha, 'bytes' => 0, 'source' => 'GitHub', 'error' => ''];

    $tmpDir = update_file('tmp');
    update_dir($tmpDir);
    $zip = $tmpDir . '/source.zip';
    @unlink($zip);

    // 同一个版本反复点「检查更新」时不用重复下载（GitHub 包 10MB 上下，慢网络里差别很大）
    $cacheDir = DATA_DIR . '/update-cache';
    $cacheKey = $sha !== '' ? substr((string) preg_replace('/[^0-9a-f]/', '', $sha), 0, 12) : '';
    $cache    = $cacheDir . '/source-' . $cacheKey . '.zip';
    // 指定了本地包时，本地包优先于缓存（那是明确的「用这个包」指令）
    $useLocal = !$forceRemote && $state['localZip'] !== '' && is_file($state['localZip']);

    // 保险：本地包只能配本地包自己的版本号。如果调用方传进来一个 GitHub 提交号，
    // 说明「版本号」和「实际要装的包」对不上，宁可报错也不要做假更新。
    if ($useLocal && $sha !== '' && !str_starts_with($sha, 'local-') && $refPath === '') {
        $out['error'] = '本地包模式和 GitHub 版本号不一致（' . substr($sha, 0, 7)
            . '）。请清空 localZip 改回从 GitHub 更新，或改用本地包自己的版本号。';
        return $out;
    }

    if (!$useLocal && $cacheKey !== '' && is_file($cache) && (int) @filesize($cache) > 200 && @copy($cache, $zip)) {
        $out['ok']     = true;
        $out['zip']    = $zip;
        $out['bytes']  = (int) @filesize($zip);
        $out['source'] = '上次下载的包';
        return $out;
    }

    if ($useLocal) {
        if (!@copy($state['localZip'], $zip)) {
            $why = error_get_last();
            $out['error'] = '复制本地包失败：' . $state['localZip']
                . '（写到 ' . $zip . '；' . ($why['message'] ?? '系统没给原因') . '）';
            return $out;
        }
        $out['ok']     = true;
        $out['zip']    = $zip;
        $out['bytes']  = (int) @filesize($zip);
        $out['source'] = '本地包';
        return $out;
    }

    if ($refPath !== '') {
        // 按 tag 取：codeload 支持 refs/tags/<name>，私有仓库走 api 的 zipball
        $refEnc = str_replace('%2F', '/', rawurlencode($refPath));
        $url = $state['token'] !== ''
            ? 'https://api.github.com/repos/' . $state['repo'] . '/zipball/' . $refEnc
            : 'https://codeload.github.com/' . $state['repo'] . '/zip/' . $refEnc;
    } else {
        $url = $sha !== ''
            ? ($state['token'] !== ''
            ? 'https://api.github.com/repos/' . $state['repo'] . '/zipball/' . $sha
                : 'https://codeload.github.com/' . $state['repo'] . '/zip/' . $sha)
            : 'https://codeload.github.com/' . $state['repo'] . '/zip/refs/heads/' . rawurlencode($state['branch']);
    }

    $headers = [];
    if ($state['token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $state['token'];
    }
    $r = http_get($url, $headers, UPDATE_ZIP_MAX, 90, $zip);
    if (!$r['ok']) {
        $out['error'] = '下载源码包失败：' . $r['error'];
        return $out;
    }
    if ($r['bytes'] < 200) {
        @unlink($zip);
        $out['error'] = '下载到的源码包太小（' . $r['bytes'] . ' 字节），不像是压缩包';
        return $out;
    }
    $out['ok']    = true;
    $out['zip']   = $zip;
    $out['bytes'] = $r['bytes'];

    // 存一份，下次查同一个版本直接用
    if ($sha !== '') {
        update_dir($cacheDir);
        @copy($zip, $cache);
        foreach ((array) glob($cacheDir . '/source-*.zip') as $old) {
            if ($old !== $cache) {
                @unlink($old);
            }
        }
    }

    return $out;
}

/**
 * 解压到 data/update-tmp/src，返回真正的站点根目录（GitHub 的包里多一层 owner-repo-sha/）。
 *
 * @return array{ok: bool, dir: string, files: int, error: string}
 */
function update_extract(string $zip): array
{
    $out = ['ok' => false, 'dir' => '', 'files' => 0, 'error' => ''];

    if (!class_exists('ZipArchive')) {
        $out['error'] = 'PHP 没装 zip 扩展（ZipArchive），没法解压';
        return $out;
    }

    $za = new ZipArchive();
    if ($za->open($zip) !== true) {
        $out['error'] = '压缩包打不开（可能下载坏了）';
        return $out;
    }

    $names = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = (string) $za->getNameIndex($i);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    // 找最浅的那个 index.html，它所在的目录就是站点根
    $prefix = null;
    $depth  = PHP_INT_MAX;
    foreach ($names as $name) {
        $clean = ltrim(str_replace('\\', '/', $name), '/');
        if (!str_ends_with($clean, 'index.html')) {
            continue;
        }
        $d = substr_count($clean, '/');
        if ($d < $depth) {
            $depth  = $d;
            $prefix = substr($clean, 0, strlen($clean) - strlen('index.html'));
        }
    }
    if ($prefix === null) {
        $za->close();
        $out['error'] = '压缩包里没有 index.html —— 仓库里大概还没放站点文件';
        return $out;
    }

    $dest = update_file('tmp') . '/src';
    rrmdir($dest);
    if (!update_dir($dest)) {
        $za->close();
        $out['error'] = '建不了临时目录：data/update-tmp/src';
        return $out;
    }

    $count = 0;
    $skipped = 0;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = (string) $za->getNameIndex($i);
        $clean = str_replace('\\', '/', $name);
        if (!str_starts_with($clean, $prefix)) {
            continue;
        }
        $rel = substr($clean, strlen($prefix));
        if ($rel === '' || str_ends_with($rel, '/')) {
            continue;                                // 目录项
        }
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
            $skipped++;
            continue;                                // 路径穿越，直接丢
        }
        if (update_is_protected($rel)) {
            $skipped++;
            continue;                                // data/ img/uploads/ 等
        }
        if ($count >= UPDATE_FILE_MAX) {
            $za->close();
            rrmdir($dest);
            $out['error'] = '压缩包里文件太多（超过 ' . UPDATE_FILE_MAX . ' 个），已中止';
            return $out;
        }

        $target = $dest . '/' . $rel;
        update_dir(dirname($target));
        $in  = $za->getStream($name);
        if ($in === false) {
            $skipped++;
            continue;
        }
        $fh = @fopen($target, 'wb');
        if ($fh === false) {
            fclose($in);
            $skipped++;
            continue;
        }
        stream_copy_to_stream($in, $fh);
        fclose($in);
        fclose($fh);
        $count++;
    }
    $za->close();

    if ($count === 0) {
        rrmdir($dest);
        $out['error'] = '压缩包里没有可用的站点文件';
        return $out;
    }

    $out['ok']    = true;
    $out['dir']   = $dest;
    $out['files'] = $count;
    return $out;
}

/* ============================ 比对 ============================ */

/**
 * 比对解压出来的站点根和本地站点，得出要新增/覆盖/删除哪些文件。
 *
 * @return array{ok: bool, files: array<int, array{path: string, state: string, local: bool, sha1: string, size: int}>,
 *               add: int, mod: int, del: array<int, string>, same: int, total: int, changes: int, error: string}
 */
function update_plan(string $dir): array
{
    $out = ['ok' => false, 'files' => [], 'add' => 0, 'mod' => 0, 'del' => [],
            'same' => 0, 'total' => 0, 'changes' => 0, 'error' => ''];

    if (!is_dir($dir)) {
        $out['error'] = '解压目录不存在';
        return $out;
    }

    $manifest = update_manifest();
    $upstream = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $info) {
        /** @var SplFileInfo $info */
        if (!$info->isFile() || $info->isLink()) {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($info->getPathname(), strlen($dir))), '/');
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        $sha1 = (string) @sha1_file($info->getPathname());
        $size = (int) $info->getSize();
        $upstream[$rel] = ['sha1' => $sha1, 'size' => $size];
        $out['total']++;

        $localPath = APP_ROOT . '/' . $rel;
        if (!is_file($localPath)) {
            $out['add']++;
            $out['files'][] = ['path' => $rel, 'state' => 'add', 'local' => false, 'sha1' => $sha1, 'size' => $size];
            continue;
        }
        $localSha = (string) @sha1_file($localPath);
        if ($localSha === $sha1) {
            $out['same']++;
            continue;
        }
        // 本地这份和「上次装的」不一样 —— 说明你自己改过，覆盖会把你的改动冲掉
        $edited = isset($manifest[$rel]) && $manifest[$rel] !== $localSha;
        $out['mod']++;
        $out['files'][] = ['path' => $rel, 'state' => 'mod', 'local' => $edited, 'sha1' => $sha1, 'size' => $size];
    }

    // 删除：只删「台账里有、上游没有」的文件
    foreach ($manifest as $rel => $sha1) {
        $rel = (string) $rel;
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        if (isset($upstream[$rel])) {
            continue;
        }
        if (!is_file(APP_ROOT . '/' . $rel)) {
            continue;
        }
        $out['del'][] = $rel;
    }
    sort($out['del']);
    usort($out['files'], static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

    $out['ok']      = true;
    $out['changes'] = $out['add'] + $out['mod'] + count($out['del']);
    return $out;
}

/* ============================ 应用 ============================ */

/** 把被覆盖的本地文件复制一份到备份目录 */
function update_backup_file(string $rel, string $backupDir): bool
{
    $src = APP_ROOT . '/' . $rel;
    if (!is_file($src)) {
        return true;                              // 本来就没有，不用备份
    }
    $dst = $backupDir . '/' . $rel;
    if (!update_dir(dirname($dst))) {
        return false;
    }
    return @copy($src, $dst);
}

/** 只留最近几份更新备份，别把磁盘塞满 */
function update_prune_backups(): void
{
    $base = APP_ROOT . '/_backup';
    if (!is_dir($base)) {
        return;
    }
    $dirs = glob($base . '/update-*', GLOB_ONLYDIR);
    if (!is_array($dirs) || count($dirs) <= UPDATE_KEEP_BACKUPS) {
        return;
    }
    usort($dirs, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($dirs, UPDATE_KEEP_BACKUPS) as $old) {
        rrmdir($old);
    }
}

/**
 * 按计划覆盖文件。$dir 是解压出来的站点根。
 *
 * @param array{files: array, del: array} $plan
 * @return array{ok: bool, add: int, mod: int, del: int, backup: string, errors: array<int, string>}
 */
function update_apply(string $dir, array $plan, array $meta = []): array
{
    $out = ['ok' => false, 'add' => 0, 'mod' => 0, 'del' => 0, 'backup' => '', 'errors' => []];

    $backupDir = APP_ROOT . '/_backup/update-' . date('Ymd-His');
    $stateDir  = update_dir($backupDir);
    $out['backup'] = $stateDir ? $backupDir : '';

    // 1) 删除上游已经没有的文件
    foreach (($plan['del'] ?? []) as $rel) {
        $rel = (string) $rel;
        if (update_is_protected($rel)) {
            continue;
        }
        if (!update_backup_file($rel, $backupDir)) {
            $out['errors'][] = '备份失败，没敢删：' . $rel;
            continue;
        }
        if (@unlink(APP_ROOT . '/' . $rel)) {
            $out['del']++;
        } else {
            $out['errors'][] = '删不掉：' . $rel;
        }
    }

    // 2) 新增 / 覆盖
    foreach (($plan['files'] ?? []) as $item) {
        $rel  = (string) ($item['path'] ?? '');
        $sha1 = (string) ($item['sha1'] ?? '');
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        $src = $dir . '/' . $rel;
        if (!is_file($src)) {
            $out['errors'][] = '压缩包里找不到：' . $rel;
            continue;
        }
        $dst = APP_ROOT . '/' . $rel;
        if (!update_dir(dirname($dst))) {
            $out['errors'][] = '建不了目录：' . dirname($rel);
            continue;
        }
        if (is_file($dst) && !update_backup_file($rel, $backupDir)) {
            $out['errors'][] = '备份失败，没敢覆盖：' . $rel;
            continue;
        }
        if (!@copy($src, $dst)) {
            // Windows 上偶尔是文件被占用，等一下再试一次
            usleep(60000);
            clearstatcache(true, $dst);
            if (!@copy($src, $dst)) {
                $out['errors'][] = '写不进去：' . $rel;
                continue;
            }
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($dst, 0644);
        }
        if ($sha1 !== '' && @sha1_file($dst) !== $sha1) {
            $out['errors'][] = '内容校验不一致：' . $rel;
            continue;
        }
        if ($item['state'] === 'add') {
            $out['add']++;
        } else {
            $out['mod']++;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dst, true);
        }
    }

    clearstatcache();

    // 记一份「这次动了哪些文件」，回滚时按它反着来
    $touched = ['add' => [], 'mod' => [], 'del' => []];
    foreach (($plan['files'] ?? []) as $item) {
        $st = (string) ($item['state'] ?? '');
        if (isset($touched[$st])) {
            $touched[$st][] = (string) $item['path'];
        }
    }
    $touched['del'] = array_map('strval', $plan['del'] ?? []);
    if ($stateDir) {
        @file_put_contents($backupDir . '/_update.json', (string) json_encode([
            'at'    => date('Y-m-d H:i:s'),
            'from'  => $meta['from'] ?? null,
            'to'    => $meta['to'] ?? null,
            'files' => $touched,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    update_prune_backups();
    $out['ok'] = $out['errors'] === [];
    return $out;
}

/** 列出现有的更新备份，最新的在前 */
function update_backup_index(): array
{
    $base = APP_ROOT . '/_backup';
    if (!is_dir($base)) {
        return [];
    }
    $dirs = glob($base . '/update-*', GLOB_ONLYDIR);
    if (!is_array($dirs)) {
        return [];
    }
    $out = [];
    foreach ($dirs as $dir) {
        $meta = [];
        $file = $dir . '/_update.json';
        if (is_file($file)) {
            $raw = json_decode((string) @file_get_contents($file), true);
            if (is_array($raw)) {
                $meta = $raw;
            }
        }
        $files = is_array($meta['files'] ?? null) ? $meta['files'] : [];
        $out[] = [
            'dir'  => basename($dir),
            'path' => $dir,
            'at'   => (string) ($meta['at'] ?? date('Y-m-d H:i:s', (int) filemtime($dir))),
            'from' => $meta['from'] ?? null,
            'to'   => $meta['to'] ?? null,
            'add'  => is_array($files['add'] ?? null) ? count($files['add']) : 0,
            'mod'  => is_array($files['mod'] ?? null) ? count($files['mod']) : 0,
            'del'  => is_array($files['del'] ?? null) ? count($files['del']) : 0,
            'ok'   => is_file($file),
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($b['dir'], $a['dir']));
    return $out;
}

/**
 * 回滚：按某份备份的记录反着来一遍。
 * 这次新增的文件删掉，这次改过/删掉的文件从备份里拷回来。
 *
 * @return array{ok: bool, restored: int, removed: int, errors: array<int, string>}
 */
function update_rollback(string $dirName): array
{
    $out = ['ok' => false, 'restored' => 0, 'removed' => 0, 'errors' => []];

    $base = realpath(APP_ROOT . '/_backup');
    $dir  = realpath(APP_ROOT . '/_backup/' . basename($dirName));
    $norm = static fn(string $p): string => str_replace('\\', '/', $p);
    if ($base === false || $dir === false || !str_starts_with($norm($dir), $norm($base) . '/')) {
        $out['errors'][] = '备份目录不合法';
        return $out;
    }
    $file = $dir . '/_update.json';
    if (!is_file($file)) {
        $out['errors'][] = '这份备份里没有 _update.json，不知道当时动了哪些文件，只能手工还原';
        return $out;
    }
    $meta = json_decode((string) @file_get_contents($file), true);
    if (!is_array($meta)) {
        $out['errors'][] = '备份记录读不出来';
        return $out;
    }
    $files = is_array($meta['files'] ?? null) ? $meta['files'] : [];

    // 这次新增的 → 删掉
    foreach ((array) ($files['add'] ?? []) as $rel) {
        $rel = (string) $rel;
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        $target = APP_ROOT . '/' . $rel;
        if (is_file($target)) {
            if (@unlink($target)) {
                $out['removed']++;
            } else {
                $out['errors'][] = '删不掉：' . $rel;
            }
        }
    }

    // 这次改过 / 删掉的 → 从备份拷回来
    foreach (array_merge((array) ($files['mod'] ?? []), (array) ($files['del'] ?? [])) as $rel) {
        $rel = (string) $rel;
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        $src = $dir . '/' . $rel;
        if (!is_file($src)) {
            $out['errors'][] = '备份里找不到：' . $rel;
            continue;
        }
        $dst = APP_ROOT . '/' . $rel;
        if (!update_dir(dirname($dst)) || !@copy($src, $dst)) {
            $out['errors'][] = '拷不回去：' . $rel;
            continue;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($dst, 0644);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dst, true);
        }
        $out['restored']++;
    }

    clearstatcache();
    $out['ok'] = $out['errors'] === [];

    // 版本号退回上一版，并在历史里记一笔
    $state = update_state();
    $from  = is_array($meta['from'] ?? null) ? $meta['from'] : null;
    $state['current'] = $from;
    $entry = [
        'at'     => date('Y-m-d H:i:s'),
        'from'   => (string) ($meta['to']['short'] ?? ''),
        'to'     => (string) ($from['short'] ?? '') . '（回滚）',
        'add'    => 0,
        'mod'    => $out['restored'],
        'del'    => $out['removed'],
        'backup' => $dir,
        'ok'     => $out['ok'],
        'errors' => array_slice($out['errors'], 0, 20),
    ];
    $history = is_array($state['history']) ? $state['history'] : [];
    array_unshift($history, $entry);
    $state['history'] = array_slice($history, 0, 10);
    $state['lastRun'] = $entry;
    update_state_save($state);

    return $out;
}

/** 按解压目录重算「本次装完的文件清单」，下次用它判断哪些是本地改过的 */
function update_install_manifest(string $dir): int
{
    $manifest = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $info) {
        /** @var SplFileInfo $info */
        if (!$info->isFile() || $info->isLink()) {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($info->getPathname(), strlen($dir))), '/');
        if ($rel === '' || update_is_protected($rel)) {
            continue;
        }
        $manifest[$rel] = (string) @sha1_file($info->getPathname());
    }
    update_manifest_save($manifest);
    return count($manifest);
}

/* ============================ 记录 ============================ */

/** 把一次更新（成功或失败）记进 state：current / lastCheck / lastRun / history */
function update_remember_check(array $head, ?array $plan, string $note = ''): void
{
    $state = update_state();
    $files = [];
    if ($plan !== null) {
        foreach (array_slice($plan['files'] ?? [], 0, 200) as $f) {
            $files[] = ['path' => $f['path'], 'state' => $f['state'], 'local' => $f['local']];
        }
    }
    $state['lastCheck'] = [
        'at'      => date('Y-m-d H:i:s'),
        'head'    => $head,
        'note'    => $note,
        'plan'    => $plan === null ? null : [
            'add'     => $plan['add'],
            'mod'     => $plan['mod'],
            'del'     => array_slice($plan['del'], 0, 200),
            'same'    => $plan['same'],
            'total'   => $plan['total'],
            'changes' => $plan['changes'],
            'files'   => $files,
            'more'    => max(0, count($plan['files']) - 200),
        ],
        'commits' => update_recent_commits(5),
    ];
    update_state_save($state);
}

/** 把当前版本直接记为远端这一版（第一次接手已有站点、或者内容本来就一致时用） */
function update_adopt(array $head, int $files): void
{
    $state = update_state();
    $state['current'] = [
        'sha'       => $head['sha'],
        'short'     => $head['short'],
        'date'      => $head['date'],
        'message'   => $head['message'],
        'appliedAt' => date('Y-m-d H:i:s'),
        'files'     => $files,
    ];
    update_state_save($state);
}

function update_remember_run(array $head, array $run, int $files): void
{
    $state = update_state();
    $from  = (string) ($state['current']['short'] ?? '');
    // 只有真装成功了才把「当前版本」推到新 sha；失败就保持旧版本，免得下次以为已是最新
    if ($run['ok']) {
        $state['current'] = [
            'sha'       => $head['sha'],
            'short'     => $head['short'],
            'date'      => $head['date'],
            'message'   => $head['message'],
            'appliedAt' => date('Y-m-d H:i:s'),
            'files'     => $files,
        ];
    }
    $state['lastRun'] = [
        'at'     => date('Y-m-d H:i:s'),
        'from'   => $from,
        'to'     => $head['short'],
        'add'    => $run['add'],
        'mod'    => $run['mod'],
        'del'    => $run['del'],
        'backup' => $run['backup'],
        'ok'     => $run['ok'],
        'errors' => array_slice($run['errors'], 0, 20),
    ];
    $history = is_array($state['history']) ? $state['history'] : [];
    array_unshift($history, $state['lastRun']);
    $state['history'] = array_slice($history, 0, 10);
    update_state_save($state);
}

/** 清掉解压用的临时目录（源码包 + 解压结果） */
function update_cleanup_tmp(): void
{
    rrmdir(update_file('tmp'));
}

/**
 * 同一时间只允许一个人跑更新。
 * 下载 10MB 上下要几十秒，这期间又点一次的话两个请求会互相踩（还会抢同一个临时文件）。
 *
 * @return array{ok: bool, handle: mixed, error: string}
 */
function update_lock_acquire(): array
{
    $out = ['ok' => false, 'handle' => null, 'error' => ''];
    $fh  = @fopen(DATA_DIR . '/update.lock', 'c');
    if ($fh === false) {
        $out['error'] = '建不了锁文件（data/ 不可写？）';
        return $out;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        $out['error'] = '上一次检查/更新还在跑（多半在下载），过十几秒再点一次';
        return $out;
    }
    $out['ok']     = true;
    $out['handle'] = $fh;
    return $out;
}

/** @param array{ok: bool, handle: mixed, error: string} $lock */
function update_lock_release(array $lock): void
{
    if (is_resource($lock['handle'] ?? null)) {
        @flock($lock['handle'], LOCK_UN);
        @fclose($lock['handle']);
    }
}


/**
 * 列 tag（按版本回退要用）。GitHub 限流时把上次的结果吐回来，页面照样能看。
 *
 * @return array{ok: bool, tags: array<int, array{name: string, sha: string, short: string}>, at: string, error: string}
 */
function update_tags_cached(): array
{
    $state  = update_state();
    $cached = is_array($state['tagList'] ?? null) ? $state['tagList'] : [];
    $r      = update_api('/repos/' . $state['repo'] . '/tags?per_page=30');
    if (!$r['ok'] || !is_array($r['json'])) {
        return ['ok' => false, 'tags' => $cached, 'at' => (string) $state['tagListAt'], 'error' => $r['error']];
    }
    $tags = [];
    foreach ($r['json'] as $item) {
        if (!is_array($item) || !isset($item['name'])) {
            continue;
        }
        $sha    = (string) ($item['commit']['sha'] ?? '');
        $tags[] = ['name' => (string) $item['name'], 'sha' => $sha, 'short' => substr($sha, 0, 7)];
    }
    $state['tagList']   = $tags;
    $state['tagListAt'] = date('Y-m-d H:i:s');
    update_state_save($state);
    return ['ok' => true, 'tags' => $tags, 'at' => $state['tagListAt'], 'error' => ''];
}

/**
 * 切到某个 tag：下载那一版的包 → 解压 → 和现在的文件比对 → 备份 → 覆盖。
 * 复用「立即更新」那套逻辑，所以受保护目录照样不动、覆盖前照样备份、切错了还能再回滚。
 *
 * @return array{ok: bool, error: string, plan: mixed, run: mixed, head: mixed}
 */
function update_switch_tag(string $tag): array
{
    $out = ['ok' => false, 'error' => '', 'plan' => null, 'run' => null, 'head' => null];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/', $tag)) {
        $out['error'] = 'tag 名不合法';
        return $out;
    }
    $head = [
        'ok'      => true,
        'sha'     => '',
        'short'   => $tag,
        'date'    => '',
        'message' => 'tag ' . $tag,
        'author'  => '',
        'url'     => '',
        'error'   => '',
    ];
    $dl = update_download('', 'refs/tags/' . $tag, true);
    if (!$dl['ok']) {
        $out['error'] = $dl['error'] . '（是不是没有这个 tag？）';
        return $out;
    }
    $ex = update_extract($dl['zip']);
    if (!$ex['ok']) {
        $out['error'] = $ex['error'];
        return $out;
    }
    $plan  = update_plan($ex['dir']);
    $state = update_state();
    $run   = update_apply($ex['dir'], $plan, ['from' => $state['current'] ?? null, 'to' => $head]);
    $files = update_install_manifest($ex['dir']);
    update_cleanup_tmp();

    $out['plan'] = $plan;
    $out['run']  = $run;
    $out['head'] = $head;
    $out['ok']   = $run['ok'];
    if (!$out['ok']) {
        $out['error'] = implode('；', array_slice($run['errors'], 0, 3));
    }
    update_remember_run($head, $run, $files);
    return $out;
}
