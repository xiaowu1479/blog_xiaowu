<?php
/**
 * lib/db.php —— SQLite 连接、建表、首次运行初始化
 *
 * 为什么用 SQLite：零配置（不用建库建账号）、有事务和索引、备份＝拷一个文件。
 * 换 MySQL 只需改下面的 DSN 和几处 AUTOINCREMENT 语法（SQL 本身是通用的）。
 */
declare(strict_types=1);

function db_file(): string
{
    return DATA_DIR . '/site.db';
}

/** 已安装但库文件不见了 —— 入口处用它给出明确提示，而不是让用户看到通用 500 */
function db_missing(): bool
{
    return is_installed() && !is_file(db_file());
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0755, true)) {
        throw new RuntimeException('data/ 目录不存在且无法创建');
    }

    $fresh = !is_file(db_file());

    // 已安装但库文件不见了：宁可报错，也不要静默重建出一个带默认密码的空站
    if ($fresh && is_installed()) {
        throw new RuntimeException(
            '数据库文件 data/site.db 丢失。请从备份恢复；'
            . '若确实要重装，先删除 data/install.lock 再访问 install.php。'
        );
    }

    $pdo = new PDO('sqlite:' . db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    db_install($pdo, $fresh);
    return $pdo;
}

/** 事务包装 */
function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 建表 + 首次灌入默认数据 */
function db_install(PDO $pdo, bool $fresh): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        name  TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT \'\'
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS friends (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        name   TEXT NOT NULL DEFAULT \'\',
        desc   TEXT NOT NULL DEFAULT \'\',
        href   TEXT NOT NULL DEFAULT \'\',
        sort   INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS works (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        title  TEXT NOT NULL DEFAULT \'\',
        year   TEXT NOT NULL DEFAULT \'\',
        icon   TEXT NOT NULL DEFAULT \'fa-solid fa-code\',
        desc   TEXT NOT NULL DEFAULT \'\',
        tags   TEXT NOT NULL DEFAULT \'\',
        href   TEXT NOT NULL DEFAULT \'\',
        detail TEXT NOT NULL DEFAULT \'\',
        sort   INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        date    TEXT NOT NULL DEFAULT \'\',
        title   TEXT NOT NULL DEFAULT \'\',
        excerpt TEXT NOT NULL DEFAULT \'\',
        content TEXT NOT NULL DEFAULT \'\',
        href    TEXT NOT NULL DEFAULT \'\',
        sort    INTEGER NOT NULL DEFAULT 0,
        cat_id  INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1
    )');

    // 分类（借鉴 bbs1 的「版块」：名称 + 简介 + 排序 + 显隐）
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        name    TEXT NOT NULL DEFAULT \'\',
        desc    TEXT NOT NULL DEFAULT \'\',
        sort    INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS photos (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        src     TEXT NOT NULL DEFAULT \'\',
        caption TEXT NOT NULL DEFAULT \'\',
        sort    INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1
    )');

    // grp: social（左侧栏社交图标）| quick（名片卡里的两个快捷入口）
    $pdo->exec('CREATE TABLE IF NOT EXISTS contacts (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        grp    TEXT NOT NULL DEFAULT \'social\',
        icon   TEXT NOT NULL DEFAULT \'\',
        color  TEXT NOT NULL DEFAULT \'\',
        img    TEXT NOT NULL DEFAULT \'\',
        title  TEXT NOT NULL DEFAULT \'\',
        href   TEXT NOT NULL DEFAULT \'\',
        sort   INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1
    )');

    $count = (int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE name='admin_hash'")->fetchColumn();

    // 老库补列：SQLite 没有 ADD COLUMN IF NOT EXISTS，只能先看表结构
    $postCols = [];
    foreach ($pdo->query('PRAGMA table_info(posts)') as $c) $postCols[] = (string)$c['name'];
    if (!in_array('cat_id', $postCols, true)) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN cat_id INTEGER NOT NULL DEFAULT 0');
    }

    // 分类只播种一次：用 settings 里的标记记住，删光了也不会被重新灌回来
    if ((int)$pdo->query("SELECT COUNT(*) FROM settings WHERE name = 'cat_seeded'")->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO categories(name, desc, sort, visible) VALUES(?, ?, ?, 1)');
        foreach ([
            ['随笔', '想到什么写什么', 1],
            ['前端', 'HTML / CSS / JS 这些', 2],
            ['折腾', '部署、工具、踩坑记录', 3],
        ] as $c) $st->execute($c);
        $pdo->exec("INSERT OR REPLACE INTO settings(name, value) VALUES('cat_seeded', '1')");
    }

    if ($count === 0 || $hasAdmin === 0) {
        db_seed($pdo);
    }
}

