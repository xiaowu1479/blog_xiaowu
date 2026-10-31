<?php
/**
 * admin/update.php —— 后台「系统更新」页
 *
 * 两个动作（都是 POST + CSRF）：
 *   act=check  只查：取远端最新提交 → 下载源码包 → 和本地逐文件比对 → 把结论存进 data/update.json
 *   act=apply  真装：重新取最新 → 下载 → 比对 → 备份 → 覆盖 → 记台账
 *
 * 逻辑都在 lib/updater.php，这里只负责「什么时候调用」和「怎么显示」。
 */
declare(strict_types=1);

// 只给 admin/index.php require 用；被直接访问就当不存在
if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}

/** ISO 时间（UTC）转成本地时区的简短写法 */
function update_fmt(string $iso): string
{
    if ($iso === '') {
        return '';
    }
    try {
        $dt = new DateTime($iso);
        return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('m-d H:i');
    } catch (Exception) {
        return $iso;
    }
}

/** 更新/切换后统一提醒：前台缓存不在服务端 */
function update_cache_hint(): string
{
    return '前台如果还是旧样式，按 Ctrl+F5（有 CDN 的刷新 CDN 缓存）';
}

/** 一句话总结一次检查的结论 */
function update_check_summary(array $check): string
{
    if ($check === null) {
        return '还没检查过';
    }
    $plan = $check['plan'] ?? null;
    $note = (string) ($check['note'] ?? '');
    if (is_array($plan) && (int) $plan['changes'] > 0) {
        if (str_contains($note, '不一致')) {
            return '提交号未变，但有 ' . (int) $plan['changes'] . ' 个文件需要修复';
        }
        return '有新版本：新增 ' . $plan['add'] . ' · 修改 ' . $plan['mod']
            . ' · 删除 ' . count($plan['del']) . ' 个文件';
    }
    return $note !== '' ? $note : '已是最新';
}

/* ============================ 动作 ============================ */

/** 检查更新：只读远端 + 比对，不改站点文件 */
function update_action_check(): void
{
    // 下载 + 解压 + 逐文件比对可能超过默认的 30 秒
    @set_time_limit(180);
    update_cleanup_tmp();

    $head = update_head();
    if (!$head['ok']) {
        flash('检查失败：' . $head['error'], 'err');
        go('index.php?p=update');
    }

    $state   = update_state();
    $cur     = (string) ($state['current']['sha'] ?? '');
    $sameSha = ($cur !== '' && $cur === $head['sha']);
    $driftNote = '';

    if ($sameSha) {
        // 版本号一样不代表文件没被动过：手动恢复、从备份拷文件、部分写入失败都可能造成漂移。
        // 台账一致才敢直接说「已是最新」；不一致就继续下载比对，让计划里列出来。
        $drift = update_local_drift();
        if ($drift['ok'] && $drift['changed'] === []) {
            update_remember_check($head, null, '已是最新版本（本地文件与台账一致）');
            flash('已是最新版本（' . $head['short'] . '）');
            go('index.php?p=update');
        }
        $driftNote = $drift['ok']
            ? '提交号已是最新，但本地有 ' . count($drift['changed']) . ' 个文件与台账不一致（可能被手改/恢复过），重新比对'
            : '提交号已是最新，但没有文件台账，重新比对';
    }

    $dl = update_download($head['sha']);
    if (!$dl['ok']) {
        update_remember_check($head, null, '有新版本，但源码包没下来：' . $dl['error']);
        flash('有新版本，但源码包没下来：' . $dl['error'], 'err');
        go('index.php?p=update');
    }

    $ex = update_extract($dl['zip']);
    if (!$ex['ok']) {
        update_remember_check($head, null, '有新版本，但' . $ex['error']);
        flash('有新版本，但' . $ex['error'], 'err');
        go('index.php?p=update');
    }

    $plan = update_plan($ex['dir']);
    if (!$plan['ok']) {
        update_remember_check($head, null, '比对失败：' . $plan['error']);
        flash('比对失败：' . $plan['error'], 'err');
        go('index.php?p=update');
    }

    if ($plan['changes'] === 0) {
        // 仓库里的内容和服务器上一模一样：把当前版本记为这一版，建立文件台账
        update_adopt($head, $plan['total']);
        update_install_manifest($ex['dir']);
        $note = $driftNote !== '' ? $driftNote . '；内容和仓库一致' : '内容和仓库一致，已把当前版本记为 ' . $head['short'];
        update_remember_check($head, null, $note);
        update_cleanup_tmp();
        flash(($driftNote !== '' ? $driftNote . '；' : '') . '内容和仓库一致（' . $plan['total'] . ' 个文件）');
        go('index.php?p=update');
    }

    update_remember_check($head, $plan, $driftNote);
    update_cleanup_tmp();
    $what = $sameSha
        ? ($driftNote !== '' ? $driftNote . '；' : '')
            . '发现 ' . (int) $plan['changes'] . ' 个文件需要修复/更新'
        : '发现新版本 ' . $head['short'];
    flash($what . '：新增 ' . $plan['add']
        . ' · 修改 ' . $plan['mod'] . ' · 删除 ' . count($plan['del']));
    go('index.php?p=update');
}

