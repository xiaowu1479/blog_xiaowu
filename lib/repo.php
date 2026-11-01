<?php
/**
 * lib/repo.php —— 数据访问层（唯一的 SQL 出口）
 *
 * 后台和前台都只通过这里的函数取/存数据；将来换 MySQL 也只需要动这个文件和 db.php。
 */
declare(strict_types=1);

/** 各表允许写入的字段（白名单，防止表单伪造字段） */
function table_columns(): array
{
    return [
        'friends' => ['name', 'desc', 'href', 'sort', 'visible'],
        'works' => ['title', 'year', 'icon', 'desc', 'tags', 'href', 'detail', 'sort', 'visible'],
        'posts' => ['date', 'title', 'excerpt', 'content', 'href', 'sort', 'cat_id', 'visible'],
        'categories' => ['name', 'desc', 'sort', 'visible'],
        'photos' => ['src', 'caption', 'sort', 'visible'],
        'contacts' => ['grp', 'icon', 'color', 'img', 'title', 'href', 'sort', 'visible'],
    ];
}

/* ---------------- 设置（键值表） ---------------- */
function settings_all(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (db()->query('SELECT name, value FROM settings') as $r) {
        $cache[$r['name']] = $r['value'];
    }
    return $cache;
}

function setting_get(string $name, string $default = ''): string
{
    $all = settings_all();
    return array_key_exists($name, $all) ? (string)$all[$name] : $default;
}

function setting_set(string $name, string $value): void
{
    db()->prepare('INSERT INTO settings(name, value) VALUES(?, ?)
                   ON CONFLICT(name) DO UPDATE SET value = excluded.value')
        ->execute([$name, $value]);
}

function setting_set_many(array $values): void
{
    tx(function () use ($values) {
        foreach ($values as $k => $v) setting_set((string)$k, (string)$v);
    });
}

/* ---------------- 通用表操作 ---------------- */
/** 列表；$onlyVisible = true 时只取前台可见项 */
function rows(string $table, bool $onlyVisible = false): array
{
    $sql = 'SELECT * FROM ' . $table;
    if ($onlyVisible) $sql .= ' WHERE visible = 1';
    $sql .= ' ORDER BY sort ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

function row_one(string $table, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM ' . $table . ' WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** 插入或更新，返回 id；$data 里的字段会按白名单过滤 */
function row_save(string $table, array $data, ?int $id = null): int
{
    $cols = table_columns()[$table] ?? [];
    $data = array_intersect_key($data, array_flip($cols));
    if (!$data) return 0;

    if ($id) {
        $set = implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($data)));
        $params = array_values($data);
        $params[] = $id;
        db()->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?')->execute($params);
        return $id;
    }

    $names = array_keys($data);
    $sql = 'INSERT INTO ' . $table . '(' . implode(',', $names) . ') VALUES('
         . implode(',', array_fill(0, count($names), '?')) . ')';
    db()->prepare($sql)->execute(array_values($data));
    return (int)db()->lastInsertId();
}

function row_delete(string $table, int $id): void
{
    db()->prepare('DELETE FROM ' . $table . ' WHERE id = ?')->execute([$id]);
}

/** 切换显示/隐藏 */
function row_toggle(string $table, int $id): void
{
    db()->prepare('UPDATE ' . $table . ' SET visible = 1 - visible WHERE id = ?')->execute([$id]);
}

/** 上移/下移：与相邻项交换 sort */
function row_move(string $table, int $id, string $dir): void
{
    $cur = row_one($table, $id);
    if (!$cur) return;
    $op = $dir === 'up' ? '<' : '>';
    $order = $dir === 'up' ? 'DESC' : 'ASC';
    $st = db()->prepare('SELECT * FROM ' . $table . ' WHERE sort ' . $op . ' ? OR (sort = ? AND id ' . $op . ' ?)
                         ORDER BY sort ' . $order . ', id ' . $order . ' LIMIT 1');
    $st->execute([$cur['sort'], $cur['sort'], $id]);
    $other = $st->fetch();
    if (!$other) return;

    tx(function () use ($table, $cur, $other) {
        $a = (int)$cur['sort'];
        $b = (int)$other['sort'];
        if ($a === $b) {
            // 同序号时用 id 兜底，避免交换后顺序没变化
            $b = $a + ($a <= $b ? 1 : -1);
        }
        db()->prepare('UPDATE ' . $table . ' SET sort = ? WHERE id = ?')->execute([$b, (int)$cur['id']]);
        db()->prepare('UPDATE ' . $table . ' SET sort = ? WHERE id = ?')->execute([$a, (int)$other['id']]);
    });
}

/** 新增时自动接在最后 */
function next_sort(string $table): int
{
    return (int)db()->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . $table)->fetchColumn();
}

/* ---------------- 文本/结构化字段互转 ---------------- */
/** "CSS|#f70808" 每行一条 → [['label'=>'CSS','color'=>'#f70808'], ...] */
function parse_pairs(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line));
        $out[] = $parts;
    }
    return $out;
}

