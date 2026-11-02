/* 深浅色：按时间自动切换 + 手动临时覆盖
   ----------------------------------------------------------------------------
   规则（本地时间）：
     19:00 – 次日 07:00   深色
     07:00 – 19:00        浅色

   手动点开关会覆盖自动结果，但只保持到「下一次自动切换」为止：
     比如 20:00 手动切到浅色 → 保持到次日 07:00，然后恢复自动（浅色）
          10:00 手动切到深色 → 保持到当天 19:00，然后恢复自动（深色）

   脚本在 <body> 开头执行，先于正文绘制，所以不会先闪一下浅色。
   ---------------------------------------------------------------------------- */
(function () {
  var KEY       = 'zhanshiye-theme';          // 手动选择：dark / light
  var UNTIL_KEY = 'zhanshiye-theme-until';    // 手动选择的有效截止时间（毫秒时间戳）
  var OLD_KEY   = 'zhshy-theme';              // 旧版键名，只读来清掉，避免两套状态打架
  var DARK_FROM = 19;                         // 19:00 进入深色
  var DARK_TO   = 7;                          // 07:00 回到浅色
  var body      = document.body;
  var timer     = null;

  function get(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function set(key, value) {
    try {
      if (value === null) localStorage.removeItem(key);
      else localStorage.setItem(key, value);
    } catch (e) { /* 隐私模式忽略 */ }
  }

  function isNight(date) {
    var h = date.getHours();
    return h >= DARK_FROM || h < DARK_TO;
  }

  /** 下一次自动切换的时间点 */
  function nextBoundary(date) {
    var t = new Date(date.getTime());
    var h = t.getHours();
    if (h >= DARK_FROM) {
      t.setDate(t.getDate() + 1);
      t.setHours(DARK_TO, 0, 0, 0);
    } else if (h < DARK_TO) {
      t.setHours(DARK_TO, 0, 0, 0);
    } else {
      t.setHours(DARK_FROM, 0, 0, 0);
    }
    return t;
  }

  function autoTheme(date) {
    return isNight(date) ? 'dark' : 'light';
  }

  /** 当前应该用什么主题：手动选择未过期就用手动，否则按时间自动 */
  function current(date) {
    date = date || new Date();
    var mode  = get(KEY);
    var until = parseInt(get(UNTIL_KEY) || '0', 10) || 0;

    if ((mode === 'dark' || mode === 'light') && until > date.getTime()) {
      return { mode: mode, source: 'manual', until: until };
    }

    // 过期的选择清掉，避免下次又被它盖住
    if (mode !== null || until) {
      set(KEY, null);
      set(UNTIL_KEY, null);
    }
    return { mode: autoTheme(date), source: 'auto', until: nextBoundary(date).getTime() };
  }

  function apply(mode, source) {
    var dark = mode === 'dark';
    body.classList.toggle('dark-mode', dark);
    body.dataset.themeMode = source;
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    try {
      window.dispatchEvent(new CustomEvent('zhs:themechange', {
        detail: { dark: dark, source: source }
      }));
    } catch (e) { /* 老浏览器忽略 */ }
  }

  function schedule() {
    if (timer) clearTimeout(timer);
    var now  = new Date();
    var next = nextBoundary(now).getTime();
    // 多等 1 秒，避免正好卡在边界上算出旧结果
    timer = setTimeout(refresh, Math.max(1000, next - now.getTime() + 1000));
  }

  function refresh() {
    var picked = current(new Date());
    apply(picked.mode, picked.source);
    schedule();
    return picked;
  }

  /** 手动切换：结果保持到下一次自动切换 */
  function toggle() {
    var now  = new Date();
    var mode = body.classList.contains('dark-mode') ? 'light' : 'dark';
    set(KEY, mode);
    set(UNTIL_KEY, String(nextBoundary(now).getTime()));
    apply(mode, 'manual');
    schedule();
    return mode;
  }

  // 旧版键名不再使用，清掉以免和新的时间规则互相覆盖
  set(OLD_KEY, null);

  refresh();

  window.ZhsTheme = {
    refresh: refresh,
    toggle: toggle,
    current: current,
    isNight: isNight,
    nextBoundary: nextBoundary,
    DARK_FROM: DARK_FROM,
    DARK_TO: DARK_TO
  };

  window.addEventListener('DOMContentLoaded', function () {
    var toggleEl = document.querySelector('.mode-switch__toggle');
    if (toggleEl) {
      toggleEl.addEventListener('click', function () { toggle(); });
    }
  });

  // 浏览器标签页放了一夜再回来，或者系统时间变了：重新判断一次
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refresh();
  });
})();


document.oncontextmenu=new Function("event.returnValue=false");
document.onselectstart=new Function("event.returnValue=false");

document.onkeydown = function () {
          if (window.event && window.event.keyCode == 123) {
              event.keyCode = 0;
              event.returnValue = false;
              return false;
          }
      };
