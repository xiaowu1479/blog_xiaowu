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

  /* ---------------- EasyMDE（第三方 Markdown 编辑器） ---------------- */

  /** 预览是渲染在 /admin/ 下的，正文里的 img/uploads/x.jpg 要补一层 ../ 才显示得出来 */
  function fixPreviewHtml(html) {
    return String(html).replace(
      /(<img\b[^>]*\bsrc=")(?!https?:|\/\/|\/|\.\.\/|data:)([^"]+)/gi,
      '$1../$2'
    );
  }

  /**
   * 编辑区的行内图片缩略图：
   * 把非当前行的 ![alt](url) 替换成小图，点一下回到源码继续改；
   * 光标所在行始终保持 Markdown 源码，避免「图片挡住了正在写的内容」。
   */
  function inlineImages(cm) {
    var marks = [];
    var timer = null;
    var refreshTimer = null;

    function clearMarks() {
      marks.forEach(function (mark) { mark.clear(); });
      marks = [];
    }

    /** 图片是异步解码的，尺寸出来后要让 CodeMirror 重新量行高，否则文字会叠在一起 */
    function refreshSoon() {
      window.clearTimeout(refreshTimer);
      refreshTimer = window.setTimeout(function () { cm.refresh(); }, 60);
    }

    /** 后台在 /admin/ 下，站点根相对路径要补 ../ */
    function imageSrc(raw) {
      if (/^(https?:)?\/\//i.test(raw) || /^data:/i.test(raw) || raw.charAt(0) === '/') return raw;
      return '../' + raw.replace(/^\.\//, '');
    }

    var lastKey = null;

    function draw(force) {
      if (cm.somethingSelected()) { clearMarks(); return; }

      var currentLine = cm.getCursor().line;
      var vp = cm.getViewport();
      var key = vp.from + ':' + vp.to + ':' + currentLine + ':' + cm.getValue().length;
      // 内容/光标没变时不要重建，否则「刷新 → 图片 load → 再刷新」会来回死循环
      if (!force && key === lastKey) return;
      lastKey = key;

      clearMarks();
      var re = /!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g;

      for (var ln = vp.from; ln <= vp.to; ln++) {
        if (ln === currentLine) continue;
        var line = cm.getLine(ln);
        if (!line || line.indexOf('![') === -1) continue;

        re.lastIndex = 0;
        var m;
        while ((m = re.exec(line))) {
          (function (match) {
            var alt = match[1] || '';
            var path = match[2];
            var from = { line: ln, ch: match.index };
            var to = { line: ln, ch: match.index + match[0].length };

            var wrap = document.createElement('span');
            wrap.className = 'md-inline-img';
            wrap.title = '点击编辑这段 Markdown';

            var img = document.createElement('img');
            img.alt = alt;
            img.src = imageSrc(path);
            wrap.appendChild(img);

            img.addEventListener('load', refreshSoon);

            if (alt) {
              var cap = document.createElement('span');
              cap.className = 'md-inline-img__cap';
              cap.textContent = alt;
              wrap.appendChild(cap);
            }

            wrap.addEventListener('click', function (e) {
              e.preventDefault();
              e.stopPropagation();
              clearMarks();
              cm.setCursor(from);
              cm.focus();
            });

            img.addEventListener('error', function () {
              var fail = document.createElement('span');
              fail.className = 'md-inline-img__fail';
              fail.textContent = '图片没加载出来：' + path;
              wrap.innerHTML = '';
              wrap.appendChild(fail);
              refreshSoon();
            });

            marks.push(cm.markText(from, to, { replacedWith: wrap, atomic: true }));
          })(m);
        }
      }

      refreshSoon();
    }

    function schedule(force) {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { draw(force); }, 120);
    }

    cm.on('change', function () { schedule(true); });
    cm.on('cursorActivity', function () { schedule(true); });
    cm.on('viewportChange', function () { schedule(false); });
    draw(true);
  }

  /** 页面底部飘一条提示，用于上传失败等需要马上看到的信息 */
  function notify(text) {
    var tip = document.createElement('div');
    tip.className = 'md-easy-toast';
    tip.textContent = text;
    document.body.appendChild(tip);
    window.setTimeout(function () { tip.classList.add('is-out'); }, 2600);
    window.setTimeout(function () { tip.remove(); }, 3200);
  }

  /**
   * 用 Vditor 的即时渲染（ir）接管编辑器：
   * 编辑区就是最终排版，图片直接显示；存储的仍是 Markdown，前台渲染逻辑不用改。
   */
  function initVditor(root, ta) {
    var csrf = csrfOf(ta.form || document);
    var dark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var panes = root.querySelector('.md-panes');
    if (!panes) return;

    // 先隐藏自研工具栏/右栏预览，避免 Vditor 渲染前闪一下
    root.classList.add('md--vditor');

    var host = document.createElement('div');
    host.className = 'md-vditor';
    panes.appendChild(host);

    var vditor = new window.Vditor(host, {
      mode: 'wysiwyg',   // 所见即所得：完全排版，不显示 Markdown 标记
      value: ta.value,
      cdn: 'assets/vendor/vditor',
      lang: 'zh_CN',
      height: 520,
      theme: dark ? 'dark' : 'classic',
      placeholder: '写点东西…图片可以粘贴、拖拽，或用工具栏上传',
      cache: { enable: false },       // 草稿沿用站内自己的 localStorage 逻辑
      counter: { enable: true, type: 'text' },
      outline: { enable: false },
      toolbarConfig: { pin: true },
      preview: {
        // 后台在 /admin/ 下，正文里的 img/uploads/x.jpg 显示时要补 ../（只影响渲染，不改内容）
        markdown: { linkBase: '../' },
        hljs: { enable: true, style: dark ? 'native' : 'github', lineNumber: false },
        theme: { current: dark ? 'dark' : 'light' }
      },
      upload: {
        url: 'upload.php',
        fieldName: 'file',
        accept: 'image/*',
        max: 5 * 1024 * 1024,
        extraData: csrf ? { _csrf: csrf } : {},
        // 不用 format：Vditor 在所见即所得模式下会把图片插成 <img src="相对路径"> 的 HTML，
        // 相对 /admin/ 解析会 404，而且存进正文的是 HTML 而不是 Markdown。
        // 用 success 自己接管，统一插入 Markdown 语法，交给预览渲染补 ../。
        success: function (range, responseText) {
          var res = {};
          try { res = JSON.parse(responseText) || {}; } catch (e) { res = {}; }
          if (!res.path) {
            notify('图片上传失败：' + (res.error || '服务端没有返回图片路径'));
            return;
          }
          var alt = res.path.split('/').pop().replace(/\.[^.]+$/, '').replace(/[[\]]/g, '');
          vditor.insertValue('![' + alt + '](' + res.path + ')', true);
        },
        error: function (msg) { notify('图片上传失败：' + msg); }
      },
      input: function (value) {
        try { localStorage.setItem(DRAFT_KEY, value); } catch (e) { /* 隐私模式下忽略 */ }
      },
      toolbar: [
        'headings', 'bold', 'italic', 'strike', '|',
        'list', 'ordered-list', 'check', 'quote', '|',
        'link', 'upload', 'media', '|',
        'code', 'inline-code', 'table', '|',
        'undo', 'redo', '|',
        'fullscreen'
      ].map(function (item) {
        if (item !== 'media') return item;
        return {
          name: 'media',
          tip: '插入媒体库图片',
          icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M21 5v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Zm-2 12.6-4.3-5.8-3.4 4.4-2.4-2.9L5 18h14v-.4Z"/></svg>',
          click: function () {
            if (!window.ZhsMedia || typeof window.ZhsMedia.open !== 'function') {
              notify('媒体库脚本没加载出来，刷新页面再试');
              return;
            }
            window.ZhsMedia.open(function (rel, item) {
              var alt = (item && item.name ? item.name : '图片')
                .replace(/\.[^.]+$/, '').replace(/[[\]]/g, '').trim() || '图片';
              vditor.insertValue('![' + alt + '](' + rel + ')', true);
            });
          }
        };
      })
    });

    // 提交前把 Markdown 同步回 textarea
    if (ta.form) {
      ta.form.addEventListener('submit', function () {
        ta.value = vditor.getValue();
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* 忽略 */ }
      });
    }
  }

  /**
   * 用 EasyMDE 接管原来的 textarea：
   * 自研的工具栏和右栏预览由 admin.css 里的 .md--easy 规则隐藏，
   * 图片上传、媒体库、草稿、字数统计这些站内逻辑继续保留。
   */
  function initEasy(root, ta) {
    var csrf = csrfOf(ta.form || document);
    var mde = null;

    function status(text) {
      if (mde) mde.updateStatusBar('upload-image', text);
    }

    /** 工具栏「插入本地图片」：选文件 → 上传 → 在光标处插入 Markdown 图片 */
    function pickImage() {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/*';
      input.multiple = true;
      input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        files.forEach(function (file) {
          if (file.size > 5 * 1024 * 1024) { notify('「' + file.name + '」超过 5MB，换一张小点的'); return; }
          status('正在上传 ' + file.name);
          uploadImage(file, csrf).then(function (path) {
            mde.codemirror.replaceSelection('![' + altFrom(file) + '](' + path + ')');
            mde.codemirror.focus();
            status('已插入 ' + file.name);
            window.setTimeout(function () { status('拖拽或粘贴图片可上传'); }, 1600);
          }, function (e) {
            var msg = e && e.message ? e.message : '未知错误';
            status('上传失败：' + msg);
            notify('图片上传失败：' + msg);
          });
        });
      });
      input.click();
    }

    function insertFromMedia() {
      if (!window.ZhsMedia || typeof window.ZhsMedia.open !== 'function') {
        status('媒体库脚本没加载出来，刷新页面再试');
        return;
      }
      window.ZhsMedia.open(function (rel, item) {
        var alt = (item && item.name ? item.name : '图片')
          .replace(/\.[^.]+$/, '').replace(/[[\]]/g, '').trim() || '图片';
        mde.codemirror.replaceSelection('![' + alt + '](' + rel + ')');
        mde.codemirror.focus();
        status('已插入媒体库图片');
        window.setTimeout(function () { status('拖拽或粘贴图片可上传'); }, 1600);
      });
    }

    mde = new window.EasyMDE({
      element: ta,
      autoDownloadFontAwesome: false,      // 站内已经加载了 Font Awesome
      spellChecker: false,                 // 不联网拉英文词典
      nativeSpellcheck: true,
      placeholder: '在这里写 Markdown…',
      minHeight: '340px',
      previewClass: ['editor-preview', 'md-body'],
      uploadImage: true,
      imageAccept: 'image/*',
      imageMaxSize: 5 * 1024 * 1024,
      imageTexts: {
        sbInit: '拖拽或粘贴图片可上传',
        sbOnDragEnter: '松手就会上传这张图',
        sbOnDrop: '正在上传 #images_names#',
        sbOnUploaded: '已插入 #image_name#'
      },
      status: [
        'upload-image',   // 上传进度/结果，必须占一格，否则 updateStatusBar 找不到元素
        {
          className: 'md-easy-count',
          defaultValue: '',
          onUpdate: function (el) {
            var c = countChars(mde ? mde.value() : ta.value);
            el.textContent = c.chars + ' 字（中文 ' + c.cn + ' 字 · 英文 ' + c.words + ' 词）';
          }
        }
      ],
      toolbar: [
        'bold', 'italic', 'strikethrough', '|',
        'heading', 'quote', 'unordered-list', 'ordered-list', '|',
        'link',
        { name: 'upload-local-image', action: pickImage, className: 'fa-solid fa-image', title: '插入本地图片' },
        { name: 'media', action: insertFromMedia, className: 'fa-solid fa-photo-film', title: '插入媒体库图片' },
        '|', 'code', 'horizontal-rule', '|',
        'side-by-side', 'fullscreen', '|',
        'undo', 'redo'
      ],
      // 预览和前台正文用同一个渲染器，避免「预览能用、发出去垮掉」
      previewRender: function (text) {
        return fixPreviewHtml(window.ZhsMd ? window.ZhsMd.render(text) : '');
      },
      imageUploadFunction: function (file, onSuccess, onError) {
        if (file.size > 5 * 1024 * 1024) { onError('图片超过 5MB'); return; }
        uploadImage(file, csrf).then(onSuccess, function (e) {
          onError(e && e.message ? e.message : '上传失败');
        });
      },
      errorCallback: function (msg) {
        status('上传失败：' + msg);
        notify('图片上传失败：' + msg);
      }
    });

    // 建好之后再隐藏自研界面：初始化时 textarea 还要能被 CodeMirror 正常量尺寸
    root.classList.add('md--easy');

    // 编辑区里的图片语法直接显示成缩略图（点一下回源码）
    inlineImages(mde.codemirror);

    function refreshCount() {
      var c = countChars(mde.value());
      mde.updateStatusBar('md-easy-count', c.chars + ' 字（中文 ' + c.cn + ' 字 · 英文 ' + c.words + ' 词）');
    }
    refreshCount();

    // 草稿沿用原来的键，换回旧编辑器时还能接着写
    mde.codemirror.on('change', function () {
      try { localStorage.setItem(DRAFT_KEY, mde.value()); } catch (e) { /* 隐私模式下会失败，忽略 */ }
    });

    // 提交前把内容同步回 textarea（CodeMirror 自己也会同步，这里兜个底）
    if (ta.form) {
      ta.form.addEventListener('submit', function () {
        ta.value = mde.value();
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* 忽略 */ }
      });
    }
  }

  function init(root) {
    var ta = root.querySelector('[data-md-input]');
    var preview = root.querySelector('[data-md-preview]');
    var counter = root.querySelector('[data-md-count]');
    var viewBtn = root.querySelector('[data-md-view]');
    var status = root.querySelector('[data-md-status]');
    if (!ta || !preview) return;

    // 首选 Vditor 即时渲染（所见即所得），加载失败再退回 EasyMDE / 自研文本框
    if (window.Vditor) { initVditor(root, ta); return; }
    if (window.EasyMDE) { initEasy(root, ta); return; }

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