/** 执行更新：备份 + 覆盖 + 记台账 */
function update_action_apply(): void
{
    @set_time_limit(180);
    @ignore_user_abort(true);      // 浏览器中途关掉也要把这次覆盖做完，别停在半路
    update_cleanup_tmp();

    $head = update_head();
    if (!$head['ok']) {
        flash('更新失败：' . $head['error'], 'err');
        go('index.php?p=update');
    }

    $dl = update_download($head['sha']);
    if (!$dl['ok']) {
        flash('更新失败：' . $dl['error'], 'err');
        go('index.php?p=update');
    }

    $ex = update_extract($dl['zip']);
    if (!$ex['ok']) {
        flash('更新失败：' . $ex['error'], 'err');
        go('index.php?p=update');
    }

    $plan = update_plan($ex['dir']);
    if (!$plan['ok']) {
        flash('更新失败：' . $plan['error'], 'err');
        go('index.php?p=update');
    }

    if ($plan['changes'] === 0) {
        update_adopt($head, $plan['total']);
        update_install_manifest($ex['dir']);
        update_cleanup_tmp();
        flash('没有需要更新的文件，当前版本记为 ' . $head['short']);
        go('index.php?p=update');
    }

    $prev = update_state()['current'] ?? null;
    $run  = update_apply($ex['dir'], $plan, ['from' => $prev, 'to' => $head]);

    if ($run['ok']) {
        $files = update_install_manifest($ex['dir']);
        update_remember_run($head, $run, $files);
        update_remember_check($head, null, '更新到 ' . $head['short'] . ' 完成');
        $msg = '更新完成：' . $head['short'] . ' · 新增 ' . $run['add']
            . ' · 修改 ' . $run['mod'] . ' · 删除 ' . $run['del'];
        if ($run['backup'] !== '') {
            $msg .= ' · 备份 ' . str_replace('\\', '/', substr($run['backup'], strlen(APP_ROOT) + 1)) . '/';
        }
        $msg .= ' · ' . update_cache_hint();
        flash($msg);
    } else {
        update_remember_run($head, $run, 0);
        $bad = array_slice($run['errors'], 0, 3);
        flash('更新没走完（' . count($run['errors']) . ' 个问题）：' . implode('；', $bad), 'err');
    }

    update_cleanup_tmp();
    go('index.php?p=update');
}

/** 回滚到某一份备份之前的状态 */
function update_action_rollback(): void
{
    $dir = trim(post_str('dir', 60));
    if (!preg_match('/^update-[0-9]{8}-[0-9]{6}$/', $dir)) {
        flash('备份目录名不合法', 'err');
        go('index.php?p=update');
    }
    $run = update_rollback($dir);
    if ($run['ok']) {
        flash('已回滚：还原 ' . $run['restored'] . ' 个文件，删掉 ' . $run['removed'] . ' 个这次新增的文件');
    } else {
        flash('回滚没走完：' . implode('；', array_slice($run['errors'], 0, 3)), 'err');
    }
    go('index.php?p=update');
}

/** 把站点文件切到某个 tag 那一版（先备份，可再回滚） */
function update_action_switch(): void
{
    $tag = trim(post_str('tag', 60));
    $res = update_switch_tag($tag);
    if (!$res['ok']) {
        flash('切到 ' . $tag . ' 没成功：' . $res['error'], 'err');
        go('index.php?p=update');
    }
    $run  = $res['run'];
    $sum  = (int) $run['add'] + (int) $run['mod'] + (int) $run['del'];
    $path = str_replace('\\', '/', (string) $run['backup']);
    $path = substr($path, strlen(str_replace('\\', '/', APP_ROOT)) + 1);
    if ($sum === 0) {
        flash('现在的内容就是 ' . $tag . '，没动任何文件');
    } else {
        flash('已切到 ' . $tag . '：修改 ' . (int) $run['mod'] . ' · 新增 ' . (int) $run['add']
            . ' · 删除 ' . (int) $run['del'] . '（备份在 ' . $path . '/）');
    }
    go('index.php?p=update');
}

