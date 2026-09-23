self.addEventListener('install', event => { self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) {
    try { data = { title: 'ALFAMED CENTRAL', message: event.data ? event.data.text() : '' }; } catch (_) {}
  }
  const title = data.title || 'ALFAMED CENTRAL';
  const actions = Array.isArray(data.actions) ? data.actions.filter(a => a && a.action && a.title).slice(0,2) : [];
  const options = {
    body: data.message || '',
    tag: data.id ? `alfamed-${data.id}` : `alfamed-${Date.now()}`,
    renotify: true,
    requireInteraction: data.type === 'new_order',
    data: { url: data.url || './index.php', type: data.type || '', room_id: data.room_id || 0 },
    actions,
    icon: data.icon || undefined,
    badge: data.badge || undefined,
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const info = event.notification?.data || {};
  let raw = info.url || './index.php';
  // Web Notifications does not offer a standard text-input reply field. The "Raspunde" action
  // opens the exact conversation and focuses the composer, which works consistently across browsers.
  if (event.action === 'reply' && info.room_id) {
    const u = new URL(raw, self.location.origin);
    u.searchParams.set('chat_room', String(info.room_id));
    u.searchParams.set('chat_focus', '1');
    raw = u.href;
  }
  const target = new URL(raw, self.location.origin).href;
  event.waitUntil((async () => {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      try {
        if (new URL(client.url).origin === new URL(target).origin) {
          if ('navigate' in client) await client.navigate(target);
          return client.focus();
        }
      } catch (_) {}
    }
    return clients.openWindow ? clients.openWindow(target) : undefined;
  })());
});
