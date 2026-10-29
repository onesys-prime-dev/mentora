import { startPageProgress } from "./spa/page-progress.js";

/*
|--------------------------------------------------------------------------
| A select that goes to a page
|--------------------------------------------------------------------------
| <select data-select-link> whose options are addresses on this site: picking
| one opens it (a parent choosing which child to look at). An empty option,
| or an address on another site, does nothing.
*/

const go = (url) => {
    startPageProgress();

    (window.__selectLinkNavigate ?? ((target) => window.location.assign(target)))(url);
};

document.addEventListener("change", (event) => {
    const select = event.target;

    if (!(select instanceof HTMLSelectElement) || !("selectLink" in select.dataset) || !select.value) {
        return;
    }

    let target;

    try {
        target = new URL(select.value, window.location.origin);
    } catch {
        return;
    }

    if (target.origin === window.location.origin) {
        go(select.value);
    }
});
