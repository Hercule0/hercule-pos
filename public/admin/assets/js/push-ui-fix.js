(function () {
  'use strict';

  function toast(title, message, type) {
    var stack = document.getElementById('app-toast-stack');
    if (!stack) { alert(title + '\n' + message); return; }
    var el = document.createElement('div');
    el.className = 'app-toast ' + (type || 'info');
    el.innerHTML = '<div class="toast-content"><strong></strong><span></span></div><button type="button" class="toast-close-btn">&times;</button>';
    el.querySelector('strong').textContent = title;
    el.querySelector('span').textContent = message;
    el.querySelector('button').onclick = function () { el.remove(); };
    stack.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('is-visible'); });
    setTimeout(function () { if (el.parentNode) el.remove(); }, 9000);
  }

  function jsonResponse(response) {
    return response.json().catch(function () {
      throw new Error('Server returned invalid JSON (HTTP ' + response.status + ')');
    }).then(function (data) {
      if (!response.ok || !data || data.ok !== true) {
        var detail = data && (data.error || data.message || data.code);
        throw new Error(detail || ('HTTP ' + response.status));
      }
      return data;
    });
  }

  function enablePush(button) {
    button.disabled = true;
    var work;
    if (window.HerculePush && typeof window.HerculePush.enable === 'function') {
      work = window.HerculePush.enable();
    } else {
      work = Promise.reject(new Error('Unified push client is not loaded'));
    }
    work.then(function (subscription) {
      if (!subscription || !subscription.endpoint) throw new Error('No push subscription was created');
      var span = button.querySelector('span');
      if (span) span.textContent = 'Alerts Active';
      button.classList.add('is-granted');
      toast('Push Notifications Enabled', 'This phone is subscribed and synced with the server.', 'success');
    }).catch(function (error) {
      toast('Could not enable alerts', error.message || String(error), 'error');
    }).finally(function () { button.disabled = false; });
  }

  function testPush(button) {
    button.disabled = true;
    fetch('/public/admin/test_push.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-Hercule-Push-Test': '1' }
    }).then(jsonResponse).then(function (data) {
      var sent = data.sent != null ? data.sent : (data.successful != null ? data.successful : 0);
      var failed = data.failed != null ? data.failed : 0;
      toast('Test Push', data.message || ('Delivered: ' + sent + ', failed: ' + failed), failed ? 'warning' : 'success');
    }).catch(function (error) {
      toast('Test Push Failed', error.message || String(error), 'error');
    }).finally(function () { button.disabled = false; });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var enable = document.getElementById('push-perm-btn');
    if (enable) {
      enable.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        enablePush(enable);
      }, true);
    }

    ['fast-test-alert-btn', 'sidebar-fast-test-btn'].forEach(function (id) {
      var button = document.getElementById(id);
      if (!button) return;
      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        testPush(button);
      }, true);
    });
  });
})();
