<?php
/**
 * admin/index.php —— 后台（单入口）
 *
 * 路由：index.php?p=<页面>&do=<动作>
 *   p=login | dashboard | settings | friends | works | posts | photos | contacts | password
 * 所有写操作一律 POST + CSRF 校验，成功后 302 回列表（PRG，避免刷新重复提交）。
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/repo.php';
require dirname(__DIR__) . '/lib/updater.php';
require __DIR__ . '/update.php';

// 后台列表不进浏览器缓存：删掉一条后刷新或重启服务器再打开，不能又看到旧列表（heuristic cache）
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

// 还没安装：先去安装页（后台依赖库里的管理员账号，装之前什么都做不了）
if (!is_installed()) go('../install.php');

// 已安装但库丢了：宁可停下来说清楚，也不要让人对着登录页反复试密码
if (db_missing()) {
    err('数据库文件 data/site.db 丢失。请从备份恢复；'
        . '若确实要重装，先删除 data/install.lock，再访问 install.php。', 500);
}

/* ============================ 实体定义 ============================ */
function entity_defs(): array
{
    return [
        'friends' => [
            'title' => '友链',
            'icon' => 'fa-solid fa-link',
            'hint' => '留在前台显示的顺序由「排序」决定，数字小的在前。',
            'fields' => [
                ['name', '名称', 'text', true],
                ['desc', '一句话描述', 'text', false, '例如「前端文档」'],
                ['href', '链接', 'text', false, '留空则前台显示为不可点状态'],
                ['sort', '排序', 'int'],
                ['visible', '前台显示', 'bool'],
            ],
            'list' => ['name', 'desc', 'href'],
            'listHead' => ['name' => '名称', 'desc' => '描述', 'href' => '链接'],
        ],
        'works' => [
            'title' => '作品集',
            'icon' => 'fa-solid fa-code',
            'hint' => '「详情」填了内容，卡片右侧会出现「详情」按钮；「链接」留空时点整张卡片也能打开详情。图标可以填 Font Awesome 类名，也可以上传 / 填写图片地址。',
            'fields' => [
                ['title', '标题', 'text', true],
                ['year', '年份', 'text', false, '例如 2026 或 —'],
                ['icon', '图标', 'icon', false, 'Font Awesome 类名（如 fa-solid fa-image），或图片地址（img/logo.svg / https://…）；也可以直接上传'],
                ['desc', '简介', 'text', false, '一两句话，卡片上显示'],
                ['tags', '标签', 'text', false, '用逗号分隔，如 HTML,CSS,JS'],
                ['href', '链接', 'text', false, '留空则前台点击弹详情'],
                ['detail', '详情', 'textarea', false, '换行会保留，适合写长说明'],
                ['sort', '排序', 'int'],
                ['visible', '前台显示', 'bool'],
            ],
            'list' => ['title', 'year', 'desc'],
            'listHead' => ['title' => '标题', 'year' => '年份', 'desc' => '简介'],
            'listWidth' => ['year' => '76px'],
        ],
        'posts' => [
            'title' => '博客',
            'icon' => 'fa-solid fa-pen-nib',
            'hint' => '正文用 Markdown 写（工具栏可插语法，右边实时预览）。每篇文章在前台都有独立页面（post.html?id=…），列表页 blog.html 支持关键词搜索和分类筛选。',
            'fields' => [
                ['date', '日期', 'text', false, '留空则自动填今天，如 2026-03-12'],
                ['title', '标题', 'text', true],
                ['cat_id', '分类', 'cat', false, '分类在左侧「分类」页里增删'],
                ['excerpt', '摘要', 'textarea', false, '列表上显示两行；留空自动截正文前 100 字', 3],
                ['content', '正文（Markdown）', 'markdown', false, '支持 # 标题、**粗体**、`行内代码`、- 列表、> 引用、```代码块```、[链接](https://…)、![图片](img/x.jpg)', 18],
                ['href', '外链', 'text', false, '填了就跳转外链，不弹窗'],
                ['sort', '排序', 'int'],
                ['visible', '前台显示', 'bool'],
            ],
            'list' => ['date', 'title', 'excerpt'],
            // 固定布局下这几列定宽，剩下的 title / excerpt 平分剩余宽度（日期列给宽是浪费）
            'listWidth' => ['date' => '92px'],
            'listHead' => ['date' => '日期', 'title' => '标题', 'excerpt' => '摘要'],
        ],
        'categories' => [
            'title' => '分类',
            'icon' => 'fa-solid fa-folder-tree',
            'hint' => '博客的分类（对标 bbs1 的「版块」）。前台博客页顶部会按排序显示这些分类，点一下只看这个分类的文章。删除分类不会删文章，那些文章会变成「未分类」。',
            'fields' => [
                ['name', '名称', 'text', true, '如「随笔」「前端」'],
                ['desc', '简介', 'text', false, '一句话说明，前台鼠标悬停时显示'],
                ['sort', '排序', 'int'],
                ['visible', '前台显示', 'bool'],
            ],
            'list' => ['name', 'desc'],
            'listWidth' => ['name' => '140px'],
            'listHead' => ['name' => '名称', 'desc' => '简介'],
        ],
        'photos' => [
            'title' => '相册',
            'icon' => 'fa-solid fa-images',
            'hint' => '「图片地址」留空会渲染成「待补充」占位格。可以直接上传。',
            'fields' => [
                ['src', '图片地址', 'upload', false, '上传后自动填入；也可手填 img/xxx.jpg'],
                ['caption', '图说', 'text', false],
                ['sort', '排序', 'int'],
                ['visible', '前台显示', 'bool'],
            ],
            'list' => ['src', 'caption'],
            'listHead' => ['src' => '图片', 'caption' => '图说'],
        ],
        'contacts' => [
            'title' => '社交图标',
            'icon' => 'fa-solid fa-share-nodes',
            'hint' => 'quick 分组显示在名片卡右上角的两个方形入口；social 分组显示在首页右下角的浮动图标栏（勾了「前台显示」才出现，链接留空会显示成半透明占位）。',
            'fields' => [
                ['grp', '分组', 'select', true, '', ['social' => 'social（右下角浮动）', 'quick' => 'quick（快捷入口）']],
                ['icon', '图标', 'text', false, 'FontAwesome 类名，如 fa-brands fa-qq（social 用）'],
                ['color', '图标颜色', 'text', false, '如 #a0d5ff（social 用）'],
                ['img', '图片地址', 'upload', false, 'quick 分组用图片，如 img/github-alt.svg'],
                ['title', '名称', 'text', true, '同时作为鼠标悬停提示'],
                ['href', '链接', 'text', false, '留空则显示为灰色不可点'],
                ['sort', '排序', 'int'],
                ['visible', '前台显示', 'bool'],
            ],
            'list' => ['grp', 'title', 'icon', 'href'],
            'listHead' => ['grp' => '分组', 'title' => '名称', 'icon' => '图标', 'href' => '链接'],
            'listWidth' => ['grp' => '80px', 'icon' => '160px'],
        ],
    ];
}

