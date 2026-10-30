/* ============================================================================
   blog.js —— 博客列表页（blog.html）与文章页（post.html）
   ----------------------------------------------------------------------------
   两个页面共用这一个文件，靠 <body data-page="blog|post"> 分流。
   数据来自 api.php：
     ?posts=1&q=关键词&cat=3&page=2&per=8   → 列表 + 分页 + 分类
     ?post=12                               → 单篇正文 + 上下篇
   正文是 Markdown，交给 js/markdown.js 渲染（它先转义 HTML，写 <style> 也不会被执行）。
   ============================================================================ */
(function () {
  'use strict';

  const API = 'api.php';
  const PER = 8;          // 每页几篇，想改分页大小只改这里
  const SEARCH_DELAY = 260; // 输入停顿多久后开始搜

  const $ = (sel, root) => (root || document).querySelector(sel);

  function el(tag, props, children) {
    const node = document.createElement(tag);
    if (props) {
      Object.keys(props).forEach((k) => {
        if (k === 'class') node.className = props[k];
        else if (k === 'text') node.textContent = props[k];
        else if (k === 'html') node.innerHTML = props[k];
        else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), props[k]);
        else if (props[k] !== null && props[k] !== undefined) node.setAttribute(k, props[k]);
      });
    }
    (children || []).forEach((c) => c && node.appendChild(c));
    return node;
  }

  function debounce(fn, wait) {
    let timer = 0;
    return function () {
      const args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn.apply(null, args), wait);
    };
  }

  async function api(query) {
    const res = await fetch(API + query, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    let data = null;
    try { data = await res.json(); } catch (e) { /* 非 JSON（比如 500 的白页） */ }
    if (!res.ok) {
      const err = new Error((data && data.error) || ('HTTP ' + res.status));
      err.status = res.status;
      throw err;
    }
    if (!data) throw new Error('返回内容不是 JSON');
    return data;
  }

  /* ============================ 列表页 ============================ */
  function initList() {
    const list = $('#blogList');
    if (!list) return;

    const input = $('#blogSearch');
    const clearBtn = $('#blogSearchClear');
    const pager = $('#blogPager');
    const countEl = $('#blogCount');
    const catsEl = $('#blogCats');

    const params = new URLSearchParams(location.search);
    let q = (params.get('q') || '').trim();
    let pageNo = Math.max(1, parseInt(params.get('page') || '1', 10) || 1);
    let catId = Math.max(0, parseInt(params.get('cat') || '0', 10) || 0);

    if (input) input.value = q;
    syncClear();

    function syncClear() {
      if (clearBtn && input) clearBtn.hidden = input.value === '';
    }

    function syncUrl() {
      const usp = new URLSearchParams();
      if (q) usp.set('q', q);
      if (catId > 0) usp.set('cat', String(catId));
      if (pageNo > 1) usp.set('page', String(pageNo));
      const s = usp.toString();
      try {
        // 保留 router.js 存的滚动位置，只改查询串
        history.replaceState(history.state, '', s ? location.pathname + '?' + s : location.pathname);
      } catch (e) { /* file:// 下会抛错，忽略 */ }
    }

    /* 分类 chips：全部 + 每个分类（带篇数），当前分类高亮 */
    function renderCats(data) {
      if (!catsEl) return;
      const cats = data.categories || [];
      catsEl.innerHTML = '';
      if (!cats.length) { catsEl.hidden = true; return; }
      catsEl.hidden = false;

      const chip = (label, id, count) => el('button', {
        class: 'blog-cat' + (id === catId ? ' is-active' : ''),
        type: 'button',
        'aria-pressed': id === catId ? 'true' : 'false',
        text: count ? label + ' ' + count : label,
        onclick: () => {
          if (id === catId) return;
          catId = id;
          pageNo = 1;
          load();
        }
      });

      catsEl.appendChild(chip('全部', 0, data.totalAll || 0));
      cats.forEach((c) => catsEl.appendChild(chip(c.name, c.id, c.count)));
    }

    function renderPager(data) {
      if (!pager) return;
      pager.innerHTML = '';
      if (data.pages <= 1) return;

      const mk = (label, target, opts) => {
        const o = opts || {};
        return el('button', {
          class: 'blog-pager__btn' + (o.active ? ' is-active' : ''),
          type: 'button',
          'aria-current': o.active ? 'page' : null,
          disabled: o.disabled ? '' : null,
          text: label,
          onclick: () => { if (!o.disabled && !o.active) go(target); }
        });
      };

      const nums = [];
      if (data.pages <= 7) {
        for (let i = 1; i <= data.pages; i++) nums.push(i);
      } else {
        nums.push(1);
        const start = Math.max(2, data.page - 1);
        const end = Math.min(data.pages - 1, data.page + 1);
        if (start > 2) nums.push('…');
        for (let i = start; i <= end; i++) nums.push(i);
        if (end < data.pages - 1) nums.push('…');
        nums.push(data.pages);
      }

      pager.appendChild(mk('上一页', data.page - 1, { disabled: data.page <= 1 }));
      nums.forEach((n) => {
        if (n === '…') pager.appendChild(el('span', { class: 'blog-pager__gap', text: '…' }));
        else pager.appendChild(mk(String(n), n, { active: n === data.page }));
      });
      pager.appendChild(mk('下一页', data.page + 1, { disabled: data.page >= data.pages }));
    }

    function render(data) {
      list.innerHTML = '';
      renderCats(data);
      if (countEl) countEl.textContent = data.total ? data.total + ' 篇' : '';

      if (!data.items.length) {
        const msg = q
          ? '没有找到包含「' + q + '」的文章。'
          : (catId > 0 ? '这个分类下还没有文章。' : '还没有文章。');
        const hint = q
          ? '换个关键词试试，或者清空搜索看全部。'
          : (catId > 0 ? '换个分类看看，或者点「全部」。' : '去后台「博客」里发一篇吧。');
        list.appendChild(el('div', { class: 'empty' }, [
          el('p', { text: msg }),
          el('p', { class: 'empty__hint', text: hint })
        ]));
        renderPager(data);
        return;
      }

      data.items.forEach((p) => {
        const href = p.href || ('post.html?id=' + p.id);
        const attrs = { class: 'blog-item', href: href };
        if (p.href) { attrs.target = '_blank'; attrs.rel = 'noopener'; }

        const meta = el('span', { class: 'blog-item__meta' }, [
          el('span', { class: 'blog-item__date', text: p.date })
        ]);
        if (p.cat) meta.appendChild(el('span', { class: 'blog-item__cat', text: p.cat.name }));

        list.appendChild(el('a', attrs, [
          meta,
          el('h3', { class: 'blog-item__title', text: p.title }),
          el('p', { class: 'blog-item__excerpt', text: p.excerpt || '' })
        ]));
      });

      renderPager(data);
    }

    function go(target) {
      pageNo = Math.max(1, target);
      load();
      const card = list.closest('.sec') || list;
      const y = card.getBoundingClientRect().top + window.scrollY - 24;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    }

    async function load() {
      if (!list.isConnected) return;
      list.classList.add('is-loading');
      try {
        const data = await api('?posts=1&per=' + PER + '&page=' + pageNo
          + '&cat=' + catId + '&q=' + encodeURIComponent(q));
        if (!list.isConnected) return;   // 请求期间已经通过菜单换页
        pageNo = data.page;
        render(data);
        syncUrl();
      } catch (e) {
        if (!list.isConnected) return;
        list.innerHTML = '';
        list.appendChild(el('div', { class: 'empty' }, [
          el('p', { text: '文章列表加载失败：' + e.message }),
          el('p', { class: 'empty__hint', text: '如果是刚部署，确认 data/site.db 和 api.php 都正常。' })
        ]));
        if (pager) pager.innerHTML = '';
        if (countEl) countEl.textContent = '';
      } finally {
        list.classList.remove('is-loading');
      }
    }

    const search = debounce(() => {
      if (!input) return;
      q = input.value.trim();
      pageNo = 1;
      syncClear();
      load();
    }, SEARCH_DELAY);

    if (input) {
      input.addEventListener('input', search);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          q = input.value.trim();
          pageNo = 1;
          syncClear();
          load();
        }
        if (e.key === 'Escape') {
          input.value = '';
          q = '';
          pageNo = 1;
          syncClear();
          load();
        }
      });
    }

    if (clearBtn && input) {
      clearBtn.addEventListener('click', () => {
        input.value = '';
        q = '';
        pageNo = 1;
        syncClear();
        input.focus();
        load();
      });
    }

    return load();
  }

  /* ============================ 文章页 ============================ */
  function paragraphs(text) {
    return String(text || '')
      .split(/\n{2,}/)
      .map((s) => s.trim())
      .filter(Boolean)
      .map((s) => {
        const p = document.createElement('p');
        s.split('\n').forEach((line, i) => {
          if (i) p.appendChild(document.createElement('br'));
          p.appendChild(document.createTextNode(line));
        });
        return p;
      });
  }

  /** head 里没有就建一个、有就改属性；都不影响页面显示 */
  function setHead(selector, tag, attrs) {
    let node = document.head.querySelector(selector);
    if (!node) {
      node = document.createElement(tag);
      node.setAttribute('data-zhs-seo', '');
      document.head.appendChild(node);
    }
    Object.keys(attrs).forEach((k) => node.setAttribute(k, attrs[k]));
    return node;
  }

  /** 分享名片：发到 QQ / 微信 / Twitter 时显示的标题、摘要、封面；顺带给搜索引擎看 */
  function applySeo(post, html) {
    const site = post.siteName || '小悟大人';
    const desc = (post.excerpt || '').trim()
      || String(post.content || '').replace(/[#>*`_~\-]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);

    const found = String(html || '').match(/<img[^>]+src="([^"]+)"/i);
    let image = '';
    if (found) {
      try { image = new URL(found[1], location.href).href; } catch (e) { image = ''; }
    }

    document.title = post.title + ' · ' + site;
    setHead('meta[name="description"]', 'meta', { name: 'description', content: desc });
    setHead('meta[property="og:type"]', 'meta', { property: 'og:type', content: 'article' });
    setHead('meta[property="og:title"]', 'meta', { property: 'og:title', content: post.title });
    setHead('meta[property="og:description"]', 'meta', { property: 'og:description', content: desc });
    setHead('meta[property="og:site_name"]', 'meta', { property: 'og:site_name', content: site });
    setHead('meta[property="og:url"]', 'meta', { property: 'og:url', content: location.href });
    setHead('meta[name="twitter:card"]', 'meta', {
      name: 'twitter:card', content: image ? 'summary_large_image' : 'summary'
    });
    if (image) {
      setHead('meta[property="og:image"]', 'meta', { property: 'og:image', content: image });
      setHead('meta[name="twitter:image"]', 'meta', { name: 'twitter:image', content: image });
    }
    setHead('link[rel="canonical"]', 'link', { rel: 'canonical', href: location.href });

    const ld = {
      '@context': 'https://schema.org',
      '@type': 'BlogPosting',
      headline: post.title,
      description: desc,
      url: location.href,
      mainEntityOfPage: { '@type': 'WebPage', '@id': location.href },
      author: { '@type': 'Person', name: site },
      publisher: { '@type': 'Organization', name: site }
    };
    if (post.date) ld.datePublished = post.date;
    if (image) ld.image = image;

    let schema = document.getElementById('postSchema');
    if (!schema) {
      schema = document.createElement('script');
      schema.type = 'application/ld+json';
      schema.id = 'postSchema';
      document.head.appendChild(schema);
    }
    schema.textContent = JSON.stringify(ld);
  }

  function initPost() {
    const titleEl = $('#articleTitle');
    const metaEl = $('#articleMeta');
    const bodyEl = $('#articleBody');
    const navEl = $('#articleNav');
    if (!bodyEl) return;

    const fail = (msg) => {
      if (!bodyEl.isConnected) return;   // 已经通过菜单离开文章页，别再改标题
      if (titleEl) titleEl.textContent = '文章不存在';
      if (metaEl) metaEl.textContent = '';
      if (navEl) navEl.innerHTML = '';
      bodyEl.innerHTML = '';
      bodyEl.appendChild(el('div', { class: 'empty' }, [
        el('p', { text: msg }),
        el('p', { class: 'empty__hint' }, [
          el('a', { href: 'blog.html', text: '← 回到全部文章' })
        ])
      ]));
      document.title = '文章不存在 · 小悟大人';
    };

    const id = parseInt(new URLSearchParams(location.search).get('id') || '', 10);
    if (!id) return fail('链接里没有文章编号，可能是复制的时候少了一段。');

    api('?post=' + id).then((post) => {
      if (!bodyEl.isConnected) return;   // 已经通过菜单离开文章页
      if (titleEl) titleEl.textContent = post.title;

      const html = window.ZhsMd ? window.ZhsMd.render(post.content || '') : '';
      applySeo(post, html);

      if (metaEl) {
        metaEl.innerHTML = '';
        if (post.date) metaEl.appendChild(el('span', { class: 'article-date', text: post.date }));
        if (post.cat) {
          if (post.date) metaEl.appendChild(el('span', { class: 'article-sep', text: '·' }));
          metaEl.appendChild(el('a', {
            class: 'article-cat',
            href: 'blog.html?cat=' + post.cat.id,
            text: post.cat.name
          }));
        }
        const len = (post.content || '').replace(/\s/g, '').length;
        if (len) {
          metaEl.appendChild(el('span', { class: 'article-sep', text: '·' }));
          metaEl.appendChild(el('span', { text: len + ' 字' }));
        }
      }

      bodyEl.innerHTML = '';
      bodyEl.classList.add('md-body');
      if (html) {
        bodyEl.innerHTML = html;
      } else {
        const ps = paragraphs(post.content || post.excerpt);
        if (ps.length) ps.forEach((p) => bodyEl.appendChild(p));
        else bodyEl.appendChild(el('p', { class: 'article-empty', text: '这篇还没有正文。' }));
      }

      if (navEl) {
        navEl.innerHTML = '';
        const prev = post.neighbors && post.neighbors.prev;
        const next = post.neighbors && post.neighbors.next;
        if (prev) {
          navEl.appendChild(el('a', { class: 'article-nav__item', href: 'post.html?id=' + prev.id }, [
            el('span', { class: 'article-nav__label', text: '← 上一篇' }),
            el('span', { class: 'article-nav__title', text: prev.title })
          ]));
        }
        if (next) {
          navEl.appendChild(el('a', { class: 'article-nav__item article-nav__item--next', href: 'post.html?id=' + next.id }, [
            el('span', { class: 'article-nav__label', text: '下一篇 →' }),
            el('span', { class: 'article-nav__title', text: next.title })
          ]));
        }
      }
    }).catch((e) => {
      fail(e.status === 404 ? '这篇文章可能被删掉了，或者还没发布。' : '加载失败：' + e.message);
    });
  }

  /* ============================ 启动 ============================ */
  function mount() {
    const page = document.body.dataset.page;
    if (page === 'blog') return initList();
    if (page === 'post') return initPost();
  }

  // 首屏自动跑一次；之后菜单局部换页由 router.js 调 ZhsBlog.mount()
  window.ZhsBlog = { mount: mount };
  mount();
})();
