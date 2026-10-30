/**
 * js/markdown.js —— 极简 Markdown 渲染器（后台预览和前台正文共用同一个文件）
 *
 * 设计取舍：
 *  1. 先整体转义 HTML，再套语法 —— 正文里写 <script> 只会显示成文字，不可能执行。
 *  2. 单个换行渲染成 <br>。标准 Markdown 会把它当空格，但站里老的文章是纯文本，
 *     保留换行才不会让它们变得挤成一团（这个选择的代价是"一句话跨两行"会显示成两行）。
 *  3. 只做够用的语法：标题、粗体、斜体、删除线、行内代码、代码块、列表、引用、
 *     分隔线、链接、图片。表格、脚注、嵌套列表没做。
 *
 * 对外只暴露 window.ZhsMd.render(text) → HTML 字符串。
 */
(function (root) {
  'use strict';

  // 注意：">" 保持原样。HTML 里裸的 > 是合法文本，转义成 &gt; 反而会让 "> 引用" 认不出来。
  var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '>', '"': '&quot;', "'": '&#39;' };

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return ESC_MAP[c]; });
  }

  /* ---------------- 行内：粗体、斜体、代码、链接、图片 ---------------- */
  function inline(text) {
    var codes = [];

    // 行内代码先挖出来，免得里面的 * 和 _ 被当成强调
    text = text.replace(/`([^`\n]+)`/g, function (_, code) {
      codes.push(code);
      return '\u0000' + (codes.length - 1) + '\u0000';
    });

    // 图片要在链接之前处理，否则 ![x](y) 会被当成链接 [x](y)。
    // alt 里允许一层方括号：从网页复制来的图注常带「图片[1]-xxx」，
    // 用 [^\]]* 会在第一个 ] 处截断，整条语法就渲染不出来了。
    // 先换成占位符，免得 alt 里的 [x](y) 又被下面的链接规则吃掉。
    var imgs = [];
    text = text.replace(/!\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(([^)\n]+)\)/g, function (_, alt, src) {
      imgs.push('<img src="' + src + '" alt="' + alt + '" loading="lazy">');
      return '\u0001' + (imgs.length - 1) + '\u0001';
    });

    text = text.replace(/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(([^)\n]+)\)/g, function (_, label, href) {
      var external = /^(https?:)?\/\//i.test(href);
      return '<a href="' + href + '"' + (external ? ' target="_blank" rel="noopener noreferrer"' : '') + '>'
        + label + '</a>';
    });

    text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
    text = text.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    text = text.replace(/(^|[^_\w])_([^_\n]+)_/g, '$1<em>$2</em>');
    text = text.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');

    text = text.replace(/\u0000(\d+)\u0000/g, function (_, i) {
      return '<code>' + codes[+i] + '</code>';
    });

    return text.replace(/\u0001(\d+)\u0001/g, function (_, i) {
      return imgs[+i];
    });
  }

  /* ---------------- 块级 ---------------- */
  /** 对外的入口：先整篇转义一次，再交给下面的块级解析 */
  function render(source) {
    if (!source) return '';
    var text = escapeHtml(String(source).replace(/\r\n?/g, '\n')).replace(/[ \t]+$/gm, '');
    return renderEscaped(text);
  }

  /** 已经转义过的文本走这里（引用块内部递归用它，避免二次转义） */
  function renderEscaped(text) {
    var lines = text.split('\n');
    var out = [];
    var i = 0;

    function isBlank(line) { return /^\s*$/.test(line); }

    while (i < lines.length) {
      var line = lines[i];

      if (isBlank(line)) { i++; continue; }

      // 代码块 ```lang
      var fence = line.match(/^```\s*([\w-]*)\s*$/);
      if (fence) {
        var lang = fence[1];
        var buf = [];
        i++;
        while (i < lines.length && !/^```\s*$/.test(lines[i])) { buf.push(lines[i]); i++; }
        i++; // 跳过收尾的 ```
        out.push('<pre><code' + (lang ? ' class="lang-' + lang + '"' : '') + '>'
          + buf.join('\n') + '</code></pre>');
        continue;
      }

      // 分隔线
      if (/^ {0,3}(-{3,}|\*{3,}|_{3,})$/.test(line)) { out.push('<hr>'); i++; continue; }

      // 标题
      var head = line.match(/^(#{1,6})\s+(.*)$/);
      if (head) {
        var lv = head[1].length;
        out.push('<h' + lv + '>' + inline(head[2].trim()) + '</h' + lv + '>');
        i++;
        continue;
      }

      // 引用（连续的 > 行算一块，内部再按块级渲染）
      if (/^ {0,3}>\s?/.test(line)) {
        var quote = [];
        while (i < lines.length && /^ {0,3}>\s?/.test(lines[i])) {
          quote.push(lines[i].replace(/^ {0,3}>\s?/, ''));
          i++;
        }
        out.push('<blockquote>' + renderEscaped(quote.join('\n')) + '</blockquote>');
        continue;
      }

      // 无序列表
      if (/^ {0,3}[-*+]\s+/.test(line)) {
        var ul = [];
        while (i < lines.length && /^ {0,3}[-*+]\s+/.test(lines[i])) {
          ul.push(lines[i].replace(/^ {0,3}[-*+]\s+/, ''));
          i++;
          // 列表项的续行（缩进两格以上）
          while (i < lines.length && /^ {2,}\S/.test(lines[i]) && !/^ {0,3}[-*+]\s+/.test(lines[i])) {
            ul[ul.length - 1] += '\n' + lines[i].trim();
            i++;
          }
        }
        out.push('<ul>' + ul.map(function (it) {
          return '<li>' + inline(it).replace(/\n/g, '<br>') + '</li>';
        }).join('') + '</ul>');
        continue;
      }

      // 有序列表
      if (/^ {0,3}\d+[.)]\s+/.test(line)) {
        var ol = [];
        while (i < lines.length && /^ {0,3}\d+[.)]\s+/.test(lines[i])) {
          ol.push(lines[i].replace(/^ {0,3}\d+[.)]\s+/, ''));
          i++;
        }
        out.push('<ol>' + ol.map(function (it) { return '<li>' + inline(it) + '</li>'; }).join('') + '</ol>');
        continue;
      }

      // 段落（空行结束）
      var para = [];
      while (i < lines.length && !isBlank(lines[i])
        && !/^```/.test(lines[i]) && !/^ {0,3}#{1,6}\s/.test(lines[i])
        && !/^ {0,3}>\s?/.test(lines[i]) && !/^ {0,3}[-*+]\s+/.test(lines[i])
        && !/^ {0,3}\d+[.)]\s+/.test(lines[i]) && !/^ {0,3}(-{3,}|\*{3,}|_{3,})$/.test(lines[i])) {
        para.push(lines[i]);
        i++;
      }
      out.push('<p>' + inline(para.join('\n')).replace(/\n/g, '<br>') + '</p>');
    }

    return out.join('\n');
  }

  root.ZhsMd = { render: render, escape: escapeHtml };
})(window);