/* ============================ 登录 ============================ */
function handle_login(): void
{
    $ip = client_ip();
    login_guard($ip);

    $user = post_str('username', 100);
    $pass = (string)($_POST['password'] ?? '');
    $hash = setting_get('admin_hash');

    if ($user === '' || $pass === '' || !hash_equals(setting_get('admin_user', 'admin'), $user)
        || $hash === '' || !password_verify($pass, $hash)) {
        login_record_fail($ip);
        flash('账号或密码错误', 'err');
        go('index.php?p=login');
    }

    login_clear_fail($ip);
    auth_set(1, $hash);
    flash('欢迎回来，' . $user);
    go('index.php');
}

function login_view(): void
{
    $msg = flash_take();
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 · 后台</title>
<link rel="stylesheet" href="../css/all.min.css">
<link rel="stylesheet" href="../css/md.css">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-body">
<form class="login-card" method="post" action="index.php?p=login">
  <h1><i class="fa-solid fa-lock"></i> 后台登录</h1>
  <?php if ($msg): ?><div class="flash <?= h($msg['t']) ?>"><?= h($msg['m']) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <label>账号<input type="text" name="username" autocomplete="username" autofocus></label>
  <label>密码<input type="password" name="password" autocomplete="current-password"></label>
  <button type="submit" class="btn-primary">登录</button>
  <p class="login-tip">默认账号 <code>admin</code> / <code>admin123</code>，登录后请立刻在「修改密码」里改掉。</p>
</form>
</body>
</html><?php
}