/** 把更新源从本地包切回 GitHub（清空 localZip） */
function update_action_use_github(): void
{
    $state = update_state();
    $old   = (string) $state['localZip'];
    $state['localZip'] = '';
    // 上一轮的「最新」可能是按本地包算的，切回 GitHub 后重新查
    $state['lastCheck'] = null;
    update_state_save($state);
    flash('已改回从 GitHub 更新' . ($old !== '' ? '（原本地包：' . $old . '）' : '') . '，请点「检查更新」重新比对');
    go('index.php?p=update');
}

/** 清理服务端缓存；浏览器缓存只能靠 Ctrl+F5 / CDN 刷新 */
function update_action_flush_cache(): void
{
    clearstatcache(true);
    $op = function_exists('opcache_reset') ? @opcache_reset() : false;
    update_cleanup_tmp();
    flash('服务端缓存已清理' . ($op ? '（OPcache 已重置）' : '（PHP 没开 OPcache）')
        . '；浏览器如果还是旧页面，请按 Ctrl+F5，有 CDN 的刷新 CDN 缓存');
    go('index.php?p=update');
}

function handle_update_action(): void
{
    $act = post_str('act', 20);
    if (!in_array($act, ['check', 'apply', 'rollback', 'switch', 'use-github', 'flush-cache'], true)) {
        err('未知操作');
    }

    // 只改本地状态，不需要更新锁
    if ($act === 'use-github') {
        update_action_use_github();
    }

    // 同一时间只允许一个人跑：下载要几十秒，重复点会互相踩
    $lock = update_lock_acquire();
    if (!$lock['ok']) {
        flash($lock['error'], 'err');
        go('index.php?p=update');
    }
    register_shutdown_function(static function () use ($lock): void {
        update_lock_release($lock);
    });

    if ($act === 'flush-cache') {
        update_action_flush_cache();
    }
    if ($act === 'check') {
        update_action_check();
    }
    if ($act === 'apply') {
        update_action_apply();
    }
    if ($act === 'switch') {
        update_action_switch();
    }
    update_action_rollback();
}

/* ============================ 页面 ============================ */

