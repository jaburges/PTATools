(function () {
  if (!window.ptaHomeScreen) return;

  var dismissedKey = 'pta-pin-dismissed';
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var narrow = window.matchMedia('(max-width: 782px)').matches;
  var ios = /iPad|iPhone|iPod/.test(navigator.userAgent);
  var sheet = document.getElementById('pta-pin-sheet');
  var allow = document.getElementById('pta-push-allow');

  function show(el) {
    if (el) el.hidden = false;
  }

  if (sheet && ptaHomeScreen.showPin && !standalone && narrow && !localStorage.getItem(dismissedKey)) {
    sheet.classList.add(ios ? 'is-ios' : 'is-android');
    show(sheet);
    var close = sheet.querySelector('.pta-pin-sheet__close');
    if (close) {
      close.addEventListener('click', function () {
        localStorage.setItem(dismissedKey, '1');
        sheet.hidden = true;
      });
    }
  }

  if ('serviceWorker' in navigator && ptaHomeScreen.sw) {
    navigator.serviceWorker.register(ptaHomeScreen.sw, { scope: '/' }).catch(function () {});
  }

  function postSubscription(sub) {
    var json = sub.toJSON();
    if (!json || !json.endpoint || !json.keys) return;
    var body = new URLSearchParams();
    body.set('action', 'pta_home_screen_subscribe');
    body.set('nonce', ptaHomeScreen.nonce);
    body.set('endpoint', json.endpoint);
    body.set('p256dh', json.keys.p256dh || '');
    body.set('auth', json.keys.auth || '');
    fetch(ptaHomeScreen.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
  }

  function subscribe() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !ptaHomeScreen.vapid) return;
    navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(ptaHomeScreen.vapid)
      });
    }).then(postSubscription).catch(function () {});
  }

  if (standalone && 'Notification' in window) {
    if (Notification.permission === 'granted') {
      subscribe();
    } else if (Notification.permission === 'default' && allow) {
      show(allow);
      var button = allow.querySelector('.pta-pin-sheet__allow');
      if (button) {
        button.addEventListener('click', function () {
          Notification.requestPermission().then(function (result) {
            if (result === 'granted') {
              allow.hidden = true;
              subscribe();
            }
          });
        });
      }
    }
  } else if ('Notification' in window && Notification.permission === 'granted') {
    subscribe();
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(base64);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
})();