/* ============================ 动作 ============================ */
function handle_settings(): void
{
    $scalars = [
        'name' => 120, 'sign' => 200, 'status' => 120, 'avatar' => 300, 'avatar_text' => 4,
        'about_version' => 120, 'icp_text' => 120, 'icp_href' => 300, 'since' => 40, 'year' => 20,
        'music_src' => 500, 'music_title' => 120, 'music_cover' => 300,
    ];
    $values = [];
    foreach ($scalars as $k => $max) $values[$k] = post_str($k, $max);

    $values['skills'] = post_str('skills', 4000);
    $values['about_lines'] = post_str('about_lines', 4000);
    $values['sites'] = post_str('sites', 4000);
    $values['pay_wechat'] = post_str('pay_wechat', 300);
    $values['pay_alipay'] = post_str('pay_alipay', 300);
    $values['lock_inspect'] = post_bool('lock_inspect') ? '1' : '0';
    $values['welcome_toast'] = post_bool('welcome_toast') ? '1' : '0';

    if ($values['name'] === '') err('站点名称不能为空');

    // 头像 / 赞赏码 / 音乐封面可以直接传图（文件名和表单里的 file_<字段名> 对应）
    foreach (['avatar', 'pay_wechat', 'pay_alipay', 'music_cover'] as $key) {
        $path = save_upload('file_' . $key);
        if ($path !== null) $values[$key] = $path;
    }

    // 首页音乐也可以直接传音频文件；上传的文件优先于文本框里的地址
    $audio = save_audio('file_music_src');
    if ($audio !== null) $values['music_src'] = $audio;

    setting_set_many($values);
    flash('站点信息已保存');
    go('index.php?p=settings');
}

