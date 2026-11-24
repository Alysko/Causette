self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

function isInScope(url, scope) {
    return url.origin === scope.origin && url.pathname.startsWith(scope.pathname);
}

function notificationTarget(link, scope) {
    const route = String(link || '').trim();
    if (!route.startsWith('/') || route.startsWith('//')) return null;
    const target = new URL(route.slice(1), scope);
    return isInScope(target, scope) ? target : null;
}

self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        const scope = new URL(self.registration.scope);
        const language = String(self.navigator?.language || '').split('-')[0].toLowerCase();
        const fallback = language === 'fr'
            ? { title: 'Nouveau message', body: 'Vous avez une nouvelle notification dans Causette.' }
            : { title: 'New message', body: 'You have a new notification in Causette.' };
        let payload = {};

        if (event.data) {
            try {
                payload = event.data.json() ?? {};
            } catch {
                payload = { body: event.data.text() };
            }
        }

        let title = String(payload.title || '').trim();
        let body = String(payload.body || '').trim();
        let link = String(payload.link || '').trim();
        let notificationType = String(payload.type || '').trim().toLowerCase();

        if (!title || !body) {
            try {
                const response = await fetch(new URL('notifications', scope).href, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });

                if (response.ok) {
                    const data = await response.json();
                    const notifications = Array.isArray(data?.notifications) ? data.notifications : [];
                    const firstUnread = notifications.find((item) => Number(item?.read) === 0) || notifications[0] || null;
                    if (firstUnread) {
                        title = String(firstUnread.title || title).trim();
                        body = String(firstUnread.message || body).trim();
                        link = String(firstUnread.link || link).trim();
                        notificationType = String(firstUnread.type || notificationType || '').trim().toLowerCase();
                    }
                }
            } catch {
                // Fallback title/body below.
            }
        }

        const icon = new URL('assets/img/favicon.png', scope).href;
        await self.registration.showNotification(title || fallback.title, {
            body: body || fallback.body,
            icon,
            badge: icon,
            data: {
                link,
                type: notificationType
            }
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const title = String(event.notification?.title || '').trim().toLowerCase();
    const notificationType = String(event.notification?.data?.type || '').trim().toLowerCase();
    const shouldJoinCall = notificationType === 'poke' || title === 'call poke';

    const scope = new URL(self.registration.scope);
    const noticeUrl = notificationTarget(event.notification?.data?.link, scope);
    const target = noticeUrl || scope;
    if (noticeUrl && shouldJoinCall) {
        if (target.searchParams.get('join_call') !== '1') {
            target.searchParams.set('join_call', '1');
        }
        if (!target.searchParams.get('join_source')) {
            target.searchParams.set('join_source', 'poke');
        }
    }

    event.waitUntil((async () => {
        const clientList = await self.clients.matchAll({ type: 'window' });

        for (const client of clientList) {
            if (!isInScope(new URL(client.url), scope)) continue;
            const window = client.url === target.href ? client : await client.navigate(target.href);
            if (window) {
                await window.focus();
                return;
            }
        }

        await self.clients.openWindow(target.href);
    })());
});
