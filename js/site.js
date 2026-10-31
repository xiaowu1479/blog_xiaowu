/* ============================================================================
   site.js —— 站点内容配置 + 渲染层
   ----------------------------------------------------------------------------
   二开要点：全站的"文字内容"都集中在下面的 SITE 对象里。
   想改名字、换头像、加作品、发文章、放照片、换友链，只改 SITE，不用碰 index.html。

   依赖：jQuery + bootstrap.js（模板自带，用于弹窗）、player.js（音乐卡）、
        dark.js（深浅色开关）。
   ============================================================================ */

const SITE = {
  /* ---- 名片 ---- */
  name: '小悟大人',
  sign: '天黑了.别忘记回家',
  status: 'iPhone 在线',
  // 头像：默认用本地 SVG，换成自己的照片就填 img/xxx.jpg；
  // 想用 QQ 头像接口可以填 'https://imgapi.cn/qq.php?qq=你的QQ'（依赖外部服务，可能失效）
  avatar: 'img/avatar.svg',
  avatarText: '悟',           // 头像加载失败时的兜底文字
  skills: [
    { label: 'CSS', icon: 'fa-circle-check', color: '#f70808' },
    { label: 'Java', icon: 'fa-circle-check', color: '#63e6be' },
    { label: 'JS', icon: 'fa-circle-check', color: '#74C0FC' },
    { label: 'Android', icon: 'fa-circle-check', color: '#ea875e' }
  ],

  /* ---- 关于弹窗 ---- */
  about: {
    version: 'v2.0 · 二开版',
    lines: [
      { icon: 'fa-regular fa-comment', color: '#63e6be', text: '相信未来皆有可能。' },
      { icon: 'fa-regular fa-user', color: '#74C0FC', text: '独特吧' }
    ]
  },

  /* ---- 赞赏码：把二维码图片丢进 img/ 并填好下面的文件名即可自动显示 ---- */
  pay: [
    { label: '微信赞赏', icon: 'fa-brands fa-weixin', src: 'img/zanshang.png' },
    { label: '支付宝', icon: 'fa-brands fa-alipay', src: 'img/zfb.png' }
  ],

  /* ---- 社交 / 联系方式：href 留 null 表示"还没配好"，会自动变成不可点击状态 ---- */
  contacts: [
    { icon: 'fa-brands fa-qq', color: '#a0d5ff', title: 'QQ', href: 'tencent://message/?uin=3227350433' },
    { icon: 'fa-regular fa-envelope', color: '#ffc19c', title: '邮箱', href: null },
    { icon: 'fa-brands fa-square-x-twitter', color: '#141414', title: 'X', href: null },
    { icon: 'fa-brands fa-twitter', color: '#f4a0c0', title: 'Twitter', href: null }
  ],

  /* ---- 右上角快捷按钮（GitHub / 部署） ---- */
  quick: [
    { img: 'img/github-alt.svg', title: 'GitHub', href: null },
    { img: 'img/octopus-deploy.svg', title: '部署', href: null }
  ],

  /* ---- 作品集 ---- */
  works: [
    {
      title: '个人主页 v2.0',
      year: '2026',
      icon: 'fa-solid fa-code',
      desc: '当前这个站点。由网上的单页模板二开而来，重构成卡片式板块布局。',
      tags: ['HTML', 'CSS', 'JS'],
      href: '#top'
    },
    {
      title: '博客系统',
      year: '2025',
      icon: 'fa-solid fa-feather-pointed',
      desc: '轻量博客，文章用 Markdown 写，PHP + MySQL 存数据，带标签与全文搜索。',
      tags: ['PHP', 'MySQL'],
      href: null,
      detail: '这里写项目详情：技术选型、遇到的坑、上线后的数据。\n\n没有 link 的作品点击后会弹出这个弹窗，方便你放长说明。'
    },
    {
      title: '图片压缩工具',
      year: '2025',
      icon: 'fa-solid fa-image',
      desc: '批量压缩 / 转 WebP 的小工具，纯本地处理不上传，拖进去就能用。',
      tags: ['Node.js', 'Sharp'],
      href: null,
      detail: '命令行 + 网页双入口，支持批量、支持目录递归。'
    },
    {
      title: '待填坑',
      year: '—',
      icon: 'fa-solid fa-flask',
      desc: '占位示例：把你的下一个项目写在这里，或者直接从 SITE.works 里删掉这一条。',
      tags: ['Idea'],
      href: null
    }
  ],

  /* ---- 博客：content 有内容就弹窗阅读，href 有值就跳转外链 ---- */
  posts: [
    {
      date: '2026-03-12',
      title: '从模板二开说起：先把 CSS 拆干净',
      excerpt: '拿到一个好看的模板，第一件事不是往上堆功能，而是把样式层理清楚。这篇记录了我怎么拆的。',
      content: '拿到一个好看的模板，第一件事不是往上堆功能，而是把样式层理清楚。\n\n'
        + '这个模板的问题很典型：一个 index.html 里同时塞了 <style>、<script> 和结构，'
        + '正文宽度写死 300px，所有内容挤在一张白卡里。想加板块就没地方放了。\n\n'
        + '我做的处理是三步：\n'
        + '1. 原 style.css 一个字不改，作为"基线"保留；\n'
        + '2. 新建 site.css，用设计令牌（CSS 变量）在其上做覆盖和扩展；\n'
        + '3. 把英文类名之外新增的组件统一用 card-ui / sec / work / post 这套命名，方便以后替换。\n\n'
        + '这样即使哪天想回到原版，把 site.css 一删就回去了。'
    },
    {
      date: '2026-02-28',
      title: '深浅色模式的一种低成本实现',
      excerpt: '不用 CSS-in-JS，也不用两套样式表，靠 body 上的一个类 + 一组 CSS 变量就够了。',
      content: '不用 CSS-in-JS，也不用两套样式表，靠 body 上的一个类 + 一组 CSS 变量就够了。\n\n'
        + '模板原本就有 --dark-mode-body / --dark-mode-text-color 两个变量，我在此基础上补了'
        + 'card、line、muted、accent 几个，然后：\n\n'
        + 'body { --ui-card: #fefefe; }\n'
        + 'body.dark-mode { --ui-card: #243044; }\n\n'
        + '组件里一律用 var(--ui-card)，切换时浏览器自己重算，零 JS 逻辑。'
    },
    {
      date: '2026-01-15',
      title: '纯前端做相册灯箱，20 行够了',
      excerpt: '复用页面上已有的 Bootstrap 弹窗组件，不用再引 lightbox 库。',
      content: '复用页面上已有的 Bootstrap 弹窗组件，不用再引 lightbox 库。\n\n'
        + '思路：相册缩略图点击时，把图片地址塞进弹窗里的 <img>，然后弹出即可。'
        + '好处是不增加依赖、样式和站点统一，坏处是不能左右翻页——对个人站够了。'
    },
    {
      date: '2025-12-01',
      title: '小站上线一年记',
      excerpt: '从抄模板到自己改写，聊聊这一年换过的三套皮肤。',
      content: '从抄模板到自己改写，聊聊这一年换过的三套皮肤。\n\n（这里是正文示例，替换成你自己的内容即可。）'
    }
  ],

  /* ---- 相册：src 为 null 的格子会渲染成"待放入照片"的占位 ---- */
  photos: [
    { src: 'css/resources/2.jpg', caption: '背景素材' },
    { src: null, caption: '待补充' },
    { src: null, caption: '待补充' },
    { src: null, caption: '待补充' },
    { src: null, caption: '待补充' }
  ],

  /* ---- 友链 ---- */
  friends: [
    { name: '独特吧', desc: '模板原作者', href: null },
    { name: 'GitHub', desc: '代码托管', href: 'https://github.com' },
    { name: 'MDN', desc: '前端文档', href: 'https://developer.mozilla.org' },
    { name: '待添加', desc: '替换成朋友的小站', href: null }
  ],

  /* ---- 旗下站点（弹窗） ---- */
  /* 每行一个；想要能点，就写「名称 | 网址」 */
  sites: [
    '本站 · 小悟大人的个人主页',
    '示例站点 | https://example.com'
  ],

  /* ---- 页脚 ---- */
  footer: {
    icp: '苏ICP备',
    icpHref: 'https://beian.miit.gov.cn/#/Integrated/index',
    since: '2020-01-07T00:00:00',   // "已运行"计时起点
    year: '2026'
  },

  /* ---- 行为开关 ---- */
  lockInspect: false,      // true = 恢复模板原有的禁用右键 / F12
  welcomeToast: true,      // 是否显示"欢迎你的到来"提示条
  smoothNav: true          // 板块导航是否跟随滚动高亮
};

