/**
 * admin/assets/editor.js —— 后台的 Markdown 编辑器（工具栏 + 实时预览 + 草稿）
 *
 * 预览用的是 ../js/markdown.js，和前台正文同一个渲染器，
 * 所以"编辑时看到的样子"就是"发布后的样子"，不会出现预览能用、发出去垮掉的情况。
 */
(function () {
  'use strict';

  var DRAFT_KEY = 'zhs-draft-post';

  function debounce(fn, wait) {
    var t = null;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, wait);
    };
  }

  /* 选中就包起来（粗体/斜体/行内代码）；没选中就插入占位词并选中它 */
  function wrapSelection(ta, before, after, placeholder) {
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.slice(s, e) || placeholder || '';
    ta.value = ta.value.slice(0, s) + before + sel + after + ta.value.slice(e);
    ta.focus();
    var caret = s + before.length;
    ta.setSelectionRange(caret, caret + sel.length);
  }

  /* 给选中的每一行加前缀（标题/列表/引用） */
  function prefixLines(ta, prefix) {
    var s = ta.selectionStart, e = ta.selectionEnd;
    var start = ta.value.lastIndexOf('\n', Math.max(0, s - 1)) + 1;
    var nl = ta.value.indexOf('\n', e);
    var end = nl === -1 ? ta.value.length : nl;
    var block = ta.value.slice(start, end);
    var out = block.split('\n').map(function (line) {
      if (!line.trim()) return line;
      return prefix + line.replace(/^\s*([-*+]\s+|\d+[.)]\s+|#{1,6}\s+|>\s?)?/, '');
    }).join('\n');
    ta.value = ta.value.slice(0, start) + out + ta.value.slice(end);
    ta.focus();
    ta.setSelectionRange(start, start + out.length);
  }

  function insertBlock(ta, before, after, placeholder) {
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.slice(s, e) || placeholder;
    ta.value = ta.value.slice(0, s) + before + sel + after + ta.value.slice(e);
    ta.focus();
    var caret = s + before.length;
    ta.setSelectionRange(caret, caret + sel.length);
  }

  /* ---------------- 粘贴 / 拖入图片 ---------------- */

  /** 只挑出图片项；剪贴板里同时有文字和图片时，浏览器会自己决定粘哪个，我们只在有图时接管 */
  function imageFiles(dt) {
    if (!dt || !dt.items) return [];
    var out = [];
    for (var i = 0; i < dt.items.length; i++) {
      var it = dt.items[i];
      if (it.kind === 'file' && /^image\//i.test(it.type)) {
        var f = it.getAsFile();
        if (f) out.push(f);
      }
    }
    return out;
  }

  /** 拖拽的是不是文件（区分「拖一段选中的文字」和「从资源管理器拖一张图」） */
  function hasFiles(dt) {
    return !!dt && Array.prototype.indexOf.call(dt.types || [], 'Files') !== -1;
  }

  /* ---------------- 剪贴板里的「文字 + 图片」 ---------------- */

  /** 把剪贴板 HTML 粗略转成 Markdown：段落/换行/列表/标题/引用/粗体/链接，图片先占位 */
  function htmlToMd(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var imgs = [];
    var out = '';

    function walk(node) {
      if (node.nodeType === 3) {
        out += node.nodeValue.replace(/\s+/g, ' ');
        return;
      }
      if (node.nodeType !== 1) return;

      var tag = node.tagName.toLowerCase();
      if (tag === 'script' || tag === 'style' || tag === 'meta') return;
      if (tag === 'br') { out += '\n'; return; }

      if (tag === 'img') {
        var src = (node.getAttribute('src') || '').trim();
        if (!src) return;
        out += '\u0000' + imgs.length + '\u0000';
        // alt 里带方括号（网页图注常见「图片[1]-xxx」）会把 ![](...) 语法截断，存之前先去掉
        var alt = (node.getAttribute('alt') || '').replace(/[[\]]/g, '').replace(/\s+/g, ' ').trim();
        imgs.push({ src: src, alt: alt });
        return;
      }

      if (tag === 'a' && /^https?:/i.test(node.getAttribute('href') || '')) {
        var label = (node.textContent || '').trim();
        if (label) {
          out += '[' + label + '](' + node.getAttribute('href') + ')';
          return;
        }
      }

      var block = /^(p|div|section|article|header|footer|blockquote|h[1-6]|ul|ol|table|tr|figure|figcaption)$/.test(tag);
      var prefix = tag === 'li' ? '- ' : (/^h[1-6]$/.test(tag) ? '## ' : (tag === 'blockquote' ? '> ' : ''));
      var wrap = (tag === 'strong' || tag === 'b') ? '**' : ((tag === 'em' || tag === 'i') ? '*' : '');

      if (block && out && !/\n$/.test(out)) out += '\n';
      out += prefix + wrap;
      for (var i = 0; i < node.childNodes.length; i++) walk(node.childNodes[i]);
      out += wrap;
      if (block) out += '\n';
    }

    walk(doc.body || doc.documentElement);

    var text = out
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .replace(/[ \t]{2,}/g, ' ')
      .trim();

    return { text: text, imgs: imgs };
  }

  /** data: 里的 base64 图 → Blob → 上传；http(s) 图 → 交给服务端抓回本地 */
  function importImage(entry, token) {
    var src = (entry.src || '').trim();

    if (/^data:image\//i.test(src)) {
      return fetch(src).then(function (r) { return r.blob(); }).then(function (blob) {
        var ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        return uploadImage(new File([blob], 'pasted.' + ext, { type: blob.type }), token);
      });
    }

    var abs = src.indexOf('//') === 0 ? 'https:' + src : src;
    if (!/^https?:\/\//i.test(abs)) return Promise.reject(new Error('不支持的图片地址'));

    var fd = new FormData();
    fd.set('_csrf', token);
    fd.set('url', abs);
    return fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () {
          return { error: 'HTTP ' + res.status };
        }).then(function (data) {
          if (!res.ok || !data.path) throw new Error((data && data.error) || ('HTTP ' + res.status));
          return data.path;
        });
      });
  }

  /** 用文件名当说明文字；截图/粘贴来的没有有意义的名字，就用「图片」 */
  function altFrom(file) {
    var name = (file && file.name ? file.name : '').replace(/\.[^.]+$/, '').replace(/[[\]]/g, '').trim();
    if (!name || /^(image|blob|paste|clipboard|screenshot|截图)$/i.test(name)) return '图片';
    return name;
  }

  function insertText(ta, text) {
    var s = ta.selectionStart, e = ta.selectionEnd;
    var before = ta.value.slice(0, s);
    // 光标在行首/空文档时上下各留一个空行，粘到句子中间就直接接上
    var pad = (before === '' || /\n$/.test(before)) ? '\n' : '';
    var insert = pad + text + '\n';
    ta.value = before + insert + ta.value.slice(e);
    var caret = s + insert.length;
    ta.focus();
    ta.setSelectionRange(caret, caret);
  }

  function csrfOf(root) {
    var f = (root || document).querySelector('input[name="_csrf"]');
    return f ? f.value : '';
  }

  function uploadImage(file, token) {
    var fd = new FormData();
    fd.set('_csrf', token);
    fd.set('file', file, file.name || 'paste.png');
    return fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () {
          return { error: '服务器返回的不是 JSON（HTTP ' + res.status + '）' };
        }).then(function (data) {
          if (!res.ok || !data || !data.path) throw new Error((data && data.error) || ('HTTP ' + res.status));
          return data.path;
        });
      });
  }

  var ACTIONS = {
    bold: function (ta) { wrapSelection(ta, '**', '**', '粗体'); },
    italic: function (ta) { wrapSelection(ta, '*', '*', '斜体'); },
    code: function (ta) { wrapSelection(ta, '`', '`', 'code'); },
    h2: function (ta) { prefixLines(ta, '## '); },
    ul: function (ta) { prefixLines(ta, '- '); },
    quote: function (ta) { prefixLines(ta, '> '); },
    fence: function (ta) { insertBlock(ta, '\n```\n', '\n```\n', '在这里写代码'); },
    link: function (ta) { wrapSelection(ta, '[', '](https://)', '链接文字'); }
    // img 不在这里：点它是打开文件选择框上传，见 init() 里的 picker
  };

  function countChars(text) {
    var cn = (text.match(/[\u4e00-\u9fa5]/g) || []).length;
    var words = text.replace(/[\u4e00-\u9fa5]/g, ' ').split(/\s+/).filter(Boolean).length;
    return { chars: text.length, cn: cn, words: words };
  }

  function init(root) {
    var ta = root.querySelector('[data-md-input]');
    var preview = root.querySelector('[data-md-preview]');
    var counter = root.querySelector('[data-md-count]');
    var viewBtn = root.querySelector('[data-md-view]');
    var status = root.querySelector('[data-md-status]');
    if (!ta || !preview) return;

    // 点工具栏「图片」按钮时用它选本地文件；选完走和粘贴/拖拽同一条上传逻辑
    var picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/*';
    picker.multiple = true;
    picker.style.display = 'none';
    picker.setAttribute('data-md-picker', '');
    root.appendChild(picker);
    picker.addEventListener('change', function () {
      var files = Array.prototype.slice.call(picker.files || []);
      picker.value = '';
      if (files.length) acceptImages(files);
    });

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text || '';
      status.className = 'md-status' + (isError ? ' is-error' : '');
    }

    /** 预览页在 /admin/ 下，正文里写的是相对站点根的 img/uploads/x.jpg，补一层 ../ 才显示得出来 */
    function fixRelativeSrc(scope) {
      scope.querySelectorAll('img[src]').forEach(function (img) {
        var src = img.getAttribute('src');
        if (/^[a-z][a-z0-9+.-]*:|^\/|^\.\.\//i.test(src)) return;
        img.setAttribute('src', '../' + src.replace(/^\.\//, ''));
      });
    }

    /**
     * 预览里的图片：去掉 loading="lazy"——预览区是个 overflow 滚动容器，
     * lazy 会让图一直不加载，看起来就是「预览里没有图片」。
     * 真加载失败（404 / 路径不对）就红框标出来，别静默留白。
     */
    function watchImages(scope) {
      var broken = 0;
      scope.querySelectorAll('img').forEach(function (img) {
        img.removeAttribute('loading');
        img.setAttribute('decoding', 'async');
        var mark = function () {
          if (img.naturalWidth > 0) return;
          img.classList.add('is-broken');
          img.title = '图片加载失败：' + img.getAttribute('src');
        };
        if (img.complete) mark();
        else {
          img.addEventListener('load', mark, { once: true });
          img.addEventListener('error', mark, { once: true });
        }
      });
      broken = scope.querySelectorAll('img.is-broken').length;
      return broken;
    }

    function paint() {
      preview.innerHTML = window.ZhsMd ? window.ZhsMd.render(ta.value) : '';
      fixRelativeSrc(preview);
      var broken = watchImages(preview);
      if (broken) setStatus(broken + ' 张图片没加载出来（检查路径）', true);
      if (counter) {
        var c = countChars(ta.value);
        counter.textContent = c.chars + ' 字（中文 ' + c.cn + ' 字 · 英文 ' + c.words + ' 词）';
      }
    }

    /* 一张张传，插进去的顺序和选中的顺序一致 */
    function acceptImages(files) {
      var token = csrfOf(ta.form || document);
      var total = files.length;
      var done = 0;
      setStatus('上传中… 0/' + total);

      files.reduce(function (chain, file) {
        return chain.then(function () {
          if (file.size > 5 * 1024 * 1024) {
            throw new Error('「' + (file.name || '图片') + '」超过 5MB');
          }
          return uploadImage(file, token).then(function (path) {
            insertText(ta, '![' + altFrom(file) + '](' + path + ')');
            done++;
            setStatus('已插入 ' + done + '/' + total);
            paint();
          });
        });
      }, Promise.resolve()).then(function () {
        window.setTimeout(function () { setStatus(''); }, 1600);
      }).catch(function (e) {
        setStatus('上传失败：' + e.message, true);
      });
    }

    /** 从媒体库挑一张已经传过的图，插成 ![](img/uploads/…)；弹窗在 assets/media.js 里 */
    function pickFromMedia() {
      if (!window.ZhsMedia || typeof window.ZhsMedia.open !== 'function') {
        setStatus('媒体库脚本没加载出来，刷新页面再试', true);
        return;
      }
      window.ZhsMedia.open(function (rel, item) {
        var alt = (item && item.name ? item.name : '图片').replace(/\.[^.]+$/, '').replace(/[[\]]/g, '').trim();
        insertText(ta, '![' + (alt || '图片') + '](' + rel + ')');
        paint();
        setStatus('已插入媒体库图片');
        window.setTimeout(function () { setStatus(''); }, 1600);
      });
    }

    var repaint = debounce(paint, 120);
    ta.addEventListener('input', function () {
      repaint();
      try { localStorage.setItem(DRAFT_KEY, ta.value); } catch (e) { /* 隐私模式下会失败，忽略 */ }
    });

    // 粘贴：截图（图片文件）和「文字 + 图片」（HTML 里的 <img>）都要能带进正文
    ta.addEventListener('paste', function (ev) {
      var dt = ev.clipboardData;
      if (!dt) return;

      var files = imageFiles(dt);
      var html = '';
      try { html = dt.getData('text/html') || ''; } catch (e) { html = ''; }
      var htmlHasImg = /<img[\s>]/i.test(html);

      // 没有图片文件、HTML 里也没有图 → 纯文字/纯富文本，交给浏览器自己粘
      if (!files.length && !htmlHasImg) return;
      ev.preventDefault();

      var token = csrfOf(ta.form || document);

      // 情况 A：剪贴板里有真正的图片文件（系统截图、资源管理器里复制、Office 里的图）
      if (files.length) {
        var plain = '';
        try { plain = (dt.getData('text/plain') || '').trim(); } catch (e) { plain = ''; }
        if (plain) insertText(ta, plain);
        acceptImages(files);
        return;
      }

      // 情况 B：网页/公众号里「连文字带图」复制 —— 文字在 text/html 里，图片是 <img src="http…"> 或 data:
      var parsed = htmlToMd(html);
      if (!parsed.imgs.length) {
        if (parsed.text) { insertText(ta, parsed.text); paint(); }
        return;
      }

      var total = parsed.imgs.length;
      var done = 0;
      var results = [];
      setStatus('处理图片 0/' + total);

      parsed.imgs.reduce(function (chain, entry, i) {
        return chain.then(function () {
          return importImage(entry, token).then(function (path) {
            results[i] = '![' + (entry.alt || '图片') + '](' + path + ')';
            done++;
            setStatus('已导入图片 ' + done + '/' + total);
          }).catch(function (e) {
            // 抓不回来（内网、防盗链、超时…）就先保留原链接，至少不丢
            results[i] = /^https?:/i.test(entry.src) ? '![' + (entry.alt || '图片') + '](' + entry.src + ')' : '';
            setStatus('第 ' + (i + 1) + ' 张没导进来（' + e.message + '），先用原链接', true);
          });
        });
      }, Promise.resolve()).then(function () {
        var text = parsed.text;
        results.forEach(function (md, i) { text = text.split('\u0000' + i + '\u0000').join(md); });
        text = text.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
        if (text) insertText(ta, text);
        paint();
        if (done) {
          setStatus('已粘贴：文字 + ' + done + ' 张图');
          window.setTimeout(function () { setStatus(''); }, 1800);
        }
      });
    });

    // 从资源管理器拖图片进来：整个编辑区（工具栏、预览、提示）都是落点，拖进来时有高亮
    var dragDepth = 0;
    root.addEventListener('dragenter', function (ev) {
      if (!hasFiles(ev.dataTransfer)) return;
      dragDepth++;
      root.classList.add('is-dragging');
    });
    root.addEventListener('dragover', function (ev) {
      if (!hasFiles(ev.dataTransfer)) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'copy';
    });
    root.addEventListener('dragleave', function () {
      dragDepth = Math.max(0, dragDepth - 1);
      if (!dragDepth) root.classList.remove('is-dragging');
    });
    root.addEventListener('drop', function (ev) {
      if (!hasFiles(ev.dataTransfer)) return;
      ev.preventDefault();
      dragDepth = 0;
      root.classList.remove('is-dragging');
      var files = imageFiles(ev.dataTransfer);
      if (!files.length) {
        setStatus('只支持图片：jpg / png / gif / webp / svg', true);
        return;
      }
      acceptImages(files);
    });

    root.querySelectorAll('[data-md-act]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var act = btn.getAttribute('data-md-act');
        if (act === 'img') { picker.click(); return; }   // 选本地文件上传，不再插占位路径
        if (act === 'media') { pickFromMedia(); return; } // 已有图片：直接在媒体库里挑，不重复上传
        var fn = ACTIONS[act];
        if (fn) fn(ta);
        paint();
      });
    });

    if (viewBtn) {
      viewBtn.addEventListener('click', function () {
        var nowPreview = root.classList.toggle('md--only-preview');
        viewBtn.textContent = nowPreview ? '只看编辑' : '只看预览';
        if (!nowPreview) ta.focus();
      });
    }

    // Tab 在编辑区里应该是缩进，而不是跳去下一个输入框
    ta.addEventListener('keydown', function (ev) {
      if (ev.key === 'Tab' && !ev.shiftKey && !ev.ctrlKey && !ev.altKey) {
        ev.preventDefault();
        insertBlock(ta, '  ', '', '');
      }
    });

    // 提交前清掉草稿，免得下次新建文章时又把上一篇弹回来
    var form = ta.form;
    if (form) {
      form.addEventListener('submit', function () {
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* 忽略 */ }
      });
    }

    paint();
  }

  function boot() {
    document.querySelectorAll('[data-md]').forEach(init);

    // 拖到编辑区外面时，别让浏览器把图片当页面打开（那会把辛苦写的正文刷掉）
    ['dragover', 'drop'].forEach(function (type) {
      document.addEventListener(type, function (ev) {
        if (!hasFiles(ev.dataTransfer)) return;
        if (ev.target && ev.target.closest && ev.target.closest('[data-md]')) return;
        ev.preventDefault();
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