function render_update(): void
{
    $state = update_state();
    $cur   = is_array($state['current']) ? $state['current'] : null;
    $check = is_array($state['lastCheck']) ? $state['lastCheck'] : null;
    $plan  = is_array($check['plan'] ?? null) ? $check['plan'] : null;
    $head  = is_array($check['head'] ?? null) ? $check['head'] : null;
    $hasUpdate = is_array($plan) && (int) $plan['changes'] > 0;

    $repoUrl = 'https://github.com/' . $state['repo'];
    $srcName = $state['localZip'] !== '' ? '本地包（' . $state['localZip'] . '）' : 'GitHub';
    $tokenOn = $state['token'] !== '';
    $localOn = $state['localZip'] !== '';

    // 前台关键资源自检：服务器上的文件到底是不是新的？
    $manifestNow = update_manifest();
    $watchRows = [];
    foreach (['js/site.js', 'css/site.css', 'index.html'] as $rel) {
        $abs = APP_ROOT . '/' . $rel;
        $exists = is_file($abs);
        $sha = $exists ? (string) @sha1_file($abs) : '';
        $recorded = (string) ($manifestNow[$rel] ?? '');
        $watchRows[] = [
            'path' => $rel,
            'exists' => $exists,
            'sha' => $sha,
            'mtime' => $exists ? date('Y-m-d H:i:s', (int) filemtime($abs)) : '',
            'size' => $exists ? (int) filesize($abs) : 0,
            'recorded' => $recorded,
            'same' => $recorded !== '' && $sha !== '' && $sha === $recorded,
        ];
    }
    $socialCode = is_file(APP_ROOT . '/js/site.js')
        && str_contains((string) @file_get_contents(APP_ROOT . '/js/site.js'), 'socialDock');

    ob_start();
    ?>
<div class="card">
  <h2>版本</h2>
  <table class="up-kv">
    <tr>
      <th>仓库</th>
      <td><a href="<?= h($repoUrl) ?>" target="_blank" rel="noopener"><?= h($state['repo']) ?></a>
        · 分支 <code><?= h($state['branch']) ?></code>
        · 更新源 <?= h($srcName) ?>
        <?php if ($state['localZip'] === ''): ?>
          <span class="up-tag"><?= $tokenOn ? 'token 已配置' : '公开仓库，无需 token' ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>当前版本</th>
      <td>
        <?php if ($cur === null): ?>
          <span class="empty">还没有更新记录。第一次点「检查更新」会和仓库比一遍文件，一致就把当前版本记下来。</span>
        <?php else: ?>
          <code class="up-sha"><?= h((string) $cur['short']) ?></code>
          <?= h(mb_strimwidth((string) ($cur['message'] ?? ''), 0, 46, '…')) ?>
          <span class="up-dim">装于 <?= h((string) $cur['appliedAt']) ?> · <?= (int) ($cur['files'] ?? 0) ?> 个文件</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th><?= $state['localZip'] === '' ? '远端最新' : '包里版本' ?></th>
      <td>
        <?php if ($head === null): ?>
          <span class="empty">还没查过</span>
        <?php else: ?>
          <code class="up-sha"><?= h((string) $head['short']) ?></code>
          <?= h(mb_strimwidth((string) $head['message'], 0, 46, '…')) ?>
          <span class="up-dim"><?= h((string) $head['author']) ?> · <?= h(update_fmt((string) $head['date'])) ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>上次检查</th>
      <td>
        <?php if ($check === null): ?>
          <span class="empty">还没查过</span>
        <?php else: ?>
          <span class="up-dim"><?= h((string) $check['at']) ?></span>
          <span class="up-verdict <?= $hasUpdate ? 'new' : 'ok' ?>"><?= h(update_check_summary($check)) ?></span>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <div class="actions">
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="act" value="check">
      <button type="submit" class="btn-primary">检查更新</button>
    </form>
    <form method="post" onsubmit="return confirm('将从仓库覆盖站点文件：新增/修改的直接替换，上游删掉的会删除。\n\ndata/、img/uploads/ 不动，被覆盖的文件会先备份到 _backup/。\n\n继续？')">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="apply">
      <button type="submit" class="btn-primary">立即更新</button>
    </form>
    <span class="hint">「立即更新」会重新取一次远端最新版，可能比上面的预览新一两个提交。</span>
  </div>
</div>

<?php if ($localOn): ?>
<div class="card">
  <h2>当前是本地包模式</h2>
  <div class="up-alert">
    更新源是 <code><?= h($state['localZip']) ?></code>。这个模式只安装这个包，不会去 GitHub 拉最新代码，
    版本号也按包的哈希计算。如果你以为在更新 GitHub 最新版，请先切回去。
  </div>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="act" value="use-github">
    <button type="submit" class="btn-primary">改回从 GitHub 更新</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>缓存与前台资源</h2>
  <p class="hint">
    更新程序已经处理了服务端的 PHP OPcache / stat 缓存；但浏览器和 CDN 的缓存清不到。
    更新完前台还是旧样式时：先按 <b>Ctrl+F5</b>，有 CDN 的再去 CDN 后台刷新。
  </p>
  <div class="actions">
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="act" value="flush-cache">
      <button type="submit" class="btn-ghost">清理服务端缓存</button>
    </form>
    <span class="hint">清 OPcache、stat 缓存和临时解压目录；源码包下载缓存保留。</span>
  </div>

  <table class="up-kv" style="margin-top:12px">
    <tr>
      <th>社交图标代码</th>
      <td>
        <?php if ($socialCode): ?>
          <span class="up-verdict ok">已包含 renderSocial / socialDock</span>
          <span class="up-dim">如果这里正常但首页没有图标，先查 /api.php 的 contacts，再查浏览器缓存。</span>
        <?php else: ?>
          <span class="up-warn">js/site.js 里没有 socialDock —— 这个文件还是旧版</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php foreach ($watchRows as $w): ?>
      <tr>
        <th style="width:110px"><code><?= h($w['path']) ?></code></th>
        <td>
          <?php if (!$w['exists']): ?>
            <span class="up-warn">文件不存在</span>
          <?php else: ?>
            <span class="up-dim"><?= h($w['mtime']) ?> · <?= (int) $w['size'] ?> 字节 · sha1 <code><?= h(substr($w['sha'], 0, 12)) ?></code></span>
            <?php if ($w['same']): ?>
              <span class="up-verdict ok">与台账一致</span>
            <?php elseif ($w['recorded'] !== ''): ?>
              <span class="up-warn">与台账不一致（被手改/恢复过，或台账还是旧版本）</span>
            <?php else: ?>
              <span class="up-dim">台账里没有这个文件</span>
            <?php endif; ?>
            <a href="../<?= h($w['path']) ?>?t=<?= time() ?>" target="_blank" rel="noopener">打开当前文件</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="margin-top:8px">
    带 <code>?t=时间戳</code> 的链接会绕过浏览器缓存。它显示的是新内容、但普通页面还是旧的，就是浏览器/CDN 缓存。
  </p>
</div>

<?php if ($plan !== null): ?>
<div class="card">
  <h2>待更新文件 · <?= (int) $plan['changes'] ?> 个变化</h2>
  <?php if ((int) $plan['changes'] === 0): ?>
    <p class="empty">没有变化。仓库内容和服务器上的完全一致。</p>
  <?php else: ?>
    <p class="hint">
      仓库里共 <?= (int) $plan['total'] ?> 个文件，其中 <?= (int) $plan['same'] ?> 个和服务器一致。
      下面只列有变化的；标「本地改过」的说明你在这台服务器上手改过，这次更新会覆盖它（覆盖前已备份）。
    </p>
    <div class="list-wrap">
      <table class="list">
        <thead>
          <tr><th style="width:74px">状态</th><th>文件</th><th style="width:190px">说明</th></tr>
        </thead>
        <tbody>
        <?php
        $rows = [];
        foreach (($plan['files'] ?? []) as $f) {
            $rows[] = ['state' => (string) $f['state'], 'path' => (string) $f['path'], 'local' => (bool) $f['local']];
        }
        foreach (($plan['del'] ?? []) as $p) {
            $rows[] = ['state' => 'del', 'path' => (string) $p, 'local' => false];
        }
        usort($rows, static fn(array $a, array $b): int => [$a['state'], $a['path']] <=> [$b['state'], $b['path']]);
        $shown = 0;
        foreach ($rows as $r):
            if ($shown >= 60) {
                break;
            }
            $shown++;
            $label = ['add' => '新增', 'mod' => '修改', 'del' => '删除'][$r['state']] ?? $r['state'];
            ?>
          <tr>
            <td><span class="up-file-state <?= h($r['state']) ?>"><?= h($label) ?></span></td>
            <td><code><?= h($r['path']) ?></code></td>
            <td>
              <?php if ($r['state'] === 'del'): ?>
                <span class="up-dim">上游已删除（会从服务器删掉）</span>
              <?php elseif ($r['local']): ?>
                <span class="up-warn">本地改过，会覆盖</span>
              <?php elseif ($r['state'] === 'add'): ?>
                <span class="up-dim">新文件</span>
              <?php else: ?>
                <span class="up-dim">跟随上游</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($rows) > $shown): ?>
      <p class="hint">还有 <?= count($rows) - $shown ?> 个没列出来，更新时照样会处理。</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (is_array($check['commits'] ?? null) && $check['commits'] !== []): ?>
