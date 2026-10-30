/* ============================================================================
   router.js —— 左侧「浏览」菜单的局部换页
   ----------------------------------------------------------------------------
   只接管 5 个菜单链接（首页 / 作品集 / 博客 / 相册 / 友链）：
   fetch 目标页 → 取里面的 <main class="main"> → 原位替换正文，
   侧栏、顶栏、弹窗、深浅色、播放器外壳都不重新创建，所以不会再整页闪一下。

   文章页、博客搜索/分页、外链、后台入口仍然走普通跳转；
   fetch 失败或脚本报错时也会退回 location.href，不会把用户卡住。

   依赖：site.js 暴露的 window.ZhsSite（ready / refresh / mount），
        blog.js 暴露的 window.ZhsBlog.mount（只在博客页需要）。
   ============================================================================ */
(function () {
  'use strict';

  if (location.protocol === 'file:') return;   // 双击本地 HTML 时没有 PHP，保持普通跳转

  var CONTENT = 'main.main';
  // 文章页也一起接管：从列表点进文章、再点「全部文章」都原地换，不整页重载
  var MENU = ['index.html', 'works.html', 'blog.html', 'gallery.html', 'links.html', 'post.html'];

  // 目标页额外需要的脚本 / 样式（公共的 jquery / shell / site 等首屏已经加载）
  var SCRIPTS = {
    'index.html': ['js/player.js'],
    'blog.html': ['js/blog.js'],
    'post.html': ['js/markdown.js']
  };
  var STYLES = {
    'blog.html': ['css/blog.css'],
    'post.html': ['css/md.css']
  };

  var navSeq = 0;

  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  /** 立刻跳转；页面 CSS 开了 scroll-behavior:smooth，普通 scrollTo 会变成动画 */
  function jumpTo(top) {
    try {
      window.scrollTo({ top: top, left: 0, behavior: 'instant' });
    } catch (e) {
      window.scrollTo(0, top);
    }
  }

  function fileOf(path) {
    var f = String(path || '').split('?')[0].split('#')[0];
    f = f.substring(f.lastIndexOf('/') + 1);
    return (!f || f === 'index.php') ? 'index.html' : f;
  }

  function currentFile() {
    return fileOf(location.pathname);
  }

  /** 菜单项的 href 属性 → 目标文件；首页侧栏写的是 #top */
  function targetOf(a) {
    var raw = (a.getAttribute('href') || '').trim();
    if (raw === '#top' || raw === './' || raw === '/') return 'index.html';
    if (!raw || raw.charAt(0) === '#') return '';
    if (a.origin !== location.origin) return '';
    return fileOf(a.pathname);
  }

  function samePath(a, b) {
    try {
      return new URL(a, location.href).pathname === new URL(b, location.href).pathname;
    } catch (e) {
      return false;
    }
  }

  function ensureScript(src) {
    var exists = Array.prototype.some.call(document.scripts, function (s) {
      return s.src && samePath(s.src, src);
    });
    if (exists) return Promise.resolve();

    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = false;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('脚本加载失败：' + src)); };
      document.body.appendChild(s);
    });
  }

  function ensureStyle(href) {
    var exists = Array.prototype.some.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) {
      return l.href && samePath(l.href, href);
    });
    if (exists) return Promise.resolve();

    return new Promise(function (resolve) {
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = href;
      l.onload = function () { resolve(); };
      l.onerror = function () { resolve(); };   // 样式失败不挡住导航
      document.head.appendChild(l);
    });
  }

  function loadAssets(file) {
    var jobs = [];
    (SCRIPTS[file] || []).forEach(function (src) { jobs.push(ensureScript(src)); });
    (STYLES[file] || []).forEach(function (href) { jobs.push(ensureStyle(href)); });
    return Promise.all(jobs);
  }

  function fetchPage(url) {
    return fetch(url, {
      headers: { Accept: 'text/html' },
      credentials: 'same-origin',
      cache: 'no-cache'
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.text();
    }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var main = doc.querySelector(CONTENT);
      if (!main) throw new Error('目标页没有正文容器');

      var aside = doc.querySelector('#sidebar');
      var sub = aside ? (aside.getAttribute('data-sub') || '') : '';
      if (!sub) {
        var subEl = doc.querySelector('#sidebar .sb-sub');
        sub = subEl ? subEl.textContent.trim() : '';
      }

      return {
        main: main,
        title: doc.title,
        desc: (doc.querySelector('meta[name="description"]') || {}).content || '',
        bodyPage: doc.body.getAttribute('data-page') || '',
        sub: sub
      };
    });
  }

  function setActive(file) {
    document.querySelectorAll('.sb-nav a').forEach(function (a) {
      var on = targetOf(a) === file;
      a.classList.toggle('is-active', on);
      if (on) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
  }

  function closeModals() {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
      try { window.jQuery('.modal.show').modal('hide'); } catch (e) { /* 忽略 */ }
    }
    document.body.classList.remove('modal-open');
    document.querySelectorAll('.modal-backdrop').forEach(function (n) { n.remove(); });
  }

  function swapPage(page, file) {
    var current = document.querySelector(CONTENT);
    if (!current) throw new Error('当前页没有正文容器');
    current.replaceWith(document.importNode(page.main, true));

    if (page.bodyPage) document.body.setAttribute('data-page', page.bodyPage);
    else document.body.removeAttribute('data-page');

    if (page.title) document.title = page.title;

    var desc = document.querySelector('meta[name="description"]');
    if (desc && page.desc) desc.setAttribute('content', page.desc);

    setActive(file === 'post.html' ? 'blog.html' : file);   // 文章页高亮「博客」
    var subEl = document.querySelector('.sb-sub');
    if (subEl && page.sub) subEl.textContent = page.sub;
  }

  function mountPage() {
    var jobs = [];
    try {
      if (window.ZhsSite && window.ZhsSite.mount) jobs.push(window.ZhsSite.mount());
    } catch (e) {
      console.error('[router] site mount 失败', e);
    }
    try {
      if (window.ZhsBlog && window.ZhsBlog.mount) jobs.push(window.ZhsBlog.mount());
    } catch (e) {
      console.error('[router] blog mount 失败', e);
    }
    // 博客列表是异步拉数据，等它渲染完再恢复滚动位置更准
    return Promise.all(jobs).catch(function (e) {
      console.error('[router] 正文挂载失败', e);
    });
  }

  /** 换页前刷新一次 SITE，保证后台刚改的内容能在菜单切换后看到。
      先等首屏 hydrate 完成（避免用内置兜底数据渲染），
      刷新本身最多等 800ms，接口慢/挂掉时不干等。 */
  function refreshSite() {
    if (!window.ZhsSite) return Promise.resolve();
    return Promise.resolve(window.ZhsSite.ready).then(function () {
      var job = window.ZhsSite.refresh ? window.ZhsSite.refresh() : null;
      return Promise.race([job, new Promise(function (resolve) {
        setTimeout(resolve, 800);
      })]);
    }).catch(function () { /* 接口失败就用现有数据渲染 */ });
  }

  function navigate(url, push, restoreScroll) {
    var my = ++navSeq;
    var file = fileOf(new URL(url, location.href).pathname);

    return Promise.all([fetchPage(url), refreshSite(), loadAssets(file)]).then(function (results) {
      if (my !== navSeq) return;             // 用户又点了别的菜单，放弃这次
      var page = results[0];

      var mounted = null;
      var apply = function () {
        closeModals();
        swapPage(page, file);
        mounted = mountPage();
        if (push) jumpTo(0);
      };

      // 先记下当前滚动位置，再把地址栏换成目标页，最后才挂载正文：
      // blog.js 的 initList() 会读 location.search，必须在 pushState 之后执行。
      if (push) {
        try {
          history.replaceState(
            Object.assign({}, history.state, { zhsScroll: window.scrollY }),
            '', location.href
          );
        } catch (e) { /* 忽略 */ }
        history.pushState({ zhsScroll: 0 }, '', url);
      }

      var vt = null;
      if (document.startViewTransition && !prefersReducedMotion()) {
        try {
          vt = document.startViewTransition(apply);
        } catch (e) {
          vt = null;   // 浏览器不支持/上下文不安全时退回直接替换
        }
      }
      if (vt) return vt.finished.then(function () { return mounted; });
      apply();
      return mounted;
    }).then(function () {
      if (my !== navSeq) return;
      if (!push && typeof restoreScroll === 'number') jumpTo(restoreScroll);
    }).catch(function () {
      if (my === navSeq) location.href = url;   // 任何意外都退回整页跳转
    });
  }

  /* ---------------- 点击拦截 ---------------- */
  document.addEventListener('click', function (ev) {
    if (ev.defaultPrevented || ev.button !== 0) return;
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;

    // 侧栏菜单 + 文章页的「全部文章 / 返回列表」都走原地换页，
    // 免得从文章返回博客时整页重载、左侧菜单跟着闪一下
    var a = ev.target.closest ? ev.target.closest('.sb-nav a, a.blog-item, a.article-back, a.topbar-btn, #articleNav a') : null;
    if (!a) return;
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download')) return;

    var file = targetOf(a);
    if (MENU.indexOf(file) === -1) return;

    ev.preventDefault();

    if (file === currentFile()) {
      // 已经在本页：菜单再点一次就回到顶部（和整页刷新时的观感一致）
      var smooth = !window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: 0, behavior: smooth ? 'smooth' : 'auto' });
      return;
    }

    // 用链接真实的 href（不是文件名）：文章页要靠 ?id=N 才知道渲染哪篇
    var target = new URL(a.getAttribute('href') || file, location.href);
    navigate(target.pathname + target.search + target.hash, true);
  });

  /* ---------------- 前进 / 后退 ---------------- */
  window.addEventListener('popstate', function (ev) {
    var file = currentFile();
    if (MENU.indexOf(file) === -1) {
      // 例如首屏直接打开 post.html，点菜单局部换页后再后退：
      // 当前 URL 已经回到文章页，但正文还是别的页，必须整页加载一次。
      location.reload();
      return;
    }
    var state = ev.state || {};
    var scroll = typeof state.zhsScroll === 'number' ? state.zhsScroll : 0;
    navigate(location.href, false, scroll);
  });

  // 自己管理滚动位置，避免浏览器按旧文档高度乱跳
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
})();
