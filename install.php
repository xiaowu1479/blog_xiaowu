<?php
/**
 * install.php —— 安装页（单页，不是向导）
 *
 * 设计要点：
 *   - 装完即锁：data/install.lock 存在就直接 403，不提供"重新安装"入口
 *   - 只问四个字段：站点名、管理员账号、密码、确认密码
 *   - 先做环境自检，硬件/扩展不满足时不允许继续，并明确指出缺什么
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

/* ---------------- 已安装：一律拒绝 ---------------- */
if (is_installed()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>已安装</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fa;
       font:14px/1.7 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:#141414}
  .box{width:min(460px,92vw);background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;
       padding:26px;box-shadow:0 16px 40px rgba(17,24,39,.08)}
  h1{font-size:17px;margin:0 0 10px}
  p{color:#666;margin:0 0 10px}
  a{color:#749efc}
  code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:5px;font-size:12.5px}
</style></head>
<body><div class="box">
  <h1>本站已安装</h1>
  <p>为避免被人重置管理员密码，安装程序在安装完成后即被锁定。</p>
  <p>请前往 <a href="admin/">后台登录</a>。</p>
  <p style="font-size:12.5px;color:#999">确实需要重装：先删除 <code>data/install.lock</code>，
     并在需要时删除 <code>data/site.db</code>。</p>
</div></body></html><?php
    exit;
}

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/repo.php';

/* ---------------- 环境自检 ---------------- */
function env_checks(): array
{
    $c = [];

    $c[] = [
        'label' => 'PHP 版本 ≥ 8.1',
        'value' => PHP_VERSION,
        'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
        'hint'  => '代码用到了 never 返回类型等 8.1 语法',
    ];

    $exts = [
        'pdo_sqlite' => 'SQLite 数据库驱动',
        'fileinfo'   => '上传文件类型检测',
        'mbstring'   => '中文截断与长度校验',
        'json'       => '前台数据接口',
    ];
    foreach ($exts as $ext => $label) {
        $has = extension_loaded($ext);
        $c[] = [
            'label' => $label . '（' . $ext . '）',
            'value' => $has ? '已启用' : '未启用',
            'ok'    => $has,
            'hint'  => $has ? '' : '在 php.ini 里取消 extension=' . $ext . ' 前面的分号',
        ];
    }

    $dataExists = is_dir(DATA_DIR);
    $dataWritable = $dataExists ? is_writable(DATA_DIR) : @mkdir(DATA_DIR, 0755, true);
    $c[] = [
        'label' => 'data/ 目录可写',
        'value' => $dataWritable ? '可写' : '不可写',
        'ok'    => (bool)$dataWritable,
        'hint'  => $dataWritable ? '' : '数据库要写在这里，需给 Web 用户写权限',
    ];

    $imgWritable = is_writable(APP_ROOT . '/img');
    $c[] = [
        'label' => 'img/ 目录可写',
        'value' => $imgWritable ? '可写' : '不可写',
        'ok'    => (bool)$imgWritable,
        'hint'  => $imgWritable ? '' : '不写也能用，只是后台无法上传图片',
    ];

    $server = (string)($_SERVER['SERVER_SOFTWARE'] ?? '未知');
    $isNginx = stripos($server, 'nginx') !== false;
    $c[] = [
        'label' => 'Web 服务器',
        'value' => $server,
        'ok'    => true,
        'hint'  => $isNginx
            ? 'nginx 不读 .htaccess：务必确认已给 data/ 配置拒绝访问，否则数据库可被直接下载'
            : 'Apache 走 data/.htaccess 拒绝规则',
    ];

    return $c;
}

/* ---------------- 提交处理 ---------------- */
$errors = [];
$form = [
    'site_name'  => '小悟大人的个人主页',
    'admin_user' => 'admin',
];

if (is_post()) {
    csrf_check();

    $form['site_name']  = post_str('site_name', 60);
    $form['admin_user'] = post_str('admin_user', 30);
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $passLen = function_exists('mb_strlen') ? mb_strlen($pass) : strlen($pass);

    if ($form['site_name'] === '') $errors[] = '站点名称不能为空';
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $form['admin_user'])) {
        $errors[] = '管理员账号需为 3–30 位字母、数字或下划线';
    }
    if ($passLen < 8) $errors[] = '密码至少 8 位';
    if ($pass !== $pass2) $errors[] = '两次输入的密码不一致';

    $blocking = array_filter(env_checks(), static fn(array $c): bool => !$c['ok']);
    if ($blocking) $errors[] = '环境自检未通过，请先解决下面标红的项';

    if (!$errors) {
        try {
            db();   // 建表 + 灌示例内容
            setting_set_many([
                'name'       => $form['site_name'],
                'admin_user' => $form['admin_user'],
                'admin_hash' => password_hash($pass, PASSWORD_DEFAULT),
            ]);
            $lock = DATA_DIR . '/install.lock';
            if (@file_put_contents($lock, date('c') . ' ' . bin2hex(random_bytes(8)) . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('无法写入 data/install.lock，请检查目录权限');
            }
            flash('安装完成，请用刚才设置的账号登录');
            go('admin/');
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
        }
    }
}

