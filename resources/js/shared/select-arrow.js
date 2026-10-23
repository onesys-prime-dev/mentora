/*
|--------------------------------------------------------------------------
| A select whose arrow shows whether its list is open
|--------------------------------------------------------------------------
| <select data-select-arrow>: the browser does not say when its list is open,
| so a click toggles data-open (a second click closes the list, and the arrow
| goes back), and choosing, leaving or pressing Escape clears it. The page's
| CSS turns the arrow from that attribute.
*/

const close = (select) => {
    select.dataset.open = "false";
};

document.addEventListener("mousedown", (event) => {
    const select = event.target;

    if (!(select instanceof HTMLSelectElement) || !("selectArrow" in select.dataset)) {
        return;
    }

    select.dataset.open = select.dataset.open === "true" ? "false" : "true";
});

document.addEventListener("change", (event) => {
    if (event.target instanceof HTMLSelectElement && "selectArrow" in event.target.dataset) {
        close(event.target);
    }
});

document.addEventListener("focusout", (event) => {
    if (event.target instanceof HTMLSelectElement && "selectArrow" in event.target.dataset) {
        close(event.target);
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && event.target instanceof HTMLSelectElement && "selectArrow" in event.target.dataset) {
        close(event.target);
    }
});

/*
|--------------------------------------------------------------------------
| The same arrow on every select of the courses pages
|--------------------------------------------------------------------------
| A select without its own arrow (no data-select-arrow) on the courses pages
| gets a grey one drawn over its right edge, turning the same way. The select
| keeps its place in the page: the arrow is a sibling placed over it, and
| follows it when it is resized or shown (in a dialog, say).
*/

const SCOPE = ["courses", "monitor-program"];
const NS = "http://www.w3.org/2000/svg";

const place = (select, arrow) => {
    const parent = select.offsetParent;

    if (!parent || select.offsetWidth === 0) {
        arrow.style.display = "none";
        return;
    }

    arrow.style.display = "";
    arrow.style.left = `${select.offsetLeft + select.offsetWidth - 26}px`;
    arrow.style.top = `${select.offsetTop + select.offsetHeight / 2 - 8}px`;
};

const enhance = (select) => {
    if (select.dataset.arrowDrawn || "selectArrow" in select.dataset || select.multiple || select.size > 1 || !select.parentElement) {
        return;
    }

    select.dataset.arrowDrawn = "true";
    select.dataset.selectArrow = "";
    select.style.appearance = "none";
    select.style.paddingRight = "2rem";

    if (getComputedStyle(select.parentElement).position === "static") {
        select.parentElement.style.position = "relative";
    }

    const arrow = document.createElementNS(NS, "svg");
    arrow.setAttribute("viewBox", "0 0 24 24");
    arrow.setAttribute("aria-hidden", "true");
    arrow.setAttribute("fill", "none");
    arrow.setAttribute("stroke", "currentColor");
    arrow.setAttribute("stroke-width", "2.5");
    arrow.setAttribute("stroke-linecap", "round");
    arrow.setAttribute("stroke-linejoin", "round");
    arrow.style.cssText = "position:absolute;width:16px;height:16px;pointer-events:none;color:#6b7280;transition:transform .2s";
    arrow.innerHTML = '<path d="M6 9l6 6 6-6" />';
    select.after(arrow);

    const turn = () => {
        arrow.style.transform = select.dataset.open === "true" ? "rotate(180deg)" : "";
    };

    new MutationObserver(turn).observe(select, { attributes: true, attributeFilter: ["data-open"] });
    if (typeof ResizeObserver !== "undefined") {
        new ResizeObserver(() => place(select, arrow)).observe(select);
    }

    window.addEventListener("resize", () => place(select, arrow));
    place(select, arrow);
};

const enhanceAll = (root) => root.querySelectorAll("select").forEach(enhance);

if (SCOPE.includes(document.body?.dataset.page ?? "")) {
    enhanceAll(document);

    // Selects the page adds later (a dialog's, a row's).
    new MutationObserver((records) => {
        for (const record of records) {
            record.addedNodes.forEach((node) => {
                if (node instanceof HTMLSelectElement) {
                    enhance(node);
                } else if (node instanceof Element) {
                    enhanceAll(node);
                }
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
}
