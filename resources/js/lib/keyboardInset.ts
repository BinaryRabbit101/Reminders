/**
 * Keeps `--keyboard-inset` on the root element in sync with how much of the
 * screen the on-screen keyboard is currently covering.
 *
 * iOS Safari — and, worse, this app installed as a standalone PWA — never
 * shrinks the *layout* viewport for the software keyboard, only the
 * *visual* one. A `position: fixed; bottom: 0` sheet keeps anchoring to the
 * bottom of the full screen and ends up rendered underneath the keyboard.
 * `window.visualViewport` is the only place that knows the keyboard is
 * there at all; this CSS variable is how that reaches the Sheet without
 * every consumer wiring up its own listener.
 */
export function initializeKeyboardInset(): void {
    const viewport =
        typeof window === 'undefined' ? null : window.visualViewport;

    if (viewport === null) {
        return;
    }

    const update = () => {
        // How far the visual viewport's bottom edge sits above the layout
        // viewport's — zero with no keyboard up, roughly the keyboard's
        // height once one is. offsetTop corrects for the page having been
        // scrolled while the keyboard is open.
        const inset = Math.max(
            0,
            window.innerHeight - viewport.height - viewport.offsetTop,
        );

        document.documentElement.style.setProperty(
            '--keyboard-inset',
            `${inset}px`,
        );
    };

    viewport.addEventListener('resize', update);
    viewport.addEventListener('scroll', update);
    update();
}
