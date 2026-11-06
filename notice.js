/* 校园墙 · 内容规范公告（全站通用，首次访问自动弹出一次）
   文案为固定内容；如需改文案，请把 NOTICE_KEY 版本号 +1，所有人会重新看到一次。 */
(function () {
  var NOTICE_KEY = 'campus_notice_v1';

  var css = ''
    + '.cn-mask{position:fixed;inset:0;background:rgba(10,22,46,.5);z-index:300;'
    + 'display:none;align-items:center;justify-content:center;padding:18px}'
    + '.cn-mask.show{display:flex}'
    + '.cn-box{background:#fff;border-radius:18px;padding:22px 20px;width:100%;max-width:430px;'
    + 'max-height:86vh;overflow:auto;box-shadow:0 20px 50px rgba(20,35,63,.3)}'
    + '.cn-box h3{font-size:18px;color:#1346c7;margin-bottom:6px;text-align:center}'
    + '.cn-sub{font-size:12px;color:#6b7c9c;text-align:center;line-height:1.6;margin-bottom:14px}'
    + '.cn-list{margin:0 0 12px;padding-left:20px}'
    + '.cn-list li{font-size:13px;line-height:1.8;color:#14233f;margin-bottom:8px}'
    + '.cn-note{font-size:12px;line-height:1.7;color:#6b7c9c;background:#f4f9ff;'
    + 'border:1px solid #d4e3ff;border-radius:10px;padding:10px 12px;margin-bottom:16px}'
    + '.cn-ok{width:100%;background:#2f7bff;color:#fff;border:none;border-radius:11px;'
    + 'padding:11px;font-size:15px;cursor:pointer;font-family:inherit}'
    + '.cn-ok:active{transform:scale(.98)}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  var mask = document.createElement('div');
  mask.className = 'cn-mask';
  mask.innerHTML = ''
    + '<div class="cn-box" role="dialog" aria-modal="true" aria-label="内容规范公告">'
    + '<h3>内容规范公告</h3>'
    + '<p class="cn-sub">温州市滨海高级中学校园墙 · 请一起维护友善的交流环境</p>'
    + '<ol class="cn-list">'
    + '<li>文明发言：禁止辱骂、人身攻击、歧视、煽动对立；不良用语会被系统自动替换为 *。</li>'
    + '<li>保护隐私：请勿公开他人姓名、班级、手机号、照片等信息，不要发布未经允许的偷拍内容。</li>'
    + '<li>请勿发布广告、刷屏、诈骗链接、二维码导流，以及任何违法违规内容。</li>'
    + '<li>帖子、评论和学习资料均需审核通过后才会公开，请耐心等待；学习专区需登录后查看。</li>'
    + '<li>转载资料请注明来源，尊重原作者；上传内容请自行确认不侵犯他人权益。</li>'
    + '</ol>'
    + '<p class="cn-note">违规内容会被删除，情节严重者账号将被封禁。有任何建议可联系微信号 BG5EFA。</p>'
    + '<button type="button" class="cn-ok">我知道了</button>'
    + '</div>';
  document.body.appendChild(mask);

  function show() { mask.classList.add('show'); }
  function hide() {
    mask.classList.remove('show');
    try { localStorage.setItem(NOTICE_KEY, '1'); } catch (e) {}
  }

  mask.querySelector('.cn-ok').addEventListener('click', hide);
  mask.addEventListener('click', function (e) { if (e.target === mask) hide(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && mask.classList.contains('show')) hide();
  });

  window.showCampusNotice = show;

  var read = false;
  try { read = localStorage.getItem(NOTICE_KEY) === '1'; } catch (e) {}
  if (!read) setTimeout(show, 450);
})();
