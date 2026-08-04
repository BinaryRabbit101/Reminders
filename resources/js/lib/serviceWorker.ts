/**
 * Registers the PWA service worker (public/sw.js).
 *
 * The worker is network-only — it exists for installability and web push, not
 * caching. Registration is best-effort by design: an insecure context (plain
 * HTTP over the LAN), a browser without service workers, or a failed fetch must
 * never surface as an error in the page.
 *
 * The push-subscription flow (push-notifications spec) can await
 * `navigator.serviceWorker.ready` rather than registering a second time.
 */
export function registerServiceWorker(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Intentionally silent — the app works fine without it.
        });
    });
}
