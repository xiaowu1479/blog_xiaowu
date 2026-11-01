/**
 * admin/assets/media.js —— 媒体库选图弹窗 + 复制路径
 *
 * 两个入口，共用同一个弹窗：
 *   1. 上传字段旁的按钮：<button data-media-pick="输入框id">，选中后把路径写回输入框并刷新预览图；
 *   2. Markdown 编辑器工具栏的图片图标：window.ZhsMedia.open(cb)，由 editor.js 插入 ![]().
 *
 * 数据从 media.php?list=1 拿（登录态 + 只读），弹窗自己带搜索；图片在 ../img/uploads/ 下，
 * 因为后台页面是 /admin/，所以显示时补一层 ../，写回表单的仍是站点根相对路径。
 */
(function () {
  'use strict';

  var API = 'media.php';
  var modal = null;
  var listEl = null;
  var searchEl = null;
  var footEl = null;
  var callback = null;
  var seq = 0;          // 请求序号：搜索时旧响应可能后到，用序号丢弃
  var lastQ = '';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function toast(text, isError) {
    var t = el('div', 'media-toast' + (isError ? ' err' : ''), text);
    document.body.appendChild(t);
    window.setTimeout(function () { t.remove(); }, 1800);
  }

  /* ---------------- 弹窗骨架 ---------------- */

  function build() {
    modal = el('div', 'media-modal');
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');

    var dialog = el('div', 'media-dialog');

    var head = el('header', 'media-modal-head');
    head.appendChild(el('b', '', '媒体库'));
    searchEl = el('input');
    searchEl.type = 'search';
    searchEl.placeholder = '搜索文件名…';
    searchEl.setAttribute('autocomplete', 'off');
    head.appendChild(searchEl);

    var closeBtn = el('button', 'media-close');
    closeBtn.type = 'button';
    closeBtn.title = '关闭';
    closeBtn.appendChild(el('i', 'fa-solid fa-xmark'));
    head.appendChild(closeBtn);

    var body = el('div', 'media-modal-body');
    listEl = el('div', 'media-grid media-grid-sm');
    body.appendChild(listEl);

    var foot = el('footer', 'media-modal-foot');
    footEl = el('span', '', '点一张图片就选中它');
    foot.appendChild(footEl);

    dialog.appendChild(head);
    dialog.appendChild(body);
    dialog.appendChild(foot);
    modal.appendChild(dialog);
    document.body.appendChild(modal);

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (ev) {
      if (ev.target === modal) close();
    });

    var debounce = null;
    searchEl.addEventListener('input', function () {
      window.clearTimeout(debounce);
      var q = searchEl.value;
      debounce = window.setTimeout(function () { load(q); }, 200);
    });
    searchEl.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        load(searchEl.value);
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && modal && !modal.hidden) close();
    });
  }

  /* ---------------- 列表 ---------------- */

  function load(q) {
    lastQ = q || '';
    var mine = ++seq;
    listEl.innerHTML = '';
    listEl.appendChild(el('p', 'empty', '读取中…'));

    fetch(API + '?list=1&q=' + encodeURIComponent(lastQ), { credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () { return { error: 'HTTP ' + res.status }; })
          .then(function (data) {
            if (!res.ok || !data || !data.items) throw new Error((data && data.error) || ('HTTP ' + res.status));
            return data;
          });
      })
      .then(function (data) {
        if (mine !== seq) return;                 // 旧请求的响应，丢掉
        paint(data);
      })
      .catch(function (e) {
        if (mine !== seq) return;
        listEl.innerHTML = '';
        listEl.appendChild(el('p', 'empty', '读不到图片列表：' + e.message));
        footEl.textContent = '登录过期的话，刷新页面重新登录后台即可';
      });
  }

  function paint(data) {
    var items = data.items || [];
    listEl.innerHTML = '';

    if (!items.length) {
      listEl.appendChild(el('p', 'empty', lastQ ? '没有匹配的图片' : '媒体库还是空的，先去「媒体库」页上传'));
      footEl.textContent = lastQ ? '换个关键词试试' : '上传入口：左侧「媒体库」';
      return;
    }

    items.forEach(function (it) {
      var btn = el('button', 'media-pick');
      btn.type = 'button';
      btn.title = it.rel;

      var img = el('img');
      img.src = '../' + it.rel;
      img.alt = it.name || '';
      img.loading = 'lazy';
      btn.appendChild(img);
      btn.appendChild(el('span', 'media-pick-name', it.name || ''));

      btn.addEventListener('click', function () { choose(it); });
      listEl.appendChild(btn);
    });

    var total = data.total || items.length;
    footEl.textContent = total > items.length
      ? '共 ' + total + ' 张，先显示最新的 ' + items.length + ' 张（用上面的搜索缩小范围）'
      : '共 ' + total + ' 张 · 点一张就选中它';
  }

  function choose(it) {
    var cb = callback;
    close();
    if (cb) cb(it.rel, it);
  }

  function close() {
    if (!modal) return;
    modal.hidden = true;
    callback = null;
  }

  function open(cb) {
    if (!modal) build();
    callback = typeof cb === 'function' ? cb : null;
    searchEl.value = '';
    modal.hidden = false;
    load('');
    window.setTimeout(function () { searchEl.focus(); }, 30);
  }

  window.ZhsMedia = { open: open };

  /* ---------------- 上传字段旁的选择按钮 ---------------- */

  function previewIn(field, rel) {
    var src = '../' + rel.replace(/^\.?\//, '');
    var img = field.querySelector('.thumb img');
    if (img) {
      img.src = src;
      return;
    }
    var box = el('div', 'thumb');
    var created = el('img');
    created.alt = '';
    created.src = src;
    box.appendChild(created);
    var hint = field.querySelector('.hint');
    field.insertBefore(box, hint || null);
  }

  function applyToInput(input, rel) {
    input.value = rel;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));

    var field = input.closest ? input.closest('.field') : null;
    if (field) previewIn(field, rel);
  }

  document.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.closest) return;

    var pick = target.closest('[data-media-pick]');
    if (pick) {
      ev.preventDefault();
      var input = document.getElementById(pick.getAttribute('data-media-pick'));
      if (!input) return;
      pick.blur();
      open(function (rel) { applyToInput(input, rel); });
      return;
    }

    var copyBtn = target.closest('[data-media-copy]');
    if (copyBtn) {
      ev.preventDefault();
      var text = copyBtn.getAttribute('data-media-copy') || '';
      copyText(text).then(function () {
        toast('已复制 ' + text);
      }, function () {
        toast('复制失败，请手动选中路径', true);
      });
    }
  });

  /* ---------------- 复制到剪贴板（http 环境下没有 clipboard API，退回复制命令） ---------------- */

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      ta.remove();
      ok ? resolve() : reject(new Error('copy failed'));
    });
  }
})();
