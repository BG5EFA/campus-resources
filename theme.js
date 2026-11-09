/* 全站外观：浅色 / 深色 / 自动（跟随系统）三态选择；head 同步执行避免首屏闪白 */
(function () {
  'use strict';
  var KEY = 'bb_theme';
  var root = document.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var MODES = [
    { id: 'light', icon: '\u2600\uFE0F', label: '浅色', desc: '始终使用浅色' },
    { id: 'dark',  icon: '\uD83C\uDF19', label: '深色', desc: '始终使用深色' },
    { id: 'auto',  icon: '\uD83C\uDF17', label: '自动', desc: '跟随系统设置' }
  ];
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (_) {}
  var mode = isValid(saved) ? saved : 'auto'; // 未设置过 => 默认自动（跟随系统）
  var sysDark = mq ? mq.matches : false;

  function isValid(m) { return m === 'light' || m === 'dark' || m === 'auto'; }
  function byId(id) { return document.getElementById(id); }
  function resolved(m) { return m === 'auto' ? (sysDark ? 'dark' : 'light') : m; }
  function findMode(id) {
    for (var i = 0; i < MODES.length; i++) if (MODES[i].id === id) return MODES[i];
    return MODES[2];
  }

  // 同步按钮图标 / 提示 / 菜单选中态
  function paint() {
    var btn = byId('wbThemeBtn');
    if (!btn) return;
    var cur = findMode(mode);
    btn.textContent = cur.icon;
    var tip = '外观设置 · 当前：' + cur.label +
      (mode === 'auto' ? '（系统' + (sysDark ? '深色' : '浅色') + '）' : '');
    btn.title = tip;
    btn.setAttribute('aria-label', tip);

    var menu = byId('wbThemeMenu');
    if (!menu) return;
    var items = menu.querySelectorAll('[data-mode]');
    for (var i = 0; i < items.length; i++) {
      var on = items[i].getAttribute('data-mode') === mode;
      items[i].classList.toggle('on', on);
      items[i].setAttribute('aria-checked', on ? 'true' : 'false');
      if (items[i].getAttribute('data-mode') === 'auto') {
        var sm = items[i].querySelector('small');
        if (sm) sm.textContent = '跟随系统（当前' + (sysDark ? '深色' : '浅色') + '）';
      }
    }
  }

  function apply(m, persist) {
    mode = m;
    root.setAttribute('data-theme', resolved(m));
    root.setAttribute('data-theme-mode', m);
    if (persist) { try { localStorage.setItem(KEY, m); } catch (_) {} }
    paint();
  }

  // 首屏：不写入 localStorage，未设置过的用户保持「自动」
  apply(mode, false);

  // 自动模式下，系统主题切换时实时响应
  if (mq) {
    var onSysChange = function (e) {
      sysDark = e.matches;
      if (mode === 'auto') root.setAttribute('data-theme', resolved('auto'));
      paint();
    };
    if (mq.addEventListener) mq.addEventListener('change', onSysChange);
    else if (mq.addListener) mq.addListener(onSysChange);
  }
  // 多标签页同步
  window.addEventListener('storage', function (e) {
    if (e.key !== KEY) return;
    apply(isValid(e.newValue) ? e.newValue : 'auto', false);
  });

  function items() {
    var menu = byId('wbThemeMenu');
    return menu ? Array.prototype.slice.call(menu.querySelectorAll('[data-mode]')) : [];
  }
  function openMenu() {
    var menu = byId('wbThemeMenu'), btn = byId('wbThemeBtn');
    if (!menu || !btn) return;
    menu.classList.add('open');
    menu.setAttribute('aria-hidden', 'false');
    btn.setAttribute('aria-expanded', 'true');
    paint();
    var cur = menu.querySelector('[data-mode].on') || menu.querySelector('[data-mode]');
    if (cur) cur.focus();
  }
  function closeMenu(focus) {
    var menu = byId('wbThemeMenu'), btn = byId('wbThemeBtn');
    if (!menu || !btn || !menu.classList.contains('open')) return;
    menu.classList.remove('open');
    menu.setAttribute('aria-hidden', 'true');
    btn.setAttribute('aria-expanded', 'false');
    if (focus) btn.focus();
  }
  function toggleMenu() {
    var menu = byId('wbThemeMenu');
    if (menu && menu.classList.contains('open')) closeMenu(true);
    else openMenu();
  }
  // 快速切换：按当前实际外观翻转（自动模式下切为手动）
  function quickToggle() {
    apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
  }

  function init() {
    if (byId('wbThemeBtn')) return;
    var wrap = document.createElement('div');
    wrap.id = 'wbThemeWrap';

    var btn = document.createElement('button');
    btn.id = 'wbThemeBtn';
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function (e) { e.stopPropagation(); toggleMenu(); });

    var menu = document.createElement('div');
    menu.id = 'wbThemeMenu';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', '外观模式');
    menu.setAttribute('aria-hidden', 'true');

    var title = document.createElement('div');
    title.className = 'wb-theme-title';
    title.textContent = '外观模式';
    menu.appendChild(title);

    MODES.forEach(function (m) {
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'wb-theme-item';
      item.setAttribute('role', 'menuitemradio');
      item.setAttribute('aria-checked', 'false');
      item.setAttribute('data-mode', m.id);
      var ic = document.createElement('span'); ic.className = 'i'; ic.textContent = m.icon;
      var tx = document.createElement('span'); tx.className = 't';
      var b = document.createElement('b'); b.textContent = m.label;
      var s = document.createElement('small'); s.textContent = m.desc;
      tx.appendChild(b); tx.appendChild(s);
      var ok = document.createElement('span'); ok.className = 'ok'; ok.textContent = '\u2713';
      item.appendChild(ic); item.appendChild(tx); item.appendChild(ok);
      item.addEventListener('click', function (e) {
        e.stopPropagation();
        apply(m.id, true);
        closeMenu(true);
      });
      menu.appendChild(item);
    });

    var hint = document.createElement('div');
    hint.className = 'wb-theme-hint';
    hint.textContent = 'Alt + T 打开 · Shift + D 快速切换';
    menu.appendChild(hint);

    wrap.appendChild(menu);
    wrap.appendChild(btn);
    document.body.appendChild(wrap);
    paint();

    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) closeMenu(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeMenu(true); return; }
      if (e.altKey && (e.key === 't' || e.key === 'T')) { e.preventDefault(); toggleMenu(); return; }
      var el = e.target || {};
      var tag = (el.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
      if (!typing && e.shiftKey && (e.key === 'D' || e.key === 'd')) {
        e.preventDefault(); quickToggle(); return;
      }
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && menu.classList.contains('open')) {
        var list = items();
        if (!list.length) return;
        var idx = list.indexOf(document.activeElement);
        var next = e.key === 'ArrowDown'
          ? (idx + 1) % list.length : (idx - 1 + list.length) % list.length;
        list[next].focus();
        e.preventDefault();
      }
    });
  }

  if (document.body) init();
  else document.addEventListener('DOMContentLoaded', init);
})();