/* ---------------- 前台数据（api.php 用） ---------------- */
/* ---------------- 博客：搜索 / 分页 / 单篇 ---------------- */

/** LIKE 里的 % _ \ 要转义，否则用户搜 "100%" 会变成通配 */
function like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/* ---------------- 博客：分类 ---------------- */
/** id => 分类行（一次查询，后面按 id 取名字用） */
function categories_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (db()->query('SELECT * FROM categories ORDER BY sort ASC, id ASC') as $c) {
        $map[(int)$c['id']] = $c;
    }
    return $map;
}

/** 前台可用的分类（含各分类下的文章数）；$onlyVisible = false 时后台也能用 */
function categories_list(bool $onlyVisible = true): array
{
    $counts = [];
    foreach (db()->query('SELECT cat_id, COUNT(*) AS n FROM posts WHERE visible = 1 GROUP BY cat_id') as $r) {
        $counts[(int)$r['cat_id']] = (int)$r['n'];
    }
    $out = [];
    foreach (categories_map() as $id => $c) {
        if ($onlyVisible && (int)$c['visible'] !== 1) continue;
        $out[] = [
            'id'      => $id,
            'name'    => (string)$c['name'],
            'desc'    => (string)$c['desc'],
            'count'   => $counts[$id] ?? 0,
            'visible' => (int)$c['visible'],
            'sort'    => (int)$c['sort'],
        ];
    }
    return $out;
}

/** 把正文压成一行纯文本，用作没填摘要时的兜底 */
function post_plain(string $text, int $limit = 120): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($len <= $limit) return $text;
    return (function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit)) . '…';
}

function post_payload(array $row, bool $withContent = false): array
{
    $excerpt = trim((string)$row['excerpt']);
    if ($excerpt === '') $excerpt = post_plain((string)$row['content'], 100);

    $cat = null;
    $cid = (int)($row['cat_id'] ?? 0);
    if ($cid > 0) {
        $c = categories_map()[$cid] ?? null;
        if ($c !== null && (int)$c['visible'] === 1) {
            $cat = ['id' => $cid, 'name' => (string)$c['name']];
        }
    }

    $out = [
        'id'      => (int)$row['id'],
        'date'    => (string)$row['date'],
        'title'   => (string)$row['title'],
        'excerpt' => $excerpt,
        'href'    => $row['href'] !== '' ? (string)$row['href'] : null,
        'cat'     => $cat,
    ];
    if ($withContent) $out['content'] = (string)$row['content'];
    return $out;
}

/**
 * 文章列表：关键词搜索 + 分页（只出可见文章）
 * 排序和前台一致：日期倒序，同日期按 sort、再按 id
 */
function posts_page(string $q = '', int $page = 1, int $per = 8, int $catId = 0): array
{
    $per = max(1, min($per, 30));
    $page = max(1, $page);
    $q = trim($q);
    $catId = max(0, $catId);

    $where = 'WHERE visible = 1';
    $params = [];
    if ($q !== '') {
        $where .= " AND (title LIKE ? ESCAPE '\\' OR excerpt LIKE ? ESCAPE '\\' OR content LIKE ? ESCAPE '\\')";
        $like = '%' . like_escape($q) . '%';
        $params = [$like, $like, $like];
    }
    if ($catId > 0) {
        $where .= ' AND cat_id = ?';
        $params[] = $catId;
    }

    $st = db()->prepare('SELECT COUNT(*) FROM posts ' . $where);
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per;

    $st = db()->prepare(
        'SELECT * FROM posts ' . $where
        . ' ORDER BY date DESC, sort ASC, id DESC LIMIT ' . $per . ' OFFSET ' . $offset
    );
    $st->execute($params);

    $items = [];
    foreach ($st->fetchAll() as $row) $items[] = post_payload($row);

    return ['total' => $total, 'page' => $page, 'per' => $per, 'pages' => $pages, 'q' => $q, 'cat' => $catId,
            'categories' => categories_list(),
            'totalAll' => (int)db()->query('SELECT COUNT(*) FROM posts WHERE visible = 1')->fetchColumn(),
            'items' => $items];
}

function post_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM posts WHERE id = ? AND visible = 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? post_payload($row, true) : null;
}

/** 上一篇 / 下一篇：按列表顺序，prev 是更新的一篇，next 是更早的一篇 */
function post_neighbors(int $id): array
{
    $rows = db()->query('SELECT id, title FROM posts WHERE visible = 1 ORDER BY date DESC, sort ASC, id DESC')->fetchAll();
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === $id) {
            return [
                'prev' => isset($rows[$i - 1]) ? ['id' => (int)$rows[$i - 1]['id'], 'title' => (string)$rows[$i - 1]['title']] : null,
                'next' => isset($rows[$i + 1]) ? ['id' => (int)$rows[$i + 1]['id'], 'title' => (string)$rows[$i + 1]['title']] : null,
            ];
        }
    }
    return ['prev' => null, 'next' => null];
}

