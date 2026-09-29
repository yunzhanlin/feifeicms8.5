(function () {
  'use strict';
  var toast = document.querySelector('.download-toast');
  var toastTimer = null;
  function notify(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 1800);
  }
  function fallbackCopy(text) {
    var input = document.createElement('textarea');
    var success = false;
    input.value = text;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try { success = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(input);
    return success;
  }
  function copyText(text, message) {
    if (!text) { notify('没有可复制的下载链接'); return; }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { notify(message); }, function () { notify(fallbackCopy(text) ? message : '复制失败，请手动复制'); });
      return;
    }
    notify(fallbackCopy(text) ? message : '复制失败，请手动复制');
  }
  function linksInScope(scopeId) {
    var root = document.getElementById(scopeId);
    var links = [];
    var seen = {};
    if (!root) return links;
    Array.prototype.forEach.call(root.querySelectorAll('.download-copy[data-link]'), function (item) {
      var link = item.getAttribute('data-link') || '';
      if (link && !seen[link]) { seen[link] = true; links.push(link); }
    });
    return links;
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('.download-copy,.download-copy-all') : null;
    if (!button) return;
    if (button.classList.contains('download-copy-all')) {
      var links = linksInScope(button.getAttribute('data-copy-scope') || '');
      copyText(links.join('\n'), '已复制 ' + links.length + ' 条链接');
      return;
    }
    var link = button.getAttribute('data-link') || '';
    copyText(link, link.indexOf('ed2k://') === 0 ? 'ED2K 已复制，请粘贴到下载工具' : '下载链接已复制');
  });
})();