function handle_password(): void
{
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $again = (string)($_POST['new_password2'] ?? '');

    if (!password_verify($old, setting_get('admin_hash'))) {
        flash('当前密码不正确', 'err');
        go('index.php?p=password');
    }
    if (mb_strlen($new) < 8) {
        flash('新密码至少 8 位', 'err');
        go('index.php?p=password');
    }
    if ($new !== $again) {
        flash('两次输入的新密码不一致', 'err');
        go('index.php?p=password');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    setting_set('admin_hash', $hash);
    auth_set(1, $hash);           // 旧 Cookie 因签名密钥变化而全部失效，这里给当前浏览器续一个
    flash('密码已更新，其他设备需重新登录');
    go('index.php?p=password');
}

function handle_entity(string $table): void
{
    $defs = entity_defs();
    $def = $defs[$table];
    $do = get_str('do', 'save');
    $id = get_int('id');

    if ($do === 'delete') {
        if (!$id) err('缺少 id');
        // 删分类不删文章：把文章退回「未分类」，免得它们从列表里消失
        if ($table === 'categories') {
            db()->prepare('UPDATE posts SET cat_id = 0 WHERE cat_id = ?')->execute([$id]);
        }
        row_delete($table, $id);
        flash('已删除');
        go('index.php?p=' . $table);
    }

    if ($do === 'toggle') {
        if (!$id) err('缺少 id');
        row_toggle($table, $id);
        go('index.php?p=' . $table);
    }

    if ($do === 'up' || $do === 'down') {
        if (!$id) err('缺少 id');
        row_move($table, $id, $do);
        go('index.php?p=' . $table);
    }

    // 保存
    $data = [];
    foreach ($def['fields'] as $f) {
        [$key, $label, $type] = $f;
        $data[$key] = match ($type) {
            'int' => post_int($key, 0, 0, 99999),
            'bool' => post_bool($key),
            'textarea', 'markdown' => post_str($key, 20000),
            'select' => post_str($key, 20),
            'cat' => post_int($key, 0, 0, 999999),
            default => post_str($key, 500),
        };
    }

    // 发文不填日期就自动记今天（和 bbs1 一样，省得每次手打）
    if ($table === 'posts' && trim((string)($data['date'] ?? '')) === '') {
        $data['date'] = date('Y-m-d');
    }

    foreach ($def['fields'] as $f) {
        if (!empty($f[3]) && trim((string)$data[$f[0]]) === '') err($f[1] . '不能为空');
    }

    // 上传字段：每个上传框用独立的名字，一个表单里有多个 file 也不会互相覆盖
    foreach ($def['fields'] as $f) {
        if (!in_array($f[2], ['upload', 'icon'], true)) continue;
        $path = save_upload('file_' . $f[0]);
        if ($path !== null) $data[$f[0]] = $path;
    }

    if (empty($data['sort'])) {
        $data['sort'] = $id ? (int)(row_one($table, $id)['sort'] ?? 0) : next_sort($table);
    }

    row_save($table, $data, $id ?: null);
    flash($id ? '已更新' : '已添加');
    go('index.php?p=' . $table);
}

/* ============================ 页面片段 ============================ */
/** 值是图片地址（站内 img/…、外链或 data URI），而不是 FontAwesome 类名 */
function is_image_src(string $v): bool
{
    if ($v === '') return false;
    return (bool) preg_match('~^(?:https?:)?//|^data:image/|^img/~i', $v)
        || (bool) preg_match('~\.(?:svg|png|jpe?g|gif|webp|avif)(?:[?#]|$)~i', $v);
}

/** 后台预览图地址：站内相对路径补上 ../，外链 / data URI 原样输出 */
function preview_src(string $v): string
{
    return preg_match('~^(?:https?:)?//|^data:~i', $v) ? $v : '../' . ltrim($v, '/');
}

function render_field(array $f, array $row): string
{
    [$key, $label, $type] = $f;
    $value = (string)($row[$key] ?? '');
    $hint = (string)($f[4] ?? '');
    $req = !empty($f[3]) ? ' <b class="req">*</b>' : '';
    $name = h($key);
    $out = '<div class="field"><label for="f_' . $name . '">' . h($label) . $req . '</label>';

    switch ($type) {
        case 'textarea':
            $out .= '<textarea id="f_' . $name . '" name="' . $name . '" rows="' . (int)($f[5] ?? 5) . '">'
                  . h($value) . '</textarea>';
            break;
        case 'int':
            $out .= '<input id="f_' . $name . '" type="number" name="' . $name . '" value="' . h($value === '' ? '0' : $value) . '" min="0">';
            break;
        case 'bool':
            $checked = $value === '' ? ($row ? false : true) : $value === '1';
            $out .= '<label class="switch"><input id="f_' . $name . '" type="checkbox" name="' . $name . '" value="1"'
                  . ($checked ? ' checked' : '') . '> <span>启用</span></label>';
            break;
        case 'select':
            $out .= '<select id="f_' . $name . '" name="' . $name . '">';
            foreach (($f[5] ?? []) as $v => $text) {
                $out .= '<option value="' . h($v) . '"' . ($value === (string)$v ? ' selected' : '') . '>' . h($text) . '</option>';
            }
            $out .= '</select>';
            break;
        case 'cat':
            $out .= '<select id="f_' . $name . '" name="' . $name . '">';
            $out .= '<option value="0"' . ($value === '' || $value === '0' ? ' selected' : '') . '>未分类</option>';
            foreach (categories_list(false) as $c) {
                $out .= '<option value="' . (int)$c['id'] . '"' . ($value === (string)$c['id'] ? ' selected' : '') . '>'
                      . h($c['name']) . ((int)$c['visible'] === 1 ? '' : '（前台已隐藏）') . '</option>';
            }
            $out .= '</select>';
            break;
        case 'markdown':
            $rows = (int)($f[5] ?? 14);
            $btns = [
                ['bold', '粗体', 'fa-bold'],
                ['italic', '斜体', 'fa-italic'],
                ['h2', '标题', 'fa-heading'],
                ['ul', '无序列表', 'fa-list-ul'],
                ['quote', '引用', 'fa-quote-left'],
                ['code', '行内代码', 'fa-code'],
                ['fence', '代码块', 'fa-file-code'],
                ['link', '链接', 'fa-link'],
                ['img', '插入本地图片（选文件上传）', 'fa-image'],
            ];
            $out .= '<div class="md" data-md>'
                  . '<div class="md-bar">';
            foreach ($btns as [$act, $label, $icon]) {
                $out .= '<button type="button" title="' . h($label) . '" data-md-act="' . $act . '">'
                      . '<i class="fa-solid ' . $icon . '"></i></button>';
            }
            $out .= '<span class="md-bottom">'
                  . '<span class="md-status" data-md-status></span>'
                  . '<span class="md-count" data-md-count></span>'
                  . '<button type="button" class="md-view" data-md-view>只看预览</button>'
                  . '</span>'
                  . '</div>'
                  . '<div class="md-panes">'
                  . '<textarea id="f_' . $name . '" name="' . $name . '" rows="' . $rows . '" data-md-input spellcheck="false">' . h($value) . '</textarea>'
                  . '<div class="md-preview md-body" data-md-preview></div>'
                  . '</div>'
                  . '<p class="hint md-hint">左边写，右边就是发布后的样子（用的和前台同一套渲染器）。</p>'
                  . '</div>';
            break;
        case 'audio':
            $out .= '<div class="upload-row">'
                  . '<input id="f_' . $name . '" type="text" name="' . $name . '" value="' . h($value) . '" placeholder="https://…/song.mp3 或 music/song.mp3">'
                  . '<input type="file" name="file_' . $name . '" accept="audio/*">'
                  . '</div>';
            if ($value !== '') {
                $out .= '<div class="thumb"><audio controls preload="none" src="' . h(preview_src($value)) . '"></audio></div>';
            }
            break;
        case 'icon':
            $out .= '<div class="upload-row">'
                  . '<input id="f_' . $name . '" type="text" name="' . $name . '" value="' . h($value) . '" placeholder="fa-solid fa-code 或 img/logo.svg">'
                  . '<input type="file" name="file_' . $name . '" accept="image/*">'
                  . '</div>';
            if (is_image_src($value)) {
                $out .= '<div class="thumb"><img src="' . h(preview_src($value)) . '" alt="" onerror="this.remove()"></div>';
            }
            break;
        case 'upload':
            $out .= '<div class="upload-row">'
                  . '<input id="f_' . $name . '" type="text" name="' . $name . '" value="' . h($value) . '" placeholder="img/xxx.jpg">'
                  . '<input type="file" name="file_' . $name . '" accept="image/*">'
                  . '</div>';
            if (is_image_src($value)) {
                $out .= '<div class="thumb"><img src="' . h(preview_src($value)) . '" alt="" onerror="this.remove()"></div>';
            }
            break;
        default:
            $out .= '<input id="f_' . $name . '" type="text" name="' . $name . '" value="' . h($value) . '">';
    }

    if ($hint !== '') $out .= '<p class="hint">' . h($hint) . '</p>';
    return $out . '</div>';
}

function render_entity(string $table, array $def): void
{
    $editId = get_int('edit');
    $row = $editId ? (row_one($table, $editId) ?? []) : [];
    $items = rows($table);
    // 有 Markdown 编辑器的表单要占满整行，否则左右分栏下编辑区只有 190px 宽；
    // 表单占满整行后，下面的列表也得跟着占满，不然会被挤进左侧 420px 那栏
    $wide = in_array('markdown', array_column($def['fields'], 2), true);

    ob_start();
    ?>
    <div class="grid-2">
      <section class="card<?= $wide ? ' card-wide' : '' ?>">
        <h2><?= $editId ? '编辑' : '新增' ?><?= h($def['title']) ?></h2>
        <p class="hint"><?= h($def['hint']) ?></p>
        <form method="post" enctype="multipart/form-data"
              action="index.php?p=<?= h($table) ?>&do=save<?= $editId ? '&id=' . $editId : '' ?>">
          <?= csrf_field() ?>
          <?php foreach ($def['fields'] as $f) echo render_field($f, $row); ?>
          <div class="actions">
            <button type="submit" class="btn-primary"><?= $editId ? '保存修改' : '添加' ?></button>
            <?php if ($editId): ?><a class="btn-ghost" href="index.php?p=<?= h($table) ?>">取消</a><?php endif; ?>
          </div>
        </form>
      </section>

      <section class="card<?= $wide ? ' card-wide' : '' ?>">
        <h2>已有 <?= count($items) ?> 条</h2>
        <?php if (!$items): ?>
          <p class="empty">还没有内容，<?= $wide ? '上面' : '左边' ?>添加一条试试。</p>
        <?php else: ?>
        <div class="list-wrap">
        <table class="list">
          <colgroup>
            <col style="width:40px">
            <?php foreach ($def['list'] as $c): ?>
            <col<?= isset($def['listWidth'][$c]) ? ' style="width:' . h($def['listWidth'][$c]) . '"' : '' ?>>
            <?php endforeach; ?>
            <col style="width:150px">
          </colgroup>
          <thead><tr>
            <th>#</th>
            <?php foreach ($def['list'] as $c): ?><th><?= h($def['listHead'][$c] ?? $c) ?></th><?php endforeach; ?>
            <th>操作</th>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $i => $it): ?>
            <tr class="<?= (int)$it['visible'] === 1 ? '' : 'dim' ?>">
              <td><?= (int)$it['sort'] ?></td>
              <?php foreach ($def['list'] as $c): ?>
                <td title="<?= h((string)$it[$c]) ?>"><?= h(mb_strimwidth((string)$it[$c], 0, 42, '…')) ?></td>
              <?php endforeach; ?>
              <td class="ops">
                <?php if ($table === 'posts'): ?>
                  <a href="../post.html?id=<?= (int)$it['id'] ?>" target="_blank" rel="noopener" title="在前台查看">
                    <i class="fa-solid fa-up-right-from-square"></i></a>
                <?php endif; ?>
                <a href="index.php?p=<?= h($table) ?>&edit=<?= (int)$it['id'] ?>" title="编辑"><i class="fa-solid fa-pen"></i></a>
                <form method="post" action="index.php?p=<?= h($table) ?>&do=toggle&id=<?= (int)$it['id'] ?>">
                  <?= csrf_field() ?><button type="submit" title="显示 / 隐藏">
                    <i class="fa-solid <?= (int)$it['visible'] === 1 ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button>
                </form>
                <form method="post" action="index.php?p=<?= h($table) ?>&do=up&id=<?= (int)$it['id'] ?>">
                  <?= csrf_field() ?><button type="submit" title="上移"><i class="fa-solid fa-arrow-up"></i></button>
                </form>
                <form method="post" action="index.php?p=<?= h($table) ?>&do=down&id=<?= (int)$it['id'] ?>">
                  <?= csrf_field() ?><button type="submit" title="下移"><i class="fa-solid fa-arrow-down"></i></button>
                </form>
                <form method="post" action="index.php?p=<?= h($table) ?>&do=delete&id=<?= (int)$it['id'] ?>"
                      onsubmit="return confirm('确定删除这条吗？删除后不可恢复。')">
                  <?= csrf_field() ?><button type="submit" class="danger" title="删除"><i class="fa-solid fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </section>
    </div>
    <?php
    $content = (string)ob_get_clean();
    layout($def['title'], $content, $table);
}

