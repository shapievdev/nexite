/* =============================================================================
 * Service worker мессенджера
 *  - офлайн-оболочка (стили, скрипт, иконки, страница-заглушка);
 *  - push-уведомления о новых сообщениях;
 *  - переход в чат по клику на уведомление.
 *
 * Приватные данные (переписка, вложения, /api/*) НЕ кэшируются никогда.
 * ========================================================================== */

const VERSION = 'chat-v2';

// Стили и скрипт сюда не входят: их адреса версионируются (?v=mtime),
// поэтому они попадают в кэш при первой же загрузке страницы — уже нужной версии.
const SHELL = [
    '/offline',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/badge-96.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

const isShellAsset = (url) =>
    url.pathname.startsWith('/css/')
    || url.pathname.startsWith('/js/')
    || url.pathname.startsWith('/icons/');

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Переписка, вложения и аватары мимо кэша — они приватные.
    if (url.pathname.startsWith('/api/')
        || url.pathname.startsWith('/attachments/')
        || url.pathname.startsWith('/avatars/')) {
        return;
    }

    // Страницы: сначала сеть, при обрыве — заглушка «нет сети».
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline'))
        );
        return;
    }

    // Статика: отдаём из кэша сразу и обновляем в фоне.
    if (isShellAsset(url)) {
        event.respondWith(
            caches.open(VERSION).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok) cache.put(request, response.clone());
                        return response;
                    })
                    .catch(() => cached);

                return cached || network;
            })
        );
    }
});

/* ---------------------------------------------------------------------------
 * Push
 * ------------------------------------------------------------------------ */

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Новое сообщение', {
            body: data.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-96.png',
            tag: data.tag || 'chat-message',
            renotify: true,
            vibrate: [60, 40, 60],
            data: { url: data.url || '/' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(event.notification.data?.url || '/', self.location.origin);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if (new URL(client.url).origin === target.origin && 'focus' in client) {
                    client.navigate?.(target.href);
                    return client.focus();
                }
            }

            return self.clients.openWindow(target.href);
        })
    );
});
