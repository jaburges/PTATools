self.addEventListener('push', function (event) {
  var payload = { title: 'Wilder PTSA', body: '', url: '/', icon: '' };
  if (event.data) {
    try {
      var parsed = event.data.json();
      if (parsed && typeof parsed === 'object') {
        payload.title = parsed.title || payload.title;
        payload.body = parsed.body || '';
        payload.url = parsed.url || payload.url;
        payload.icon = parsed.icon || '';
      }
    } catch (e) {}
  }
  event.waitUntil(self.registration.showNotification(payload.title, {
    body: payload.body,
    icon: payload.icon,
    data: { url: payload.url }
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url === url && 'focus' in list[i]) {
        return list[i].focus();
      }
    }
    if (clients.openWindow) {
      return clients.openWindow(url);
    }
  }));
});
