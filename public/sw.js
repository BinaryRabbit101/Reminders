// Service worker: installability + web push + notification deep-linking.
//
// Deliberately caches nothing. A reminder that shows a stale due time is worse
// than one that fails to load, and offline support is out of scope — so every
// request goes straight to the network and any cache a previous version of this
// worker created is purged on activate. (PasswordVault's stance, same reasoning.)

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(names.map((name) => caches.delete(name)));
            await self.clients.claim();
        })(),
    );
});

// Network-only. The handler exists so the app is installable, not to serve cache.
self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { title: 'Reminders', body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'Reminders', {
            body: payload.body ?? '',
            icon: payload.icon ?? '/icons/icon-192.png',
            badge: payload.badge ?? '/icons/badge-72.png',
            tag: payload.tag,
            // Buttons on the notification itself ("Complete", "Snooze 1h").
            // Their endpoints ride along in data as `{action}_url`.
            actions: payload.actions ?? [],
            data: payload.data ?? { url: payload.url ?? '/today' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data = event.notification.data ?? {};
    const url = data.url ?? '/today';

    // An action button acts and stops there: the whole point of "Complete"
    // on the lock screen is not having to open the app. The URL was signed
    // when the push was built (this worker has no CSRF token or session to
    // offer), so a bare POST is all that is needed — and a failed one is
    // swallowed, because there is nowhere to report it to.
    if (event.action) {
        const actionUrl = data[`${event.action}_url`];

        if (actionUrl) {
            event.waitUntil(fetch(actionUrl, { method: 'POST' }).catch(() => {}));
            return;
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