function site_payload(): array
{
    $skills = [];
    foreach (parse_pairs(setting_get('skills')) as $p) {
        if (($p[0] ?? '') === '') continue;
        $skills[] = ['label' => $p[0], 'icon' => 'fa-circle-check', 'color' => $p[1] ?? '#63e6be'];
    }

    $aboutLines = [];
    foreach (parse_pairs(setting_get('about_lines')) as $p) {
        if (count($p) < 3) continue;
        $aboutLines[] = ['icon' => $p[0], 'color' => $p[1], 'text' => $p[2]];
    }

    $sites = [];
    foreach (preg_split('/\R/u', setting_get('sites')) as $line) {
        $line = trim($line);
        if ($line !== '') $sites[] = $line;
    }

    $pay = [];
    if (($w = setting_get('pay_wechat')) !== '') $pay[] = ['label' => '微信赞赏', 'icon' => 'fa-brands fa-weixin', 'src' => $w];
    if (($a = setting_get('pay_alipay')) !== '') $pay[] = ['label' => '支付宝', 'icon' => 'fa-brands fa-alipay', 'src' => $a];

    $contacts = [];
    $quick = [];
    foreach (rows('contacts', true) as $c) {
        if ($c['grp'] === 'quick') {
            $quick[] = ['img' => $c['img'], 'title' => $c['title'], 'href' => $c['href'] !== '' ? $c['href'] : null];
        } else {
            $contacts[] = [
                'icon' => $c['icon'],
                'color' => $c['color'] !== '' ? $c['color'] : '#141414',
                'title' => $c['title'],
                'href' => $c['href'] !== '' ? $c['href'] : null,
            ];
        }
    }

    $works = [];
    foreach (rows('works', true) as $w) {
        $tags = array_values(array_filter(array_map('trim', preg_split('/[,，、]/u', (string)$w['tags']))));
        $works[] = [
            'title' => $w['title'],
            'year' => $w['year'],
            'icon' => $w['icon'] !== '' ? $w['icon'] : 'fa-solid fa-code',
            'desc' => $w['desc'],
            'tags' => $tags,
            'href' => $w['href'] !== '' ? $w['href'] : null,
            'detail' => $w['detail'],
        ];
    }

    // 首页只带最新 3 篇：文章再多，首页的高度和数据量都不跟着涨。
    // 完整列表和搜索走 api.php?posts=1，正文走 api.php?post=ID。
    $allPosts = db()->query('SELECT * FROM posts WHERE visible = 1 ORDER BY date DESC, sort ASC, id DESC')->fetchAll();
    $posts = [];
    foreach (array_slice($allPosts, 0, 3) as $p) $posts[] = post_payload($p);
    $postsTotal = count($allPosts);

    $photos = [];
    foreach (rows('photos', true) as $p) {
        $photos[] = ['src' => $p['src'] !== '' ? $p['src'] : null, 'caption' => $p['caption']];
    }

    $friends = [];
    foreach (rows('friends', true) as $f) {
        $friends[] = [
            'name' => $f['name'],
            'desc' => $f['desc'],
            'href' => $f['href'] !== '' ? $f['href'] : null,
        ];
    }

    return [
        'name' => setting_get('name', '小悟大人'),
        'sign' => setting_get('sign'),
        'status' => setting_get('status'),
        'avatar' => setting_get('avatar', 'img/avatar.svg'),
        'avatarText' => setting_get('avatar_text', '悟'),
        'favicon' => setting_get('favicon', DEFAULT_FAVICON) ?: DEFAULT_FAVICON,
        'music' => [
            'src' => setting_get('music_src', DEFAULT_MUSIC_SRC),
            'title' => setting_get('music_title', DEFAULT_MUSIC_TITLE),
            'cover' => setting_get('music_cover', DEFAULT_MUSIC_COVER),
        ],
        'skills' => $skills,
        'about' => ['version' => setting_get('about_version'), 'lines' => $aboutLines],
        'pay' => $pay,
        'contacts' => $contacts,
        'quick' => $quick,
        'works' => $works,
        'posts' => $posts,
        'postsTotal' => $postsTotal,
        'categories' => categories_list(),
        'photos' => $photos,
        'friends' => $friends,
        'sites' => $sites,
        'footer' => [
            'icp' => setting_get('icp_text'),
            'icpHref' => setting_get('icp_href'),
            'since' => setting_get('since'),
            'year' => setting_get('year'),
        ],
        'lockInspect' => setting_get('lock_inspect') === '1',
        'welcomeToast' => setting_get('welcome_toast') === '1',
    ];
}
