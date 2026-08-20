(function () {
  var deferredPrompt = null;
  var installButtons = Array.prototype.slice.call(document.querySelectorAll('[data-install-app]'));
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  function setInstallVisible(visible) {
    installButtons.forEach(function (button) {
      button.hidden = !visible;
    });
  }

  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('/public/admin/sw.js', {
      scope: '/public/admin/',
      updateViaCache: 'none'
    }).then(function (registration) {
      registration.update();
    }).catch(function (error) {
      console.warn('Hercule install service worker registration failed', error);
    });
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    if (!standalone) setInstallVisible(true);
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    setInstallVisible(false);
  });

  installButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      if (!deferredPrompt) return;
      button.disabled = true;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        button.disabled = false;
        setInstallVisible(false);
      });
    });
  });

  setInstallVisible(false);
})();
