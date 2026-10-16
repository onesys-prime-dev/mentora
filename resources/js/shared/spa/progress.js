/*
|--------------------------------------------------------------------------
| The slim progress bar at the top of every page
|--------------------------------------------------------------------------
|
| One bar, shared by everything that makes the person wait: a swap of
| content inside a course, and a whole new page (a link, a form, a redirect).
| Each caller starts and ends its own wait; the bar stays up until every
| wait has ended, so one finishing never hides another.
|
| The bar is also written into the server's HTML (marked data-page-load and
| already running), so it is on screen from the first paint of a new page and
| is only finished here, once the page's JavaScript has started.
*/

let waits = 0;

function find() {
    return document.querySelector("[data-spa-progress]");
}

function element() {

    let bar = find();

    if (!bar) {

        bar = document.createElement("div");
        bar.className = "spa-progress";
        bar.dataset.spaProgress = "";
        bar.setAttribute("role", "progressbar");
        bar.setAttribute("aria-label", "Loading");
        document.body.append(bar);

    }

    return bar;

}

function show() {

    const bar = element();

    bar.classList.remove("is-done");
    bar.classList.add("is-active");

}

function hide() {

    find()?.classList.remove("is-active", "is-done");

}

/** A wait has begun. */
export function startProgress() {

    waits += 1;

    show();

}

/** A wait has ended. The bar goes away when no wait is left. */
export function endProgress() {

    waits = Math.max(0, waits - 1);

    if (waits === 0) {
        hide();
    }

}

/** Forget every wait (a page restored from the back/forward cache). */
export function resetProgress() {

    waits = 0;

    hide();

}

/** How many waits are open; for tests. */
export function openWaits() {
    return waits;
}

/**
 * The bar that came with the server's HTML has done its job once the page is
 * running: run it to the end and let it fade.
 */
export function finishPageLoad() {

    const bar = find();

    if (!bar || bar.dataset.pageLoad === undefined) {
        return;
    }

    delete bar.dataset.pageLoad;

    if (waits > 0) {
        return;
    }

    bar.classList.add("is-done");

    setTimeout(() => {

        if (waits === 0) {
            hide();
        }

    }, 450);

}
