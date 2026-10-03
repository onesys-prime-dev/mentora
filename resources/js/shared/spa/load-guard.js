/*
|--------------------------------------------------------------------------
| Delayed skeleton + stale-response guard for a swappable region
|--------------------------------------------------------------------------
|
| beginLoad(el, skeleton)
|   - starts a load for `el`; the skeleton only appears if it is still
|     loading after `delay` ms, so a fast load shows no flash at all
|     (delay 0 = show it straight away, for scoped list refreshes)
|   - supersedes any earlier load on the same element
|
| The returned handle:
|   current() - false once a newer load (or supersede(el)) replaced this one;
|               a response that finds this false must be dropped, so an old
|               request can never paint over a newer tab, filter or search
|   finish()  - cancel the pending skeleton; call once the response arrived
|
| 150 ms matches shared/spa/swap.js, which already uses it for the same
| reason. It is a starting value, not a measured one.
|
*/

export const SKELETON_DELAY_MS = 150;

const tokens = new WeakMap();
const timers = new WeakMap();

/** Invalidate whatever load is in flight for `el` (e.g. the user changed tabs). */
export function supersede(el) {

    tokens.set(el, (tokens.get(el) ?? 0) + 1);

    clearTimeout(timers.get(el));

}

export function beginLoad(el, skeletonHtml, delay = SKELETON_DELAY_MS) {

    supersede(el);

    const id = tokens.get(el);

    const current = () => tokens.get(el) === id;

    if (delay <= 0) {
        el.innerHTML = skeletonHtml;
    } else {
        timers.set(el, setTimeout(() => {
            if (current()) {
                el.innerHTML = skeletonHtml;
            }
        }, delay));
    }

    return {
        current,
        finish() {
            if (current()) {
                clearTimeout(timers.get(el));
            }
        },
    };

}
