/* 深浅色切换
   ----------------------------------------------------------------------------
   站点拆成多个页面之后，这里要记住选择：否则在首页切到深色、点「作品集」
   又变回浅色。用 localStorage 存，脚本执行时机在首次绘制之前，不会闪一下。
   ---------------------------------------------------------------------------- */
(function () {
  var KEY = 'zhshy-theme';
  var body = document.body;

  function saved() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function save(v) {
    try { localStorage.setItem(KEY, v); } catch (e) {}
  }

  var s = saved();
  if (s === 'dark') body.classList.add('dark-mode');
  if (s === 'light') body.classList.remove('dark-mode');

  window.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.mode-switch__toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
      var dark = body.classList.toggle('dark-mode');
      save(dark ? 'dark' : 'light');
    });
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
