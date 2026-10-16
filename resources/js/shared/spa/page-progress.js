/*
|--------------------------------------------------------------------------
| Progress bar for every server page reload
|--------------------------------------------------------------------------
|
| A "server page reload" is the browser fetching a whole new document: a link
| to another page, a form that posts and redirects, a script that assigns
| window.location, a reload. This module starts the top bar the moment one
| begins, so there is never a silent white wait, and clears it if the page
| comes back from the back/forward cache or the navigation is cancelled.
|
| What is NOT a server reload (a tab or screen inside a course, which swaps
| content in place) shows its own skeleton and uses the same bar through
| swapContent.
*/

import { finishPageLoad, resetProgress, startProgress } from "./progress.js";

/** How long to wait before concluding that a navigation never happened and clearing the bar. */
export const CANCEL_AFTER_MS = 12000;

let timer = null;
let leaving = false;

/**
 * Whether a click on this link leaves the page.
 *
 * @param {MouseEvent} event
 * @param {HTMLAnchorElement|null} link
 * @param {string} origin
 */
export function leavesThePage(event, link, origin = window.location.origin) {

    if (!link || event.defaultPrevented || event.button !== 0) {
        return false;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }

    const href = link.getAttribute("href") || "";

    if (href === "" || href.startsWith("#") || /^(javascript|mailto|tel):/i.test(href)) {
        return false;
    }

    if (link.hasAttribute("download") || (link.target && link.target !== "_self")) {
        return false;
    }

    let url;

    try {
        url = new URL(link.href, window.location.href);
    } catch {
        return false;
    }

    if (url.origin !== origin) {
        return false;
    }

    // Same page, only the fragment differs: the browser just scrolls.
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== "") {
        return false;
    }

    return true;

}

/** Starts the bar for a server page reload that a script is about to cause. */
export function startPageProgress() {

    if (leaving) {
        return;
    }

    leaving = true;

    startProgress();

    clearTimeout(timer);
    timer = setTimeout(clear, CANCEL_AFTER_MS);

}

function clear() {

    clearTimeout(timer);

    leaving = false;

    resetProgress();

}

export function initPageProgress() {

    finishPageLoad();

    document.addEventListener("click", (event) => {

        const link = event.target instanceof Element ? event.target.closest("a[href]") : null;

        // Handlers on the link ran first; anything that took the click over has set defaultPrevented.
        if (leavesThePage(event, link)) {
            startPageProgress();
        }

    });

    document.addEventListener("submit", (event) => {

        const form = event.target;

        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }

        if (form.target && form.target !== "_self") {
            return;
        }

        startPageProgress();

    });

    // Back/forward cache: the page returns as it was left, with the bar still running.
    window.addEventListener("pageshow", (event) => {

        if (event.persisted) {
            clear();
        }

    });

}

initPageProgress();