<div class="card">
  <h2>最近提交</h2>
  <ol class="up-commits">
    <?php foreach ($check['commits'] as $c): ?>
      <li>
        <code class="up-sha"><?= h((string) $c['sha']) ?></code>
        <b><?= h(mb_strimwidth((string) $c['message'], 0, 60, '…')) ?></b>
        <span class="up-dim"><?= h((string) $c['author']) ?> · <?= h(update_fmt((string) $c['date'])) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<?php
$backups = [];
foreach (update_backup_index() as $b) {
    $backups[$b['dir']] = $b;
}
?>
<?php if (is_array($state['history']) && $state['history'] !== []): ?>
<div class="card">
  <h2>更新历史</h2>
  <div class="list-wrap">
    <table class="list">
      <thead>
        <tr><th style="width:150px">时间</th><th style="width:130px">版本</th><th>变化</th><th style="width:210px">备份</th><th style="width:64px">操作</th></tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($state['history'], 0, UPDATE_KEEP_HISTORY) as $run): ?>
        <tr>
          <td><span class="up-dim"><?= h((string) $run['at']) ?></span></td>
          <td>
            <code class="up-sha"><?= h((string) $run['to']) ?></code>
            <?php if ((string) $run['from'] !== ''): ?>
              <span class="up-dim">从 <?= h((string) $run['from']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?= (int) $run['add'] ?> 新增 · <?= (int) $run['mod'] ?> 修改 · <?= (int) $run['del'] ?> 删除
            <?php if (empty($run['ok'])): ?>
              <span class="up-warn">有失败项<?= ($run['errors'] ?? []) !== [] ? '：' . h((string) $run['errors'][0]) : '' ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((string) ($run['backup'] ?? '') !== ''): ?>
              <code><?= h(str_replace('\\', '/', substr((string) $run['backup'], strlen(APP_ROOT) + 1))) ?>/</code>
            <?php else: ?>
              <span class="up-dim">无</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
            $rowDir = (string) ($run['backup'] ?? '') !== ''
                ? basename(str_replace('\\', '/', (string) $run['backup'])) : '';
            if ($rowDir !== '' && isset($backups[$rowDir])):
                ?>
              <form method="post" onsubmit="return confirm('把这一版的改动退回去？\n\n这次新增的文件会被删掉，改过的文件从备份还原。\n\n继续？')">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="rollback">
                <input type="hidden" name="dir" value="<?= h($rowDir) ?>">
                <button type="submit" class="up-link-btn">回滚</button>
              </form>
            <?php else: ?>
              <span class="up-dim">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$tags     = update_tags_cached();
$curShort = (string) ($state['current']['short'] ?? '');
?>
<div class="card">
  <h2>按版本回退</h2>
  <?php if ($tags['tags'] === []): ?>
    <p class="up-dim">
      现在列不出 tag<?= $tags['ok'] ? '（仓库里还没打过 tag）' : '（' . h($tags['error']) . '）' ?>。
      仓库里打过 tag 之后，这里会出现「切到这一版」的按钮。
    </p>
  <?php else: ?>
    <table class="kg-table">
      <thead>
        <tr><th style="width:200px">版本</th><th style="width:110px">提交</th><th style="width:110px">操作</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tags['tags'] as $t): ?>
          <tr>
            <td><b><?= h($t['name']) ?></b><?php if ($t['name'] === $curShort): ?> <span class="up-tag">当前</span><?php endif; ?></td>
            <td><code class="up-sha"><?= h($t['short']) ?></code></td>
            <td>
              <form method="post" onsubmit="return confirm('把站点文件切换到 <?= h($t['name']) ?> 那一版？\n\n· 覆盖前会自动备份，切错了还能再回滚\n· 那一版之后新增的文件会被删掉\n· data/ 和 img/uploads/ 不动\n\n继续？')">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="switch">
                <input type="hidden" name="tag" value="<?= h($t['name']) ?>">
                <button type="submit" class="up-link-btn">切到这一版</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="up-dim" style="margin:8px 0 0">列表来自 GitHub（<?= h($tags['at']) ?>）。切过去之后，「当前版本」会显示 tag 名。</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>这个更新程序怎么工作</h2>
  <ul class="up-notes">
    <li><b>不走 git，直接拿源码包覆盖。</b>服务器不需要装 git，也不会有「本地改动导致 pull 冲突」这种事。</li>
    <li><b>永远不动这几处：</b><code>data/</code>（数据库、安装锁、本页配置）、<code>img/uploads/</code>（你上传的图）、
      <code>_backup/</code>、<code>_preview/</code>。</li>
    <li><b>删文件很保守：</b>只删「上一次是我们装的、这一版上游删掉了」的文件。第一次更新不会删任何东西。</li>
    <li><b>覆盖前先备份：</b>被替换的原文件会进 <code>_backup/update-&lt;时间&gt;/</code>，只保留最近 3 份。</li>
    <li><b>先提交再更新：</b>你在服务器上直接改过的文件，更新会覆盖（页面上会标「本地改过」）。想保留就先同步到 GitHub 再更新。</li>
    <li><b>能回滚：</b>每次更新的备份都记着「当时动了哪些文件」，历史里点「回滚」会按记录反着来一遍
      —— 新增的删掉、改过的还原。备份只留最近 3 份。</li>
    <li><b>私有仓库：</b>在 <code>data/update.json</code> 里给 <code>"token"</code> 填一个只有读权限的 PAT 即可；
      公开仓库留空就行（也可以填，能避免被 GitHub 限流）。</li>
    <li><b>服务器没外网：</b>把源码包放到服务器任意位置，把路径填进 <code>data/update.json</code> 的 <code>"localZip"</code>，
      更新时就只读本地包。</li>
  </ul>
</div>
    <?php
    layout('系统更新', (string) ob_get_clean(), 'update');
}
