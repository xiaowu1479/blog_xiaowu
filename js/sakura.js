/* ============================================================================
   sakura.js —— 樱花飘落特效（全站生效：首页与作品集 / 博客 / 相册 / 友链 / 文章页）
   ----------------------------------------------------------------------------
   首页「更多工具」里的樱花按钮调用 window.switchSakura() 开关。

   · 画布 pointer-events:none，花瓣不会挡住点击
   · 默认开启（系统开启「减少动态效果」时不自动播放），手动点击仍然可以开
   · 开关状态记在 localStorage，换页、下次打开都保持
   · 标签页切到后台时暂停动画，切回来继续
   · 花瓣图片在 img/sakura-petal.png，取自原模板的 sakura.js
   ============================================================================ */
(function () {
  'use strict';

  var STORAGE_KEY = 'zhs-sakura';
  var PETAL_SRC = 'img/sakura-petal.png';
  var PETAL_BASE = 40;          // 原图绘制基准尺寸（px）

  var img = null;
  var imgReady = false;
  var canvas = null;
  var ctx = null;
  var petals = [];
  var rafId = 0;
  var running = false;
  var lastTs = 0;
  var dpr = 1;
  var visible = !document.hidden;

  function prefersReduced() {
    try {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  function rand(min, max) {
    return min + Math.random() * (max - min);
  }

  function petalCount() {
    return (window.innerWidth || 1024) < 768 ? 26 : 50;
  }

  /** 重新投放一片花瓣；initial=true 时先铺满全屏 */
  function resetPetal(p, initial) {
    var w = window.innerWidth || 1024;
    var h = window.innerHeight || 768;
    p.x = Math.random() * w;
    p.y = initial ? Math.random() * h : -60 - Math.random() * 80;
    p.size = PETAL_BASE * rand(0.35, 1.15);
    p.rot = Math.random() * Math.PI * 2;
    p.rotSpeed = rand(-0.7, 0.7);
    p.vy = rand(22, 64);          // 下落速度：px/秒
    p.vx = rand(-16, 16);         // 横向漂移：px/秒
    p.swayPhase = Math.random() * Math.PI * 2;
    p.swaySpeed = rand(0.7, 1.8);
    p.swayAmp = rand(6, 20);      // 左右摆动幅度
    p.alpha = rand(0.65, 1);
  }

  function buildPetals() {
    var n = petalCount();
    petals = [];
    for (var i = 0; i < n; i++) {
      var p = {};
      resetPetal(p, true);
      petals.push(p);
    }
  }

  /** 按设备像素比放大画布，避免高分屏发虚 */
  function resize() {
    if (!canvas || !ctx) return;
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    var w = window.innerWidth;
    var h = window.innerHeight;
    canvas.width = Math.max(1, Math.round(w * dpr));
    canvas.height = Math.max(1, Math.round(h * dpr));
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function createCanvas() {
    if (canvas) return;
    canvas = document.createElement('canvas');
    canvas.id = 'canvas_sakura';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.appendChild(canvas);
    ctx = canvas.getContext('2d');
    resize();
    buildPetals();
    window.addEventListener('resize', resize, { passive: true });
  }

  function removeCanvas() {
    window.removeEventListener('resize', resize);
    if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
    canvas = null;
    ctx = null;
    petals = [];
    lastTs = 0;
  }

  function frame(ts) {
    if (!running) return;
    if (!lastTs) lastTs = ts;
    var dt = Math.min((ts - lastTs) / 1000, 0.05);   // 切回前台时不要一次跳太远
    lastTs = ts;

    var w = window.innerWidth;
    var h = window.innerHeight;

    ctx.clearRect(0, 0, w, h);
    for (var i = 0; i < petals.length; i++) {
      var p = petals[i];
      p.swayPhase += p.swaySpeed * dt;
      p.x += (p.vx + Math.cos(p.swayPhase) * p.swayAmp) * dt;
      p.y += p.vy * dt;
      p.rot += p.rotSpeed * dt;

      if (p.y > h + 80 || p.x < -100 || p.x > w + 100) resetPetal(p, false);

      var half = p.size / 2;
      ctx.save();
      ctx.globalAlpha = p.alpha;
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      ctx.drawImage(img, -half, -half, p.size, p.size);
      ctx.restore();
    }

    rafId = window.requestAnimationFrame(frame);
  }

  function play() {
    if (running || !imgReady) return;
    createCanvas();
    if (!visible) return;         // 后台标签页先不跑，visibilitychange 里恢复
    running = true;
    lastTs = 0;
    rafId = window.requestAnimationFrame(frame);
  }

  function pause() {
    running = false;
    if (rafId) {
      window.cancelAnimationFrame(rafId);
      rafId = 0;
    }
    lastTs = 0;
  }

  function ensureImage() {
    if (img) return;
    img = new Image();
    img.onload = function () {
      imgReady = true;
      // 图片解码期间用户可能已经关掉了，这里再看一眼状态
      if (document.body.classList.contains('sakura-on')) play();
    };
    img.src = PETAL_SRC;
    if (img.complete && img.naturalWidth) imgReady = true;
  }

  function store(on) {
    try {
      window.localStorage.setItem(STORAGE_KEY, on ? 'on' : 'off');
    } catch (e) { /* 隐私模式下写不了就算了 */ }
  }

  /** 'on' / 'off' / ''（从没选过，默认开着） */
  function preference() {
    try {
      var v = window.localStorage.getItem(STORAGE_KEY);
      return v === 'on' || v === 'off' ? v : '';
    } catch (e) {
      return '';
    }
  }

  function toast(text) {
    var el = document.getElementById('welcomeToast');
    if (!el || !window.jQuery) return;
    var body = el.querySelector('.toast-body');
    if (body) body.textContent = text;
    window.jQuery(el).toast({ delay: 1800 }).toast('show');
  }

  function start(remember) {
    if (remember !== false) store(true);
    document.body.classList.add('sakura-on');
    ensureImage();
    if (imgReady) play();
  }

  function stop(remember) {
    if (remember !== false) store(false);
    document.body.classList.remove('sakura-on');
    pause();
    removeCanvas();
  }

  function toggle() {
    if (document.body.classList.contains('sakura-on')) {
      stop();
      toast('樱花特效已关闭 🌸');
    } else {
      start();
      toast('樱花飘落已开启 🌸');
    }
  }

  function init() {
    ensureImage();
    // 没选过时默认开；选过「关」就保持关；减少动态效果时不自动播放
    if (preference() !== 'off' && !prefersReduced()) start(false);
  }

  document.addEventListener('visibilitychange', function () {
    visible = !document.hidden;
    if (!visible) pause();
    else if (document.body.classList.contains('sakura-on') && imgReady) play();
  });

  window.ZhsSakura = {
    toggle: toggle,
    start: function () { start(); },
    stop: function () { stop(); },
    isOn: function () { return document.body.classList.contains('sakura-on'); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
