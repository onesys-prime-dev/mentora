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