function render_settings(): void
{
    $s = settings_all();
    // 老库没有音乐字段时，表单里先显示内置默认值；保存一次就会写进库
    $s += [
        'music_src' => setting_get('music_src', DEFAULT_MUSIC_SRC),
        'music_title' => setting_get('music_title', DEFAULT_MUSIC_TITLE),
        'music_cover' => setting_get('music_cover', DEFAULT_MUSIC_COVER),
    ];
    ob_start();
    ?>
    <form method="post" enctype="multipart/form-data" action="index.php?p=settings">
      <?= csrf_field() ?>
      <div class="settings-grid">
        <div class="settings-col">
          <section class="card">
            <h2><span class="sec-ico"><i class="fa-solid fa-id-card"></i></span>名片</h2>
            <?php
            echo render_field(['name', '站点 / 昵称', 'text', true], $s);
            echo render_field(['sign', '签名', 'text'], $s);
            echo render_field(['status', '在线状态', 'text', false, '例如 iPhone 在线'], $s);
            echo render_field(['avatar', '头像', 'upload', false, '留空保持原样；上传新图会自动替换'], $s);
            echo render_field(['avatar_text', '头像兜底文字', 'text', false, '头像加载失败时显示的单个字'], $s);
            ?>
          </section>

          <section class="card">
            <h2><span class="sec-ico"><i class="fa-solid fa-qrcode"></i></span>赞赏码</h2>
            <?php
            echo render_field(['pay_wechat', '微信赞赏图', 'upload', false, '放图后前台自动显示，不再是占位'], $s);
            echo render_field(['pay_alipay', '支付宝图', 'upload', false], $s);
            ?>
          </section>

          <section class="card tip-card">
            <h2><span class="sec-ico"><i class="fa-solid fa-lightbulb"></i></span>小提示</h2>
            <ul class="tip-list">
              <li><i class="fa-solid fa-circle-check"></i><span>前台内容来自 <code>api.php</code>，保存后刷新前台即可看到。</span></li>
              <li><i class="fa-solid fa-circle-check"></i><span>图片上传上限 5MB，音频上限 20MB；超限会直接提示。</span></li>
              <li><i class="fa-solid fa-circle-check"></i><span>清空「音频地址」并保存，就会关闭首页音乐。</span></li>
              <li><i class="fa-solid fa-circle-check"></i><span>前台没更新时，<kbd>Ctrl + F5</kbd> 强刷一下浏览器缓存。</span></li>
              <li><i class="fa-solid fa-circle-check"></i><span>数据备份：直接拷走 <code>data/</code> 目录即可。</span></li>
              <li><i class="fa-solid fa-circle-check"></i><span>本页所有修改都要点下方「保存全部设置」才会生效。</span></li>
            </ul>
          </section>
        </div>

        <div class="settings-col">
          <section class="card">
            <h2><span class="sec-ico"><i class="fa-solid fa-layer-group"></i></span>内容字段</h2>
            <?php
            echo render_field(['skills', '技能标签', 'textarea', false, '每行一条，格式「标签|颜色」，如 CSS|#f70808', 5], $s);
            echo render_field(['about_lines', '关于弹窗条目', 'textarea', false, '每行一条，格式「图标|颜色|文字」', 4], $s);
            echo render_field(['sites', '旗下站点', 'textarea', false, '每行一条，格式「名称|网址」，如 我的博客|https://example.com；不写网址就是纯文字', 4], $s);
            ?>
          </section>

          <section class="card">
            <h2><span class="sec-ico"><i class="fa-solid fa-music"></i></span>首页音乐</h2>
            <?php
            echo render_field(['music_src', '音频地址 / 上传', 'audio', false, '填 MP3 直链或站内路径（music/xxx.mp3），也可以直接上传（mp3 / m4a / ogg / wav，≤20MB）；清空地址并保存可关闭首页音乐'], $s);
            echo render_field(['music_title', '歌曲名', 'text', false, '鼠标悬停播放器时显示'], $s);
            echo render_field(['music_cover', '封面', 'upload', false, '首页播放器左侧的小封面；留空保持原图'], $s);
            ?>
          </section>
        </div>
      </div>

      <section class="card">
        <h2><span class="sec-ico"><i class="fa-solid fa-sliders"></i></span>页脚与开关</h2>
        <div class="settings-fields cols-4">
          <?php
          echo render_field(['icp_text', '备案文字', 'text'], $s);
          echo render_field(['icp_href', '备案链接', 'text'], $s);
          echo render_field(['since', '运行计时起点', 'text', false, 'ISO 格式，如 2020-01-07T00:00:00'], $s);
          echo render_field(['year', '页脚年份', 'text'], $s);
          ?>
        </div>
        <h3 class="subhead">行为开关</h3>
        <div class="settings-fields cols-2 switch-cards">
          <?php
          echo render_field(['welcome_toast', '欢迎提示条', 'bool', false, '页面右下角弹一条欢迎语；关掉后不再显示'], $s);
          echo render_field(['lock_inspect', '禁用右键 / F12', 'bool', false, '只提高查看源码的门槛，挡不住直接抓接口'], $s);
          ?>
        </div>
      </section>

      <div class="sticky-actions">
        <span class="save-hint"><i class="fa-solid fa-circle-info"></i> 改完记得保存，前台刷新后生效</span>
        <button type="submit" class="btn-primary">保存全部设置</button>
      </div>
    </form>
    <?php
    layout('站点信息', (string)ob_get_clean(), 'settings');
}

