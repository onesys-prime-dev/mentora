/**
 * A header checkbox that ticks every other box of its name in the same form: <input type="checkbox" data-check-all="name[]">.
 * Disabled boxes are left alone; the header box shows "some" when only part are ticked.
 */
const boxes = (all) => [...(all.form ?? document).querySelectorAll(`input[type="checkbox"][name="${CSS.escape(all.dataset.checkAll)}"]:not(:disabled)`)];

const sync = (all) => {
    const list = boxes(all);
    const on = list.filter((b) => b.checked).length;
    all.checked = list.length > 0 && on === list.length;
    all.indeterminate = on > 0 && on < list.length;
};

document.addEventListener("change", (event) => {
    const t = event.target;
    if (t.matches?.("[data-check-all]")) {
        boxes(t).forEach((b) => (b.checked = t.checked));
        t.indeterminate = false;
    } else {
        (t.form ?? document).querySelectorAll("[data-check-all]").forEach(sync);
    }
});

document.querySelectorAll("[data-check-all]").forEach(sync);
