/* ============================================================================
   shell.js —— 子页面共用的外壳（作品集 / 博客 / 相册 / 友链 / 文章页）
   ----------------------------------------------------------------------------
   解决的问题：这些页面的左侧栏和 5 个弹窗完全一样，如果每页复制一份，
   以后改菜单就要改六个文件。这里注入一次，页面里只留：

     <aside class="sidebar" id="sidebar" data-active="works" data-sub="全部作品"></aside>

   data-active 对应菜单里的 key（home/works/blog/gallery/links），决定哪一项高亮；
   data-sub    是侧栏名字下面那行小字。

   必须在 dark.js、site.js 之前加载：那两个脚本在 DOMContentLoaded 时
   会去找 .mode-switch__toggle / #navToggle / #siteName 这些节点。
   首页 index.html 仍然是静态写死的侧栏，不依赖本文件（首屏不闪）。

   首屏不闪的两条规矩（改这里时别破坏）：
   1. 本文件紧跟在 </aside> 后面同步执行，侧栏在第一次绘制前就有内容；
   2. 名字/头像先用 localStorage 里上次记住的值，别等 site.js 的接口 ——
      否则每换一页，侧栏名字会从默认值"跳"成真名，看着就是闪一下。
   ============================================================================ */
(function () {
  'use strict';

  /** 上次记住的值；没有就用兜底。localStorage 可能被禁用，一律 try */
  function remembered(key, fallback) {
    try { return localStorage.getItem(key) || fallback; } catch (e) { return fallback; }
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  }

  const NAV = [
    { key: 'home', text: '首页', icon: 'fa-solid fa-house', href: 'index.html' },
    { key: 'works', text: '作品集', icon: 'fa-solid fa-code', href: 'works.html' },
    { key: 'blog', text: '博客', icon: 'fa-solid fa-pen-nib', href: 'blog.html' },
    { key: 'gallery', text: '相册', icon: 'fa-solid fa-images', href: 'gallery.html' },
    { key: 'links', text: '友链', icon: 'fa-solid fa-link', href: 'links.html' }
  ];

  const EXTRA = [
    { icon: 'fa-solid fa-feather-pointed', text: '关于', target: '#aboutModal' },
    { icon: 'fa-solid fa-qrcode', text: '赞赏', target: '#supportModal' }
  ];

  /* 4 个弹窗：内容和首页完全一致，实际文字由 site.js 从 api.php 填 */
  const MODALS = `
  <div class="modal fade" id="supportModal" tabindex="-1" aria-labelledby="supportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="max-width:340px;margin:0 auto;">
        <div class="modal-header">
          <h5 class="modal-title" id="supportTitle">
            <i class="fa-solid fa-quote-left" style="color:#fb7474"></i>&nbsp;赞赏&nbsp;|&nbsp;为爱打赏&nbsp;
            <i class="fa-solid fa-quote-right" style="color:#fb7474"></i>
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="关闭">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="pay-wrap" id="payBody"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="aboutModal" tabindex="-1" aria-labelledby="aboutName" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered zhs-about-dialog">
      <div class="modal-content zhs-about">
        <div class="zhs-about__head">
          <div class="zhs-about__avatar">
            <img id="aboutAvatar" src="img/avatar.svg" alt="">
          </div>
          <div class="zhs-about__id">
            <h5 class="zhs-about__name" id="aboutName">小悟大人</h5>
            <span class="zhs-about__ver" id="aboutVersion"></span>
          </div>
          <button type="button" class="zhs-about__close" data-dismiss="modal" aria-label="关闭">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
        <div class="zhs-about__body">
          <section class="zhs-about__sec">
            <div class="zhs-about__label">关于本站</div>
            <div class="zhs-about__lines" id="aboutBody"></div>
          </section>
          <section class="zhs-about__sec">
            <div class="zhs-about__label">旗下站点</div>
            <div class="zhs-sites" id="siteBody"></div>
          </section>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="max-width:420px;margin:0 auto;">
        <div class="modal-header">
          <h5 class="modal-title" id="detailTitle"></h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="关闭">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div id="detailMeta"></div>
          <div id="detailBody"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="photoModal" tabindex="-1" aria-label="图片预览" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body">
          <div class="photo-stage" id="photoStage">
            <div class="photo-frame" id="photoFrame">
              <img id="photoModalImg" alt="" draggable="false">
            </div>
          </div>
          <p class="photo-caption" id="photoCaption"></p>
          <div class="photo-toolbar" role="toolbar" aria-label="图片缩放控制">
            <button type="button" class="photo-btn" data-photo-action="out" aria-label="缩小" title="缩小">−</button>
            <button type="button" class="photo-level" id="photoZoomLevel" data-photo-action="reset" aria-label="恢复原始大小" title="恢复原始大小">100%</button>
            <button type="button" class="photo-btn" data-photo-action="in" aria-label="放大" title="放大">+</button>
            <span class="photo-sep" aria-hidden="true"></span>
            <button type="button" class="photo-btn photo-btn-close" data-photo-action="close" aria-label="关闭" title="关闭">✕</button>
          </div>
          <p class="photo-hint" id="photoHint"></p>
        </div>
      </div>
    </div>
  </div>`;

  function buildSidebar(aside) {
    const active = aside.dataset.active || '';
    const sub = aside.dataset.sub || '个人主页';

    // 首帧就用上次记住的名字/头像，等接口回来再校正，避免换页时抖一下
    const name = remembered('zhs-name', '小悟大人');
    const avatar = remembered('zhs-avatar', 'img/avatar.svg');

    const navItems = NAV.map((n) => {
      const on = n.key === active ? ' class="is-active" aria-current="page"' : '';
      return `<a href="${n.href}"${on}><i class="${n.icon}"></i><span>${n.text}</span></a>`;
    }).join('');

    const extraItems = EXTRA.map(
      (e) => `<a href="#" data-toggle="modal" data-target="${e.target}"><i class="${e.icon}"></i><span>${e.text}</span></a>`
    ).join('');

    aside.innerHTML = `
      <div class="sb-head">
        <div class="avatar-wrap sb-avatar-wrap">
          <img src="${esc(avatar)}" class="sb-avatar" id="avatar" alt="头像">
          <div class="avatar-fallback sb-avatar-fallback" id="avatarFallback">悟</div>
        </div>
        <div class="sb-id">
          <div class="sb-name" id="siteName">${esc(name)}</div>
          <div class="sb-sub">${sub}</div>
        </div>
      </div>

      <nav class="sb-nav" aria-label="主导航">
        <span class="sb-label">浏览</span>
        ${navItems}
        <span class="sb-label">其他</span>
        ${extraItems}
      </nav>

      <div class="sb-foot">
        <div class="sb-bottom">
          <div class="sb-bottom__left">
            <div class="mode-switch" title="切换深浅色（19:00–07:00 自动深色）">
              <div class="mode-switch__toggle">
                <div class="mode-switch__circle"></div>
              </div>
            </div>
            <a class="sb-admin" href="admin/" target="_blank" rel="noopener" title="进入后台管理">
              <i class="fa-solid fa-sliders"></i><span>后台</span>
            </a>
          </div>
          <a class="sb-icp" href="https://beian.miit.gov.cn/#/Integrated/index" target="_blank" rel="noopener">
            <i class="fa-regular fa-copyright"></i> <span id="footerYear">2026</span>
          </a>
        </div>
      </div>`;
  }

  const aside = document.getElementById('sidebar');
  if (aside) buildSidebar(aside);

  // 弹窗统一挂到 body 末尾；已经手写过（比如首页）就跳过
  if (!document.getElementById('aboutModal')) {
    const holder = document.createElement('div');
    holder.innerHTML = MODALS;
    while (holder.firstChild) document.body.appendChild(holder.firstChild);
  }
})();