function render_password(): void
{
    ob_start();
    ?>
    <section class="card" style="max-width:460px">
      <h2>修改密码</h2>
      <p class="hint">密码用 <code>password_hash()</code> 存哈希，改完所有设备都需要重新登录。</p>
      <form method="post" action="index.php?p=password">
        <?= csrf_field() ?>
        <div class="field"><label for="old_password">当前密码</label><input id="old_password" type="password" name="old_password" autocomplete="current-password"></div>
        <div class="field"><label for="new_password">新密码 <b class="req">*</b></label><input id="new_password" type="password" name="new_password" autocomplete="new-password" minlength="8"><p class="hint">至少 8 位</p></div>
        <div class="field"><label for="new_password2">再输一次</label><input id="new_password2" type="password" name="new_password2" autocomplete="new-password"></div>
        <div class="actions"><button type="submit" class="btn-primary">更新密码</button></div>
      </form>
    </section>
    <?php
    layout('修改密码', (string)ob_get_clean(), 'password');
}

function render_dashboard(): void
{
    $defs = entity_defs();
    $stats = [];
    foreach ($defs as $t => $d) {
        $all = rows($t);
        $stats[$t] = ['label' => $d['title'], 'total' => count($all),
                      'hidden' => count(array_filter($all, fn($r) => (int)$r['visible'] !== 1))];
    }
    $weakPwd = password_verify('admin123', setting_get('admin_hash'));
    $size = is_file(db_file()) ? filesize(db_file()) : 0;

    ob_start();
    ?>
    <?php if ($weakPwd): ?>
      <div class="notice">你还在用默认密码 <code>admin123</code>，
        <a href="index.php?p=password">现在去改掉</a>。</div>
    <?php endif; ?>

    <div class="stat-grid">
      <?php foreach ($stats as $t => $s): ?>
        <a class="stat" href="index.php?p=<?= h($t) ?>">
          <i class="<?= h($defs[$t]['icon']) ?>"></i>
          <b><?= (int)$s['total'] ?></b>
          <span><?= h($s['label']) ?><?= $s['hidden'] ? '（隐藏 ' . (int)$s['hidden'] . '）' : '' ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <section class="card">
      <h2>运行信息</h2>
      <table class="kv">
        <tr><th>PHP 版本</th><td><?= h(PHP_VERSION) ?></td></tr>
        <tr><th>数据库</th><td>SQLite · data/site.db（<?= number_format($size / 1024, 1) ?> KB）</td></tr>
        <tr><th>前台接口</th><td><a href="../api.php" target="_blank">../api.php</a></td></tr>
        <tr><th>数据备份</th><td>直接拷走 <code>data/</code> 目录即可</td></tr>
      </table>
    </section>
    <?php
    layout('概览', (string)ob_get_clean(), 'dashboard');
}

