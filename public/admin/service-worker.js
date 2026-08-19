self.addEventListener('push', function(event) {
    if (!event.data) {
        return;
    }

    try {
        const data = event.data.json();
        
        const title = data.title || 'Hercule POS Alert';
        const options = {
            body: data.message || '',
            icon: data.icon || '/public/admin/assets/icons/app-icon-192.png',
            badge: '/public/admin/assets/icons/app-icon-192.png',
            tag: data.tag || 'hercule-alert',
            data: {
                url: data.actionUrl || '/public/admin/index.php'
            },
            vibrate: [200, 100, 200, 100, 200, 100, 400]
        };

        event.waitUntil(
            self.registration.showNotification(title, options)
        );
    } catch (e) {
        console.error('Push event payload could not be parsed', e);
    }
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    const urlToOpen = event.notification.data.url;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(windowClients) {
            // Check if there is already a window/tab open with the target URL
            for (let i = 0; i < windowClients.length; i++) {
                const client = windowClients[i];
                if (client.url === urlToOpen && 'focus' in client) {
                    return client.focus();
                }
            }
            // If not, open a new window
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});