$checks = env_checks();
$allOk = !array_filter($checks, static fn(array $c): bool => !$c['ok']);
csrf_token();   // 必须在输出之前，否则 Cookie 会被丢弃
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · 小悟大人的个人主页</title>
<style>
  :root { --ink:#141414; --muted:#6b7280; --line:rgba(0,0,0,.09); --bg:#f4f6fa;
          --accent:#749efc; --accent2:#a78bfa; --ok:#2f9e6b; --bad:#e15b64; }
  * { box-sizing: border-box; }
  body { margin:0; padding:40px 16px 80px; background:var(--bg); color:var(--ink);
         font:14px/1.7 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; }
  .wrap { max-width:680px; margin:0 auto; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:12.5px; margin:0 0 22px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:20px; margin-bottom:16px;
          box-shadow:0 12px 30px rgba(17,24,39,.05); }
  .card h2 { font-size:14px; margin:0 0 12px; }
  table { width:100%; border-collapse:collapse; font-size:12.5px; }
  th { text-align:left; font-weight:600; color:var(--muted); padding:6px 8px 6px 0; white-space:nowrap; width:34%; }
  td { padding:6px 0; border-bottom:1px solid var(--line); }
  tr:last-child td, tr:last-child th { border-bottom:0; }
  .flag { display:inline-block; min-width:34px; text-align:center; padding:1px 6px; border-radius:5px;
          font-size:11.5px; font-weight:600; }
  .flag.on { background:rgba(47,158,107,.12); color:var(--ok); }
  .flag.off { background:rgba(225,91,100,.12); color:var(--bad); }
  .hint { color:var(--muted); font-size:11.5px; margin:2px 0 0; }
  .field { margin-bottom:12px; }
  .field > label { display:block; font-size:12.5px; font-weight:600; margin-bottom:4px; }
  input[type=text], input[type=password] { width:100%; padding:8px 10px; font:inherit; font-size:13px;
      border:1px solid var(--line); border-radius:9px; background:var(--bg); color:var(--ink); }
  input:focus { outline:2px solid rgba(116,158,252,.35); border-color:var(--accent); }
  button { padding:9px 20px; border:0; border-radius:9px; cursor:pointer; font:inherit; font-size:13px;
           font-weight:600; color:#fff; background:linear-gradient(135deg,var(--accent),var(--accent2)); }
  button:disabled { opacity:.45; cursor:not-allowed; }
  .errors { background:rgba(225,91,100,.1); border:1px solid rgba(225,91,100,.28); color:#a5373f;
            border-radius:10px; padding:10px 14px; margin-bottom:16px; font-size:13px; }
  .errors ul { margin:0; padding-left:18px; }
  .note { color:var(--muted); font-size:11.5px; margin-top:10px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>安装小悟大人的个人主页</h1>
  <p class="sub">装完即锁：本页面在安装完成后会自动锁定，不再允许重复运行。</p>

  <?php if ($errors): ?>
    <div class="errors"><ul>
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul></div>
  <?php endif; ?>

  <section class="card">
    <h2>环境自检</h2>
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <th><?= h($c['label']) ?></th>
          <td>
            <?= h($c['value']) ?>
            <span class="flag <?= $c['ok'] ? 'on' : 'off' ?>"><?= $c['ok'] ? '通过' : '不过' ?></span>
            <?php if ($c['hint'] !== ''): ?><p class="hint"><?= h($c['hint']) ?></p><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <form class="card" method="post" action="install.php">
    <h2>站点与管理员</h2>
    <?= csrf_field() ?>

    <div class="field">
      <label for="site_name">站点名称</label>
      <input id="site_name" type="text" name="site_name" value="<?= h($form['site_name']) ?>" required>
      <p class="hint">显示在左侧栏顶部，之后在后台也能改</p>
    </div>

    <div class="field">
      <label for="admin_user">管理员账号</label>
      <input id="admin_user" type="text" name="admin_user" value="<?= h($form['admin_user']) ?>"
             pattern="[A-Za-z0-9_]{3,30}" required autocomplete="username">
      <p class="hint">3–30 位字母、数字或下划线</p>
    </div>

    <div class="field">
      <label for="password">密码</label>
      <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
      <p class="hint">至少 8 位。密码不会明文保存，只存 bcrypt 哈希</p>
    </div>

    <div class="field">
      <label for="password2">再次输入密码</label>
      <input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password">
    </div>

    <button type="submit" <?= $allOk ? '' : 'disabled' ?>>开始安装</button>
    <?php if (!$allOk): ?>
      <p class="note">环境自检未全部通过，按钮已禁用。解决后刷新本页重试。</p>
    <?php endif; ?>
  </form>

  <p class="note">
    安装会创建 <code>data/site.db</code> 并写入示例内容（作品、博客、相册、友链各几条），
    装完后到后台逐条改成你自己的即可。
  </p>
</div>
</body>
</html>
