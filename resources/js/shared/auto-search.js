/*
|--------------------------------------------------------------------------
| A search box that searches as you type, without reloading the page
|--------------------------------------------------------------------------
| <input data-auto-search> inside a GET form: a short pause after the last key
| the results are fetched in the background and swapped in. Only what comes
| after the search box (the count, the table, the paging) is replaced, so the
| header, the menu, the filters and the box itself - with its caret - stay put.
|
|   - a newer search cancels an older one still on its way, so a slow answer
|     never lands on top of a fresher one
|   - the old results stay visible, a little dimmed, until the new ones arrive
|   - the address follows the search (replaceState), so a reload or a shared
|     link shows the same results
|   - anything unexpected (a failed request, a login page, a page laid out
|     differently) falls back to sending the form the ordinary way
|
| Swapped-in content tells the rest of the scripts about itself with a
| "content:swapped" event on the document (detail.root is the element it was
| put into), so tables and dialog buttons in it can be wired up again.
*/

const PAUSE = 400;
const KEY = "autoSearchFocus";

// Things that are not results: dialogs and scripts stay as they are.
const KEEP = "[data-dialog], dialog, template, script, style";

let inFlight = null;

function remember(input) {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname, name: input.name }));
    } catch (error) {
        // Private windows can refuse storage; the search still works, only the focus is lost.
    }
}

const swappableAfter = (node) => {
    const found = [];

    for (let next = node.nextElementSibling; next; next = next.nextElementSibling) {
        if (!next.matches(KEEP)) {
            found.push(next);
        }
    }

    return found;
};

/** How many levels above the form the results sit as siblings (0 when beside the form). */
function levelsUp(form, stop) {
    let node = form;
    let levels = 0;

    while (node && node !== stop && swappableAfter(node).length === 0) {
        node = node.parentElement;
        levels++;
    }

    return node && node !== stop ? levels : -1;
}

const climb = (node, levels) => {
    for (let i = 0; i < levels && node; i++) {
        node = node.parentElement;
    }

    return node;
};

function addressFor(form) {
    const url = new URL(form.getAttribute("action") || location.href, location.href);
    const params = new URLSearchParams();

    for (const [name, value] of new FormData(form)) {
        if (typeof value === "string" && value !== "") {
            params.append(name, value);
        }
    }

    url.search = params.toString();

    return url;
}

async function search(input) {
    const form = input.form;
    const main = form.closest("main") ?? document.body;
    const levels = levelsUp(form, main);

    if (levels < 0) {
        return fallback(input);
    }

    const anchor = climb(form, levels);
    const olds = swappableAfter(anchor);
    const url = addressFor(form);

    inFlight?.abort();
    inFlight = new AbortController();
    const mine = inFlight;

    olds.forEach((el) => {
        el.style.transition = "opacity 150ms";
        el.style.opacity = "0.55";
    });

    try {
        const response = await fetch(url, {
            credentials: "same-origin",
            headers: { "X-Requested-With": "fetch" },
            signal: mine.signal,
        });

        // Sent somewhere else (signed out, or the page moved): let the browser deal with it.
        if (!response.ok || new URL(response.url).pathname !== url.pathname) {
            return fallback(input);
        }

        const doc = new DOMParser().parseFromString(await response.text(), "text/html");
        const boxes = [...document.querySelectorAll("input[data-auto-search]")];
        const fresh = doc.querySelectorAll("input[data-auto-search]")[boxes.indexOf(input)];
        const freshAnchor = fresh?.form ? climb(fresh.form, levels) : null;

        if (!freshAnchor) {
            return fallback(input);
        }

        const news = swappableAfter(freshAnchor);
        const before = [...anchor.parentElement.children].find(
            (el) => el !== anchor && (anchor.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING) && !olds.includes(el),
        ) ?? null;

        olds.forEach((el) => el.remove());
        news.forEach((el) => anchor.parentElement.insertBefore(document.importNode(el, true), before));

        history.replaceState(null, "", url);
        document.dispatchEvent(new CustomEvent("content:swapped", { detail: { root: anchor.parentElement } }));
    } catch (error) {
        if (error.name === "AbortError") {
            return undefined;
        }

        return fallback(input);
    } finally {
        if (inFlight === mine) {
            inFlight = null;
        }

        olds.forEach((el) => {
            el.style.opacity = "";
        });
    }

    return undefined;
}

function fallback(input) {
    remember(input);
    input.form.dataset.full = "1"; // so the submit handler below lets this one go through
    input.form.requestSubmit();
}

function schedule(input, pause) {
    clearTimeout(input.__autoSearchTimer);
    input.__autoSearchTimer = setTimeout(() => search(input), pause);
}

const isAuto = (input) => input instanceof HTMLInputElement && "autoSearch" in input.dataset && input.form;

document.addEventListener("input", (event) => {
    if (isAuto(event.target)) {
        schedule(event.target, PAUSE);
    }
});

// The x that clears a search box fires "search", not "input": clearing should show everything again.
document.addEventListener("search", (event) => {
    if (isAuto(event.target) && event.target.value === "") {
        schedule(event.target, 0);
    }
});

// Enter and the Apply button search in place too, instead of leaving the page.
document.addEventListener("submit", (event) => {
    const form = event.target;
    const input = form.querySelector?.("input[data-auto-search]");

    if (!input || form.dataset.full || event.defaultPrevented) {
        return;
    }

    event.preventDefault();
    schedule(input, 0);
});

// A page that did reload (the fallback) puts the caret back in the box that was typed in.
try {
    const saved = JSON.parse(sessionStorage.getItem(KEY) ?? "null");

    sessionStorage.removeItem(KEY);

    if (saved && saved.path === location.pathname) {
        const input = [...document.querySelectorAll("input[data-auto-search]")].find((candidate) => candidate.name === saved.name);

        if (input) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }
    }
} catch (error) {
    // Nothing remembered, nothing to restore.
}