/** 首次安装：写入默认设置与示例内容（与改造前 js/site.js 里的内容保持一致） */
function db_seed(PDO $pdo): void
{
    $settings = [
        // 后台账号（首次登录 admin / admin123，请立刻在后台改掉）
        'admin_user' => 'admin',
        'admin_hash' => password_hash('admin123', PASSWORD_DEFAULT),

        // 名片
        'name' => '小悟大人',
        'sign' => '天黑了.别忘记回家',
        'status' => 'iPhone 在线',
        'avatar' => 'img/avatar.svg',
        'avatar_text' => '悟',

        // 技能标签：每行 "标签|颜色"
        'skills' => "CSS|#f70808\nJava|#63e6be\nJS|#74C0FC\nAndroid|#ea875e",

        // 关于弹窗
        'about_version' => 'v3.0 · 二开版',
        'about_lines' => "fa-regular fa-comment|#63e6be|相信未来皆有可能。\nfa-regular fa-user|#74C0FC|独特吧",

        // 赞赏码
        'pay_wechat' => 'img/zanshang.png',
        'pay_alipay' => 'img/zfb.png',

        // 旗下站点：每行一个
        'sites' => "本站 · 小悟大人的个人主页\n在这里列出你的其他站点，一行一个",

        // 页脚
        'icp_text' => '苏ICP备',
        'icp_href' => 'https://beian.miit.gov.cn/#/Integrated/index',
        'since' => '2020-01-07T00:00:00',
        'year' => '2026',

        // 行为开关
        'lock_inspect' => '0',
        'welcome_toast' => '1',
    ];

    $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings(name, value) VALUES(?, ?)');
    foreach ($settings as $k => $v) $stmt->execute([$k, $v]);

    $friends = [
        ['独特吧', '模板原作者', '', 1],
        ['GitHub', '代码托管', 'https://github.com', 2],
        ['MDN', '前端文档', 'https://developer.mozilla.org', 3],
        ['待添加', '替换成朋友的小站', '', 4],
    ];
    $stmt = $pdo->prepare('INSERT INTO friends(name, desc, href, sort, visible) VALUES(?, ?, ?, ?, 1)');
    foreach ($friends as $f) $stmt->execute($f);

    $works = [
        ['个人主页 v3.0', '2026', 'fa-solid fa-code', '当前这个站点。由网上的单页模板二开而来，左侧栏布局 + 板块化内容。', 'HTML,CSS,JS', '#top', '', 1],
        ['博客系统', '2025', 'fa-solid fa-feather-pointed', '轻量博客，文章用 Markdown 写，PHP + MySQL 存数据，带标签与全文搜索。', 'PHP,MySQL', '', "这里写项目详情：技术选型、遇到的坑、上线后的数据。\n\n没有链接的作品点击后会弹出详情弹窗。", 2],
        ['图片压缩工具', '2025', 'fa-solid fa-image', '批量压缩 / 转 WebP 的小工具，纯本地处理不上传，拖进去就能用。', 'Node.js,Sharp', '', '命令行 + 网页双入口，支持批量、支持目录递归。', 3],
        ['待填坑', '—', 'fa-solid fa-flask', '占位示例：把你的下一个项目写在这里，或者直接在后台删掉这一条。', 'Idea', '', '', 4],
    ];
    $stmt = $pdo->prepare('INSERT INTO works(title, year, icon, desc, tags, href, detail, sort, visible) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($works as $w) $stmt->execute($w);

    $posts = [
        ['2026-03-12', '从模板二开说起：先把 CSS 拆干净',
            '拿到一个好看的模板，第一件事不是往上堆功能，而是把样式层理清楚。这篇记录了我怎么拆的。',
            "拿到一个好看的模板，第一件事不是往上堆功能，而是把样式层理清楚。\n\n这个模板的问题很典型：一个 index.html 里同时塞了 <style>、<script> 和结构，正文宽度写死 300px，所有内容挤在一张白卡里。想加板块就没地方放了。\n\n我做的处理是三步：\n1. 原 style.css 一个字不改，作为「基线」保留；\n2. 新建 site.css，用设计令牌（CSS 变量）在其上做覆盖和扩展；\n3. 把新增的组件统一用 card-ui / sec / work / post 这套命名，方便以后替换。\n\n这样即使哪天想回到原版，把 site.css 一删就回去了。", '', 1],
        ['2026-02-28', '深浅色模式的一种低成本实现',
            '不用 CSS-in-JS，也不用两套样式表，靠 body 上的一个类 + 一组 CSS 变量就够了。',
            "不用 CSS-in-JS，也不用两套样式表，靠 body 上的一个类 + 一组 CSS 变量就够了。\n\n模板原本就有 --dark-mode-body / --dark-mode-text-color 两个变量，我在此基础上补了 card、line、muted、accent 几个，然后：\n\n```css\nbody { --ui-card: #fefefe; }\nbody.dark-mode { --ui-card: #243044; }\n```\n\n组件里一律用 `var(--ui-card)`，切换时浏览器自己重算，零 JS 逻辑。\n\n> 这篇的正文是用 Markdown 写的，所以上面有代码块和引用块——后台编辑器支持工具栏和实时预览。", '', 2],
        ['2026-01-15', '纯前端做相册灯箱，20 行够了',
            '复用页面上已有的 Bootstrap 弹窗组件，不用再引 lightbox 库。',
            "复用页面上已有的 Bootstrap 弹窗组件，不用再引 lightbox 库。\n\n思路：相册缩略图点击时，把图片地址塞进弹窗里的 <img>，然后弹出即可。好处是不增加依赖、样式和站点统一。", '', 3],
        ['2025-12-01', '小站上线一年记',
            '从抄模板到自己改写，聊聊这一年换过的三套皮肤。',
            "从抄模板到自己改写，聊聊这一年换过的三套皮肤。\n\n（这里是正文示例，替换成你自己的内容即可。）", '', 4],
    ];
    // 示例文章分到默认分类里（分类在 db_install 里已经播种）
    $catByName = [];
    foreach ($pdo->query('SELECT id, name FROM categories') as $c) $catByName[(string)$c['name']] = (int)$c['id'];
    $postCats = ['随笔', '前端', '前端', '随笔'];
    $stmt = $pdo->prepare('INSERT INTO posts(date, title, excerpt, content, href, sort, cat_id, visible) VALUES(?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($posts as $i => $p) {
        $stmt->execute([...$p, $catByName[$postCats[$i] ?? ''] ?? 0]);
    }

    $photos = [
        ['css/resources/2.jpg', '背景素材', 2],
        ['', '待补充', 3],
        ['', '待补充', 4],
        ['', '待补充', 5],
        ['', '待补充', 6],
    ];
    $stmt = $pdo->prepare('INSERT INTO photos(src, caption, sort, visible) VALUES(?, ?, ?, 1)');
    foreach ($photos as $ph) $stmt->execute($ph);

    $contacts = [
        ['social', 'fa-brands fa-qq', '#a0d5ff', '', 'QQ', 'tencent://message/?uin=3227350433', 1],
        ['social', 'fa-regular fa-envelope', '#ffc19c', '', '邮箱', '', 2],
        ['social', 'fa-brands fa-square-x-twitter', '#141414', '', 'X', '', 3],
        ['social', 'fa-brands fa-twitter', '#f4a0c0', '', 'Twitter', '', 4],
        ['quick', '', '', 'img/github-alt.svg', 'GitHub', '', 1],
        ['quick', '', '', 'img/octopus-deploy.svg', '部署', '', 2],
    ];
    $stmt = $pdo->prepare('INSERT INTO contacts(grp, icon, color, img, title, href, sort, visible) VALUES(?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($contacts as $c) $stmt->execute($c);
}
