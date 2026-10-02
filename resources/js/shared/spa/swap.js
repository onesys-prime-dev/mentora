/*
|--------------------------------------------------------------------------
| Smooth content swaps for the course SPA
|--------------------------------------------------------------------------
|
| Replaces the old "blank the page to 'Loading...', then fill it" pattern,
| which felt like a full page reload:
|
|   - the current screen stays up while the next one loads; only if the
|     load takes longer than ~150 ms does it dim and a thin progress bar
|     appear at the top, so fast loads show nothing at all
|   - the new HTML crossfades in (View Transitions API where supported,
|     a CSS fade otherwise; both skipped for reduced motion)
|   - mode "navigate" (going to another screen) scrolls back to the top;
|     mode "refresh" (re-rendering the same screen after a save) keeps
|     the scroll position and doesn't dim
|   - on failure the old screen stays and a small notice appears
|   - a newer swap supersedes an older one still loading
|
| Styles: .spa-loading / .spa-enter / .spa-progress / .spa-notice in
| resources/css/app.css.
|
*/

const DIM_DELAY_MS = 150;

let latestSwap = 0;

/**
 * @param {HTMLElement} container          element whose content is replaced
 * @param {() => Promise<string>} loadHtml resolves to the new HTML
 * @param {{ mode?: "navigate"|"refresh", skeleton?: string|null, forceSkeleton?: boolean }} options
 * @returns {Promise<boolean>} true once swapped; false if it failed or a
 *          newer swap replaced it (callers should then skip their bindings)
 */
export async function swapContent(container, loadHtml, { mode = "navigate", skeleton = null, forceSkeleton = false } = {}) {

    const swapId = ++latestSwap;

    // forceSkeleton: child pages replace the current screen with their own
    // shape straight away instead of keeping the old one up while loading.
    const hasContent =
        !(skeleton && forceSkeleton)
        && container.childElementCount > 0 && container.dataset.spaSkeleton === undefined;

    // First visit to the screen: nothing to keep, so show its shape.
    if (!hasContent && skeleton) {

        container.innerHTML = skeleton;

        container.dataset.spaSkeleton = "";

    }

    const skeletonShown = container.dataset.spaSkeleton !== undefined;

    const scrollY = window.scrollY;

    const pending = setTimeout(() => {

        if (swapId !== latestSwap) {
            return;
        }

        // A skeleton is its own loading state — no dimming, no top bar.
        if (container.dataset.spaSkeleton !== undefined) {
            return;
        }

        if (mode === "navigate" && hasContent) {
            container.classList.add("spa-loading");
        }

        progress(true);

    }, DIM_DELAY_MS);

    let html;

    try {

        html = await loadHtml();

    }

    catch (error) {

        console.error(error);

        clearTimeout(pending);

        if (swapId === latestSwap) {

            progress(false);

            container.classList.remove("spa-loading");

            if (container.dataset.spaSkeleton !== undefined) {

                delete container.dataset.spaSkeleton;

                container.innerHTML =
                    '<p class="p-10 text-sm text-gray-500">This page couldn\'t be loaded. Please try again.</p>';

            }

            notice("Couldn't load that. Please try again.");

        }

        return false;

    }

    clearTimeout(pending);

    if (swapId !== latestSwap) {
        return false;
    }

    progress(false);

    const apply = () => {

        container.innerHTML = html;

        delete container.dataset.spaSkeleton;

        container.classList.remove("spa-loading");

        if (mode === "refresh") {

            window.scrollTo({ top: scrollY, behavior: "instant" });

        }

        else {

            const top = container.getBoundingClientRect().top + window.scrollY - 24;

            if (window.scrollY > top) {
                window.scrollTo({ top: Math.max(0, top), behavior: "instant" });
            }

        }

    };

    // No crossfade out of a skeleton either: the new page just replaces it.
    const animate = mode === "navigate" && !prefersReducedMotion() && !skeletonShown;

    if (animate && typeof document.startViewTransition === "function") {

        container.classList.add("spa-transitioning");

        const transition = document.startViewTransition(apply);

        // finished rejects when a newer transition skips this one — either
        // way the name comes off.
        const settle = () => container.classList.remove("spa-transitioning");

        transition.finished.then(settle, settle);

        await transition.updateCallbackDone;

    }

    else {

        apply();

        if (animate) {

            container.classList.remove("spa-enter");

            void container.offsetWidth; // restart the animation

            container.classList.add("spa-enter");

        }

    }

    return true;

}

function prefersReducedMotion() {

    return window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;

}

/*
|--------------------------------------------------------------------------
| Top progress bar
|--------------------------------------------------------------------------
*/

function progress(active) {

    let bar = document.querySelector("[data-spa-progress]");

    if (!bar) {

        bar = document.createElement("div");

        bar.className = "spa-progress";

        bar.dataset.spaProgress = "";

        bar.setAttribute("role", "progressbar");

        bar.setAttribute("aria-label", "Loading");

        document.body.append(bar);

    }

    bar.classList.toggle("is-active", active);

}

/*
|--------------------------------------------------------------------------
| Error notice
|--------------------------------------------------------------------------
*/

let noticeTimer = null;

function notice(message) {

    let box = document.querySelector("[data-spa-notice]");

    if (!box) {

        box = document.createElement("div");

        box.className = "spa-notice";

        box.dataset.spaNotice = "";

        box.setAttribute("role", "status");

        document.body.append(box);

    }

    box.textContent = message;

    box.classList.add("is-visible");

    clearTimeout(noticeTimer);

    noticeTimer = setTimeout(() => box.classList.remove("is-visible"), 4000);

}
