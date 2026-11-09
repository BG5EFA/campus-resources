/* 全站深色模式：跟随系统 + 本地记忆 + 右下角切换（同步加载，避免首屏闪白） */
(function () {
  var KEY = 'bb_theme';
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (_) {}
  var sysDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  var mode = (saved === 'dark' || saved === 'light') ? saved : (sysDark ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', mode);

  function paint() {
    var btn = document.getElementById('wbThemeBtn');
    if (!btn) return;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    btn.textContent = dark ? '☀️' : '🌙';
    btn.title = dark ? '切换到浅色模式' : '切换到深色模式';
    btn.setAttribute('aria-label', btn.title);
  }
  function toggle() {
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem(KEY, next); } catch (_) {}
    paint();
  }
  function init() {
    if (document.getElementById('wbThemeBtn')) return;
    var btn = document.createElement('button');
    btn.id = 'wbThemeBtn';
    btn.type = 'button';
    btn.addEventListener('click', toggle);
    document.body.appendChild(btn);
    paint();
  }
  if (document.body) init();
  else document.addEventListener('DOMContentLoaded', init);
})();
