/* ============================================================================
   friend-apply.js —— 友链申请表单
   ----------------------------------------------------------------------------
   只负责把 links.html 里的「申请友链」弹窗提交到 api.php?friend_apply=1。
   结果先进后台的审核队列，审核通过后由后端写进 friends，前台才会出现。

   弹窗用 JS 建在 <body> 末尾，而不是写进 links.html 的 <main> 里：
   main 在局部换页时会被整块替换，而且弹窗嵌在 main 里会落进内容的层叠上下文，
   Bootstrap 的半透明遮罩反而盖在上面，按钮点不到。放到 body 下最稳。

   用 document 级事件委托，而不是在表单上绑 click：
   router.js 局部换页时会整块替换 <main>，表单节点是新的，委托不会失效。
   ============================================================================ */
(function () {
  'use strict';

  var MODAL_HTML = [
    '<div class="modal fade" id="friendApplyModal" tabindex="-1" aria-labelledby="friendApplyTitle" aria-hidden="true">',
    '  <div class="modal-dialog modal-dialog-centered">',
    '    <div class="modal-content" style="max-width:420px;margin:0 auto;">',
    '      <div class="modal-header">',
    '        <h5 class="modal-title" id="friendApplyTitle">',
    '          <i class="fa-solid fa-link" style="color:#749efc"></i>&nbsp;申请友链',
    '        </h5>',
    '        <button type="button" class="close" data-dismiss="modal" aria-label="关闭">',
    '          <span aria-hidden="true">&times;</span>',
    '        </button>',
    '      </div>',
    '      <div class="modal-body">',
    '        <form id="friendApplyForm" class="friend-apply" autocomplete="off" novalidate>',
    '          <label class="friend-apply__field">',
    '            <span>站点名称 <i>*</i></span>',
    '            <input type="text" name="name" maxlength="40" required placeholder="你的站点叫什么">',
    '          </label>',
    '          <label class="friend-apply__field">',
    '            <span>站点链接 <i>*</i></span>',
    '            <input type="text" name="href" maxlength="300" required placeholder="https://example.com">',
    '          </label>',
    '          <label class="friend-apply__field">',
    '            <span>一句话介绍</span>',
    '            <input type="text" name="desc" maxlength="100" placeholder="例如：一个折腾前端的小站">',
    '          </label>',
    '          <label class="friend-apply__field">',
    '            <span>联系方式</span>',
    '            <input type="text" name="contact" maxlength="120" placeholder="邮箱 / QQ，方便站长联系你（选填）">',
    '          </label>',
    '          <div class="friend-apply__hp" aria-hidden="true">',
    '            <label>网站<input type="text" name="website" tabindex="-1" autocomplete="off"></label>',
    '          </div>',
    '          <p class="friend-apply__msg" role="status" aria-live="polite"></p>',
    '          <div class="friend-apply__actions">',
    '            <button type="submit" class="friend-apply__submit">提交申请</button>',
    '          </div>',
    '          <p class="friend-apply__tip">',
    '            友情提示：先在你自己站点放上本站链接，通过率会高一些。审核结果不会单独通知，通过后直接出现在友链里。',
    '          </p>',
    '        </form>',
    '      </div>',
    '    </div>',
    '  </div>',
    '</div>'
  ].join('\n');

  var form = null;
  var msg = null;
  var submitBtn = null;
  var SUBMIT_TEXT = '提交申请';

  /** 页面里没有弹窗就建一个；SPA 换页后脚本不会重复执行，所以也只会建一次 */
  function ensureModal() {
    if (document.getElementById('friendApplyModal')) return;
    document.body.insertAdjacentHTML('beforeend', MODAL_HTML);
  }

  ensureModal();

  function find(sel, root) {
    return (root || document).querySelector(sel);
  }

  function setMsg(text, type) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.className = 'friend-apply__msg' + (type ? ' is-' + type : '');
  }

  /** 没写协议就补 https://，只接受 http / https */
  function normalizeHref(raw) {
    var v = String(raw || '').trim();
    if (!v) return '';
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(v)) v = 'https://' + v;
    try {
      var u = new URL(v);
      if (u.protocol !== 'http:' && u.protocol !== 'https:') return '';
      return u.href;
    } catch (e) {
      return '';
    }
  }

  /** 优先用 site.js 从接口拿到的 token；没有就再拉一次 api.php */
  async function getToken() {
    if (window.ZhsSite && window.ZhsSite.ready) {
      try { await window.ZhsSite.ready; } catch (e) { /* 接口挂了也继续试 */ }
    }
    if (window.ZhsSite && typeof window.ZhsSite.csrf === 'function') {
      var t = window.ZhsSite.csrf();
      if (t) return String(t);
    }
    try {
      var res = await fetch('api.php', {
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      if (!res.ok) return '';
      var data = await res.json();
      return data && data.csrf ? String(data.csrf) : '';
    } catch (e) {
      return '';
    }
  }

  function resetButton() {
    if (!submitBtn) return;
    submitBtn.disabled = false;
    submitBtn.textContent = SUBMIT_TEXT;
  }

  document.addEventListener('submit', async function (ev) {
    var f = ev.target;
    if (!f || f.id !== 'friendApplyForm') return;
    ev.preventDefault();

    form = f;
    msg = find('.friend-apply__msg', f);
    submitBtn = find('button[type="submit"]', f);

    var nameEl = find('[name="name"]', f);
    var hrefEl = find('[name="href"]', f);
    var descEl = find('[name="desc"]', f);
    var contactEl = find('[name="contact"]', f);
    var hpEl = find('[name="website"]', f);

    var name = nameEl ? nameEl.value.trim() : '';
    var href = hrefEl ? hrefEl.value.trim() : '';
    var desc = descEl ? descEl.value.trim() : '';
    var contact = contactEl ? contactEl.value.trim() : '';
    var hp = hpEl ? hpEl.value.trim() : '';

    if (!name) {
      setMsg('请填写站点名称', 'err');
      if (nameEl) nameEl.focus();
      return;
    }
    if (!href) {
      setMsg('请填写站点链接', 'err');
      if (hrefEl) hrefEl.focus();
      return;
    }
    var cleanHref = normalizeHref(href);
    if (!cleanHref) {
      setMsg('链接格式不对，请填写完整的 http(s):// 地址', 'err');
      if (hrefEl) hrefEl.focus();
      return;
    }

    // 蜜罐命中：正常访客不会填；不发送，直接给个成功提示，别让机器人知道被识破
    if (hp) {
      setMsg('申请已提交，站长审核通过后会显示在友链里', 'ok');
      f.reset();
      return;
    }

    setMsg('');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '提交中…';
    }

    try {
      var token = await getToken();
      if (!token) {
        setMsg('服务暂时不可用，请稍后再试', 'err');
        resetButton();
        return;
      }

      var body = new URLSearchParams();
      body.set('_csrf', token);
      body.set('name', name);
      body.set('href', cleanHref);
      body.set('desc', desc);
      body.set('contact', contact);

      var res = await fetch('api.php?friend_apply=1', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          Accept: 'application/json'
        },
        body: body.toString()
      });

      var data = null;
      try { data = await res.json(); } catch (e) { /* 非 JSON 错误页 */ }

      if (!res.ok || !data || data.ok !== true) {
        setMsg((data && data.error) ? data.error : '提交失败，请稍后再试', 'err');
        resetButton();
        return;
      }

      setMsg(data.message || '申请已提交，站长审核通过后会显示在友链里', 'ok');
      f.reset();
      resetButton();
    } catch (e) {
      setMsg('网络异常，请稍后再试', 'err');
      resetButton();
    }
  });

  // 每次重新打开弹窗，清掉上一次的提示（SPA 换页后表单节点可能会换，所以用委托）
  if (window.jQuery) {
    window.jQuery(document).on('show.bs.modal', function (ev) {
      var modal = ev.target;
      if (!modal || modal.id !== 'friendApplyModal') return;
      form = find('#friendApplyForm', modal);
      msg = form ? find('.friend-apply__msg', form) : null;
      submitBtn = form ? find('button[type="submit"]', form) : null;
      setMsg('');
      resetButton();
    });
  }
})();