/* ============================ 布局 ============================ */
function layout(string $title, string $content, string $active): void
{
    $nav = [
        'dashboard' => ['概览', 'fa-solid fa-gauge-high'],
        'settings' => ['站点信息', 'fa-solid fa-sliders'],
        'friends' => ['友链', 'fa-solid fa-link'],
        'works' => ['作品集', 'fa-solid fa-code'],
        'posts' => ['博客', 'fa-solid fa-pen-nib'],
        'categories' => ['分类', 'fa-solid fa-folder-tree'],
        'photos' => ['相册', 'fa-solid fa-images'],
        'contacts' => ['社交图标', 'fa-solid fa-share-nodes'],
        'update' => ['系统更新', 'fa-solid fa-cloud-arrow-down'],
        'password' => ['修改密码', 'fa-solid fa-key'],
    ];
    $msg = flash_take();
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · 后台</title>
<link rel="stylesheet" href="../css/all.min.css">
<link rel="stylesheet" href="../css/md.css">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<aside class="side">
  <div class="side-head">
    <img src="../img/avatar.svg" alt="">
    <div><b>小悟大人</b><span>内容管理</span></div>
  </div>
  <nav>
    <?php foreach ($nav as $p => [$label, $icon]): ?>
      <a href="index.php?p=<?= h($p) ?>" class="<?= $p === $active ? 'on' : '' ?>">
        <i class="<?= h($icon) ?>"></i><span><?= h($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> 查看站点</a>
    <form method="post" action="index.php?p=logout"><?= csrf_field() ?>
      <button type="submit"><i class="fa-solid fa-arrow-right-from-bracket"></i> 退出登录</button>
    </form>
  </div>
</aside>

<main class="main">
  <header class="top">
    <h1><?= h($title) ?></h1>
  </header>
  <?php if ($msg): ?><div class="flash <?= h($msg['t']) ?>"><?= h($msg['m']) ?></div><?php endif; ?>
  <?= $content ?>
</main>
<?php if ($active === 'posts'): ?>
<script src="../js/markdown.js?v=<?= filemtime(__DIR__ . '/../js/markdown.js') ?>"></script>
<script src="assets/editor.js?v=<?= filemtime(__DIR__ . '/assets/editor.js') ?>"></script>
<?php endif; ?>
</body>
</html><?php
}

/* ============================ 路由 ============================ */
$p = get_str('p', 'dashboard');

// 登录 / 退出
if ($p === 'login') {
    if (auth_user() !== null) go('index.php');
    if (is_post()) { csrf_check(); handle_login(); }
    csrf_token();          // 必须在任何输出之前建立 CSRF Cookie，否则 setcookie() 会被丢弃
    login_view();
    exit;
}
if ($p === 'logout') {
    if (!is_post()) go('index.php');
    csrf_check();
    auth_clear();
    flash('已退出登录');
    go('index.php?p=login');
}

require_login();
csrf_token();          // 进后台就确保 CSRF Cookie 存在

if (is_post()) {
    csrf_check();
    if ($p === 'settings') handle_settings();
    elseif ($p === 'password') handle_password();
    elseif ($p === 'update') handle_update_action();
    elseif (array_key_exists($p, entity_defs())) handle_entity($p);
    else err('未知操作');
}

switch ($p) {
    case 'settings': render_settings(); break;
    case 'password': render_password(); break;
    case 'update': render_update(); break;
    case 'dashboard': render_dashboard(); break;
    default:
        $defs = entity_defs();
        if (isset($defs[$p])) render_entity($p, $defs[$p]);
        else err('页面不存在', 404);
}