/* ========================================================================== */
/* 以下是渲染逻辑，一般不需要改                                                  */
/* ========================================================================== */

(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);

  /** 创建元素的小工具 */
  function make(tag, props, children) {
    const node = document.createElement(tag);
    if (props) {
      Object.keys(props).forEach((k) => {
        if (k === 'class') node.className = props[k];
        else if (k === 'html') node.innerHTML = props[k];
        else if (k === 'text') node.textContent = props[k];
        else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), props[k]);
        else if (props[k] !== null && props[k] !== undefined) node.setAttribute(k, props[k]);
      });
    }
    (children || []).forEach((c) => c && node.appendChild(c));
    return node;
  }

  /** a 或 div：有 href 生成链接，没有就生成可点/不可点的块 */
  function linkOrBlock(href, cls, children, opts) {
    const o = opts || {};
    if (href) {
      return make('a', { class: cls, href: href, target: o.newTab ? '_blank' : null, rel: o.newTab ? 'noopener' : null }, children);
    }
    if (o.clickable) {
      return make('article', {
        class: cls,
        role: 'button',
        tabindex: '0',
        onclick: o.onclick,
        onkeydown: (e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); o.onclick(e); }
        }
      }, children);
    }
    return make('div', { class: cls + ' is-off', title: o.offTitle || '链接待填写' }, children);
  }

  /* ---------------- 名片 ---------------- */
  function renderIdentity() {
    const nameEl = $('#siteName');
    if (nameEl) nameEl.textContent = SITE.name;
    const signEl = $('#siteSign');
    if (signEl) signEl.textContent = SITE.sign;
    const statusEl = $('#siteStatus');
    if (statusEl) statusEl.textContent = ' ' + SITE.status + ' 😊';
    const yearEl = $('#footerYear');
    if (yearEl) yearEl.textContent = SITE.footer.year;
    const yearTextEl = $('#footerYearText');
    if (yearTextEl) yearTextEl.textContent = SITE.footer.year;
    const fnameEl = $('#footerName');
    if (fnameEl) fnameEl.textContent = SITE.name;
    const topbarName = $('#topbarName');
    if (topbarName) topbarName.textContent = SITE.name;
    const icpEl = $('#footerIcp');
    if (icpEl) icpEl.textContent = SITE.footer.icp;
    const icpLink = $('#icpLink');
    if (icpLink && SITE.footer.icpHref) icpLink.href = SITE.footer.icpHref;

    // 技能标签（局部换页后会重新执行，先清空避免重复）
    const skills = $('#skills');
    if (skills) {
      skills.innerHTML = '';
      SITE.skills.forEach((s) => {
        skills.appendChild(make('div', { class: 'neckbtn' }, [
          make('i', { class: 'fa-solid ' + s.icon, style: 'color:' + s.color }),
          document.createTextNode('\u00a0' + s.label)
        ]));
      });
    }

    // 头像 + 兜底（头像节点常驻，error 只绑一次）
    const avatar = $('#avatar');
    const fallback = $('#avatarFallback');
    if (avatar && fallback) {
      avatar.src = SITE.avatar;
      avatar.alt = SITE.name;
      fallback.textContent = SITE.avatarText;
      if (!avatar.dataset.zhsBound) {
        avatar.dataset.zhsBound = '1';
        avatar.addEventListener('error', () => {
          avatar.style.display = 'none';
          fallback.style.display = 'grid';
        });
      }
    }

    // 记住名字和头像：shell.js 首帧直接拿这个值渲染，不用等接口，换页时侧栏不会抖
    try {
      localStorage.setItem('zhs-name', SITE.name);
      localStorage.setItem('zhs-avatar', SITE.avatar);
    } catch (e) {}
  }

  /* ---------------- 快捷入口 & 联系方式 ---------------- */
  function renderQuick() {
    const box = $('#quickLinks');
    if (box) {
      box.innerHTML = '';
      SITE.quick.forEach((q) => {
        box.appendChild(linkOrBlock(q.href, 'applesb-2', [
          make('img', { src: q.img, alt: q.title })
        ], { newTab: true, offTitle: q.title + '：链接待填写' }));
      });
    }

    // 侧栏底部的社交图标已移除（占位链接没有实际用途）。
    // 数据仍保留在后台「社交图标」页，需要时把 #contacts 容器与渲染逻辑加回来即可。
  }

  /* ---------------- 关于 / 旗下站点 / 赞赏 ---------------- */
  function renderAbout() {
    const box = $('#aboutBody');
    if (!box) return;
    box.innerHTML = '';
    box.appendChild(make('span', {}, [
      make('i', { class: 'fa-regular fa-note-sticky', style: 'color:#74C0FC' }),
      document.createTextNode('\u00a0 ' + SITE.about.version)
    ]));
    box.appendChild(make('hr'));
    SITE.about.lines.forEach((l, i) => {
      box.appendChild(make('span', {}, [
        make('i', { class: l.icon, style: 'color:' + (l.color || 'inherit') }),
        document.createTextNode('\u00a0 ' + l.text)
      ]));
      if (i < SITE.about.lines.length - 1) box.appendChild(make('hr'));
    });
  }

  /**
   * 解析一行「旗下站点」：
   *   "名称 | https://example.com"  → { label:'名称', href:'https://example.com' }
   *   "https://example.com"         → { label:'https://example.com', href:'https://example.com' }
   *   "纯文字一行"                   → { label:'纯文字一行', href:'' }（渲染成普通文字，不可点）
   */
  function parseSiteLine(raw) {
    const text = String(raw == null ? '' : raw).trim();
    if (text === '') return null;

    let label = text;
    let href = '';
    const parts = text.split(/\s*[|｜]\s*/);
    if (parts.length > 1) {
      label = parts[0].trim();
      href = parts.slice(1).join('|').trim();
    } else if (!/\s/.test(text) && (/^(?:https?:\/\/|www\.)/i.test(text) || /^(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/\S*)?$/i.test(text))) {
      href = text;
    }

    if (href !== '') {
      // 粘网址常带尾巴：分号、逗号、句号（中文的也算）先剪掉
      href = href.replace(/[;；,，。、]+$/, '').trim();
      if (!/^https?:\/\//i.test(href)) {
        href = 'https://' + href.replace(/^\/+/, '');
      }
      // 只放行 http(s)；javascript: 之类一律降级成纯文字
      if (!/^https?:\/\/[^\s/?#]+\.[^\s/?#]+/i.test(href)) {
        href = '';
      }
    }
    return { label: label === '' ? href : label, href };
  }

  function renderSites() {
    const box = $('#siteBody');
    if (!box) return;
    box.innerHTML = '';
    SITE.sites.forEach((raw) => {
      const item = parseSiteLine(raw);
      if (!item) return;
      const labelNode = document.createTextNode('\u00a0 ' + item.label);
      box.appendChild(make('div', { class: 'post-date', style: 'font-size:12.5px;color:var(--ui-text);padding:3px 0' }, [
        make('i', { class: 'fa-solid fa-link', style: 'color:#74C0FC;font-size:10px' }),
        item.href !== ''
          ? make('a', {
            href: item.href,
            target: '_blank',
            rel: 'noopener noreferrer',
            title: item.href,
            style: 'text-decoration:none;color:inherit'
          }, [labelNode])
          : labelNode
      ]));
    });
  }

  function renderPay() {
    const box = $('#payBody');
    if (!box) return;
    box.innerHTML = '';
    SITE.pay.forEach((p) => {
      const holder = make('div', { class: 'pay-box' }, [
        make('i', { class: p.icon + ' fa-2x' }),
        make('span', { text: p.label }),
        make('small', { text: '放入 ' + p.src + ' 后自动显示' })
      ]);
      box.appendChild(holder);

      // 图片存在就替换掉占位
      const probe = new Image();
      probe.onload = () => {
        holder.innerHTML = '';
        holder.appendChild(make('img', { src: p.src, alt: p.label }));
        holder.appendChild(make('small', { text: p.label }));
      };
      probe.src = p.src;
    });
  }

  /* ---------------- 作品集 ---------------- */
  function bindDetail() {
    const modal = $('#detailModal');
    if (!modal || !window.jQuery) return null;
    return (item) => {
      $('#detailTitle').textContent = item.title;
      $('#detailMeta').textContent = item.meta || '';
      $('#detailBody').textContent = item.body || item.desc || '暂无详情。';
      window.jQuery(modal).modal('show');
    };
  }

  function renderWorks(openDetail) {
    const list = $('#workList');
    if (!list) return;
    list.innerHTML = '';
    const count = $('#worksCount');
    if (count) count.textContent = SITE.works.length + ' 项';

    if (!SITE.works.length) {
      list.appendChild(make('div', { class: 'empty', text: '还没有作品，去 SITE.works 里加一条吧。' }));
      return;
    }

    SITE.works.forEach((w, i) => {
      const card = linkOrBlock(w.href, 'work', [
        make('div', { class: 'work-ico qk-' + (i % 6) }, [make('i', { class: w.icon })]),
        make('div', { class: 'work-body' }, [
          make('h3', { class: 'work-title' }, [
            document.createTextNode(w.title),
            make('span', { class: 'work-year', text: w.year || '' })
          ]),
          make('p', { class: 'work-desc', text: w.desc || '' }),
          make('div', { class: 'work-tags' }, (w.tags || []).map((t) => make('span', { class: 'tag', text: t })))
        ])
      ], {
        clickable: true,
        offTitle: '没有链接' + (openDetail ? '，点击查看详情' : ''),
        onclick: (e) => {
          e.preventDefault();
          if (openDetail) openDetail({ title: w.title, meta: w.year || '', body: w.detail || w.desc });
        }
      });
      list.appendChild(card);
    });
  }

  /* ---------------- 博客（首页只放最新几篇，完整列表在 blog.html） ---------------- */
  function renderPosts() {
    const list = $('#postList');
    if (!list) return;
    list.innerHTML = '';

    const total = typeof SITE.postsTotal === 'number' ? SITE.postsTotal : SITE.posts.length;
    const count = $('#postsCount');
    if (count) count.textContent = total + ' 篇';

    const more = $('#postsMore');
    if (more) {
      const n = more.querySelector('[data-total]');
      if (n) n.textContent = total;
      more.hidden = total === 0;
    }

    if (!SITE.posts.length) {
      list.appendChild(make('div', {
        class: 'empty',
        text: total ? '文章没能加载出来，刷新一下试试。' : '还没有文章，去后台「文章」里加一篇吧。'
      }));
      return;
    }

    // 接口只给最新 3 篇；有外链就直接跳外链，否则进独立文章页
    // （接口挂掉时会用 SITE.posts 的兜底数据，那些没有 id，指到列表页即可）
    SITE.posts.forEach((p) => {
      const href = p.href || (p.id ? 'post.html?id=' + p.id : 'blog.html');
      const external = !!p.href;
      list.appendChild(make('a', {
        class: 'post',
        href: href,
        target: external ? '_blank' : null,
        rel: external ? 'noopener' : null
      }, [
        make('div', { class: 'post-date', text: p.date }),
        make('h3', { class: 'post-title', text: p.title }),
        make('p', { class: 'post-excerpt', text: p.excerpt || '' })
      ]));
    });
  }

  /* ---------------- 相册 ---------------- */
  function renderGallery() {
    const grid = $('#galleryGrid');
    if (!grid) return;
    grid.innerHTML = '';
    const count = $('#photosCount');
    if (count) count.textContent = SITE.photos.filter((p) => p.src).length + ' 张';

    SITE.photos.forEach((p) => {
      if (!p.src) {
        grid.appendChild(make('figure', { class: 'ph' }, [
          make('div', { class: 'ph-empty' }, [
            make('i', { class: 'fa-regular fa-image', style: 'font-size:16px' }),
            make('span', { text: p.caption || '待补充' })
          ])
        ]));
        return;
      }
      const img = make('img', { src: p.src, alt: p.caption || '', loading: 'lazy' });
      img.addEventListener('click', () => openLightbox(p.src, p.caption));
      grid.appendChild(make('figure', { class: 'ph' }, [
        img,
        make('figcaption', { text: p.caption || '' })
      ]));
    });
  }

  function openLightbox(src, caption) {
    const modal = $('#photoModal');
    const img = $('#photoModalImg');
    if (!modal || !img || !window.jQuery) return;
    img.src = src;
    img.alt = caption || '';
    window.jQuery(modal).modal('show');
  }

  /* ---------------- 友链 ---------------- */
  function renderFriends() {
    const box = $('#friendList');
    if (!box) return;
    box.innerHTML = '';
    const count = $('#friendsCount');
    if (count) count.textContent = SITE.friends.length + ' 个';

    SITE.friends.forEach((f, i) => {
      box.appendChild(linkOrBlock(f.href, 'friend', [
        make('div', { class: 'friend-ava qk-' + (i % 6), text: f.name.slice(0, 1) }),
        make('div', { class: 'friend-body' }, [
          make('div', { class: 'friend-name', text: f.name }),
          make('div', { class: 'friend-desc', text: f.desc || '' })
        ])
      ], { newTab: true, offTitle: f.name + '：链接待填写' }));
    });
  }

  /* ---------------- 运行时长 ----------------
     局部换页会重建首页正文，span 也跟着换；
     所以定时器只留一个、每次 tick 现取节点，换页后重新 mount 时重启即可。 */
  let runtimeTimer = null;
  function startRuntime() {
    if (runtimeTimer) {
      window.clearInterval(runtimeTimer);
      runtimeTimer = null;
    }
    if (!$('#runtime_span')) return;

    const start = new Date(SITE.footer.since).getTime();
    const pad = (n) => String(n).padStart(2, '0');

    const tick = () => {
      const span = $('#runtime_span');
      if (!span) return;
      const diff = Math.max(0, Date.now() - start);
      const days = Math.floor(diff / 86400000);
      const hours = Math.floor((diff / 3600000) % 24);
      const mins = Math.floor((diff / 60000) % 60);
      const secs = Math.floor((diff / 1000) % 60);
      span.textContent = '已运行 ' + days + ' 天 ' + pad(hours) + ' 时 ' + pad(mins) + ' 分 ' + pad(secs) + ' 秒';
    };
    tick();
    runtimeTimer = window.setInterval(tick, 1000);
  }

  /* ---------------- 深浅色：记住选择 ---------------- */
  function initTheme() {
    const KEY = 'zhanshiye-theme';
    let saved = null;
    try { saved = localStorage.getItem(KEY); } catch (e) { /* 隐私模式忽略 */ }

    if (saved === 'dark') document.body.classList.add('dark-mode');
    else if (saved === 'light') document.body.classList.remove('dark-mode');

    const toggle = $('.mode-switch__toggle');
    if (!toggle) return;
    toggle.setAttribute('role', 'switch');
    toggle.setAttribute('tabindex', '0');
    toggle.setAttribute('aria-label', '切换深浅色');

    const sync = () => {
      const dark = document.body.classList.contains('dark-mode');
      toggle.setAttribute('aria-checked', dark ? 'true' : 'false');
      try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (e) {}
    };
    sync();

    // dark.js 已在点击时切换类名，这里只做状态同步（用捕获阶段保证顺序）
    toggle.addEventListener('click', () => setTimeout(sync, 0));
    toggle.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        document.body.classList.toggle('dark-mode');
        sync();
      }
    });
  }

  /* ---------------- 侧栏导航高亮 ----------------
     用「参考线」判定当前版块，不用 IntersectionObserver 的百分比区间：
     首屏卡片只有 176px 高，原来的 -20%/-65% 区间（900 视口下 180–315px）根本盖不到它，
     于是滚到顶部时高亮会跳到「作品集」；滚到页底又因为区间空了而卡在「相册」。 */
  let navCleanup = null;
  function initNav() {
    // 局部换页会重建正文，旧的 scroll 监听和旧节点引用必须先清掉
    if (navCleanup) {
      navCleanup();
      navCleanup = null;
    }
    if (!SITE.smoothNav) return;
    // 只接管锚点链接；弹窗类的 href="#" 不参与高亮
    const links = Array.prototype.slice
      .call(document.querySelectorAll('.sb-nav a[href^="#"]'))
      .filter((a) => a.getAttribute('href') !== '#');

    const items = links
      .map((a) => ({ link: a, sec: document.querySelector(a.getAttribute('href')) }))
      .filter((it) => it.sec);
    if (!items.length) return;

    const first = items[0];
    const last = items[items.length - 1];

    const setActive = (id) => {
      links.forEach((a) => a.classList.toggle('is-active', a.getAttribute('href') === '#' + id));
    };

    // 点击后短暂钉住用户点的那一项：内容不够长时（比如最后两块加起来不到一屏），
    // 页底规则会把高亮抢走，看起来就像"点了没反应"。
    let pinned = null;
    let clickAt = 0;

    const update = () => {
      const line = Math.min(window.innerHeight * 0.2, 160);
      const doc = document.documentElement;
      const scrollable = doc.scrollHeight > window.innerHeight + 40;

      if (pinned) {
        const item = items.find((it) => it.sec.id === pinned);
        const r = item && item.sec.getBoundingClientRect();
        if (r && r.bottom > 0 && r.top < window.innerHeight) {
          setActive(pinned);
          return;
        }
        pinned = null;   // 目标已经滚出视口，交回几何判定
      }

      // 滚到页底：最后一块内容再短也点得到它
      if (scrollable && window.scrollY > 0 && window.innerHeight + window.scrollY >= doc.scrollHeight - 2) {
        setActive(last.sec.id);
        return;
      }

      let hit = null;
      let nearest = null;
      for (const it of items) {
        const r = it.sec.getBoundingClientRect();
        if (r.height === 0) continue;   // 还没排版的版块跳过
        if (r.top <= line && r.bottom > line) hit = it;
        const d = Math.abs(r.top - line);
        if (!nearest || d < nearest.d) nearest = { it, d };
      }
      if (!hit && !nearest) return;
      // 参考线落在某个版块内就用它；都不在（页顶 / 版块被压缩）就用顶端最近的那个
      setActive((hit || nearest.it).sec.id);
    };

    let queued = false;
    const onScroll = () => {
      // 点击引发的那段滚动结束后，把控制权交回几何判定
      if (pinned && performance.now() - clickAt > 900) pinned = null;
      if (queued) return;
      queued = true;
      window.requestAnimationFrame(() => {
        queued = false;
        update();
      });
    };

    const clickHandlers = [];
    items.forEach((it, i) => {
      const onClick = (e) => {
        // 第一个版块直接回页面顶部，不依赖 scroll-margin 的写死数值
        if (i === 0) {
          e.preventDefault();
          const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
        }
        // 点击立刻给出反馈，不等滚动结束
        pinned = it.sec.id;
        clickAt = performance.now();
        setActive(it.sec.id);
      };
      it.link.addEventListener('click', onClick);
      clickHandlers.push([it.link, onClick]);
    });

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    // 图片字体加载完高度会变，重新判定一次
    window.addEventListener('load', update);
    const timer = window.setTimeout(update, 400);
    update();

    navCleanup = () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
      window.removeEventListener('load', update);
      window.clearTimeout(timer);
      clickHandlers.forEach(([link, fn]) => link.removeEventListener('click', fn));
    };
  }

  /* ---------------- 移动端抽屉 ---------------- */
  function initDrawer() {
    const toggle = $('#navToggle');
    const sidebar = $('#sidebar');
    const backdrop = $('#navBackdrop');
    if (!toggle || !sidebar) return;

    const isMobile = () => window.matchMedia('(max-width: 767px)').matches;

    const open = () => {
      document.body.classList.add('nav-open');
      toggle.setAttribute('aria-expanded', 'true');
    };
    const close = () => {
      document.body.classList.remove('nav-open');
      toggle.setAttribute('aria-expanded', 'false');
    };
    const flip = () => {
      document.body.classList.contains('nav-open') ? close() : open();
    };

    toggle.addEventListener('click', flip);
    if (backdrop) backdrop.addEventListener('click', close);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') close();
    });

    // 移动端点完导航自动收起
    sidebar.addEventListener('click', (e) => {
      if (e.target.closest('a') && isMobile()) close();
    });

    // 视口变宽时复位，避免桌面端残留 nav-open
    window.addEventListener('resize', () => {
      if (!isMobile()) close();
    });
  }

  /* ---------------- 交互锁（模板默认禁用右键/F12） ---------------- */
  function initLocks() {
    if (SITE.lockInspect) return;
    document.oncontextmenu = null;
    document.onselectstart = null;
    document.onkeydown = null;
  }

  /* ---------------- 欢迎提示 ---------------- */
  function initToast() {
    if (!SITE.welcomeToast || !window.jQuery) return;
    const toast = $('#welcomeToast');
    if (!toast) return;
    window.setTimeout(() => {
      window.jQuery(toast).toast({ delay: 2200 }).toast('show');
    }, 500);
  }

  /* ---------------- 模板遗留的樱花按钮 ----------------
     原模板的 switchSakura() 依赖一个没被打包的脚本，点了会 ReferenceError，
     这里给个明确反馈，顺便留个接入点。 */
  window.switchSakura = function () {
    const toast = $('#welcomeToast');
    if (!toast || !window.jQuery) return;
    const body = toast.querySelector('.toast-body');
    if (body) body.textContent = '樱花特效还没接入，可以自己塞一个落樱脚本 🌸';
    window.jQuery(toast).toast({ delay: 2000 }).toast('show');
  };

  /* ---------------- 内容来源：后台接口 ----------------
     后台（admin/）把内容存进 SQLite，api.php 输出 JSON。
     这里拉取成功后覆盖 SITE；拉不到（比如直接双击本地文件打开）就用上面的内置默认值，
     所以 PHP 挂了页面也不会白屏。
     但刚重启 phpstudy 时 PHP 还可能没就绪，第一次 502 就掉回内置默认值的话，
     相册里删掉的内置图会"复活"，所以失败后先重试几次再兜底。 */
  async function hydrate() {
    if (location.protocol === 'file:') return false;   // file:// 下没有 PHP，跳过请求
    for (let i = 0; i < 4; i++) {
      try {
        const res = await fetch('api.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (res.ok) {
          const data = await res.json();
          if (data && typeof data === 'object' && !Array.isArray(data)) {
            Object.keys(data).forEach((k) => { SITE[k] = data[k]; });
            document.documentElement.dataset.content = 'api';
            return true;
          }
        }
      } catch (e) { /* 服务还没起来，接着重试 */ }
      if (i < 3) await new Promise((r) => setTimeout(r, 400 * (i + 1)));
    }
    document.documentElement.dataset.content = 'fallback';
    return false;
  }

  /* ---------------- 启动 / 局部换页的挂载点 ---------------- */
  let openDetail = null;
  let readyResolve;
  const ready = new Promise((resolve) => { readyResolve = resolve; });

  /** 重建当前正文；router.js 局部换页后会再调一次。
      所有 render 函数都先清空容器，所以可以重复执行而不产生重复内容。 */
  function mountPage() {
    renderIdentity();
    renderQuick();
    renderAbout();
    renderSites();
    renderPay();
    renderWorks(openDetail);
    renderPosts();
    renderGallery();
    renderFriends();
    startRuntime();
    initNav();
  }

  document.addEventListener('DOMContentLoaded', async function () {
    try {
      await hydrate();

      openDetail = bindDetail();
      mountPage();

      // 侧栏 / 顶栏 / 弹窗这些常驻节点，交互只初始化一次
      initTheme();
      initDrawer();
      initLocks();
      initToast();
    } finally {
      // 即使上面某一步出错，router 的局部换页也要能继续，不要一直等
      readyResolve();
    }
  });

  // 给 router.js 用：ready 保证首屏数据已就绪；refresh 让菜单切换也能拿到最新内容
  window.ZhsSite = { ready, refresh: hydrate, mount: mountPage };
})();
