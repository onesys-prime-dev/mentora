/*
|--------------------------------------------------------------------------
| Grading scheme editor (instructor)
|--------------------------------------------------------------------------
|
| Rendered by GradingSchemeController::show into #course-content.
|
| - Text fields (names) autosave on a short debounce, merged per entity —
|   the quiz builder's queueQuestionSave pattern — without re-rendering,
|   so typing keeps focus.
| - Numbers and selects save on change, then the editor re-renders: they
|   can flag the scheme for recalculation or change the weight total.
| - Nothing here changes a stored grade by itself; the server flags the
|   scheme and the instructor clicks Recalculate.
|
*/

import { showLoadError } from "../../../../../shared/spa/error-state.js";
import { renderSchemeSkeleton } from "../skeleton.js";
import {
    fetchGradingScheme,
    updateGradingScheme,
    createGradeCategory,
    updateGradeCategory,
    deleteGradeCategory,
    createGradeItem,
    updateGradeItem,
    deleteGradeItem,
    resyncGradeItem,
    recalculateGrades,
    createGradingPeriod,
    updateGradingPeriod,
    deleteGradingPeriod,
    restoreGradeCategory,
    restoreGradeItem,
    reorderGradeCategories,
    reorderGradeItems,
} from "../api.js";

import { bindModal } from "../../assessments/modals/index.js";

let backToGrades = null;

const pending = new Map();
const timers = new Map();

export async function loadGradingScheme(back)
{
    backToGrades = back;

    const container = document.querySelector("#course-content");

    container.innerHTML = renderSchemeSkeleton();

    try {

        const result = await fetchGradingScheme();

        container.innerHTML = result.html;

        bind(container);

    } catch (error) {

        console.error(error);

        showLoadError(container, "the grading scheme", () => loadGradingScheme(back));

    }
}

async function rerender()
{
    const result = await fetchGradingScheme();

    const container = document.querySelector("#course-content");

    container.innerHTML = result.html;

    bind(container);
}

/*
|--------------------------------------------------------------------------
| Saving
|--------------------------------------------------------------------------
*/

function setStatus(status)
{
    const dot = document.querySelector("[data-scheme-save-dot]");
    const message = document.querySelector("[data-scheme-save-message]");

    if (!dot || !message) {
        return;
    }

    const states = {
        saving: ["bg-amber-400", "Saving…"],
        saved: ["bg-emerald-500", "All changes saved"],
        error: ["bg-red-500", "Couldn't save — check your connection"],
    };

    const [color, text] = states[status];

    dot.className = `h-2 w-2 rounded-full ${color}`;
    message.textContent = text;
}

function showError(message)
{
    const box = document.querySelector("[data-scheme-error]");

    if (!box) {
        return;
    }

    box.textContent = message || "";
    box.classList.toggle("hidden", !message);
}

/**
 * Debounced, merged save per entity key (e.g. "category:3").
 */
function queueSave(key, partialPayload, save, delay = 600)
{
    pending.set(key, { ...(pending.get(key) || {}), ...partialPayload });

    clearTimeout(timers.get(key));

    setStatus("saving");

    timers.set(key, setTimeout(async () => {

        const payload = pending.get(key);

        pending.delete(key);

        await runSave(() => save(payload), false);

    }, delay));
}

/**
 * Runs one save; on a refused change shows the reason. Re-renders when
 * asked (and on success), so flags and totals stay truthful.
 */
async function runSave(save, rerenderAfter = true)
{
    setStatus("saving");

    try {

        const result = await save();

        if (result && result.success === false) {
            setStatus("saved");
            showError(result.message);
            if (rerenderAfter) {
                await rerender();
                showError(result.message);
            }
            return result;
        }

        showError("");
        setStatus("saved");

        if (rerenderAfter) {
            await rerender();
        }

        return result;

    } catch (error) {

        console.error(error);
        setStatus("error");
        return null;

    }
}

/*
|--------------------------------------------------------------------------
| Bindings
|--------------------------------------------------------------------------
*/

function bind(container)
{
    const editor = container.querySelector("[data-grading-scheme-editor]");

    if (!editor) {
        return;
    }

    editor.querySelector("[data-grading-scheme-back]")
        ?.addEventListener("click", () => backToGrades?.());

    /*
    | Scheme settings
    */

    editor.querySelector("[data-scheme-name]")
        ?.addEventListener("input", (event) => {
            queueSave("scheme", { name: event.target.value }, updateGradingScheme);
        });

    editor.querySelector("[data-scheme-passing]")
        ?.addEventListener("change", (event) => {
            runSave(() => updateGradingScheme({ passing_percentage: Number(event.target.value) }));
        });

    editor.querySelector("[data-scheme-scale]")
        ?.addEventListener("change", (event) => {
            runSave(() => updateGradingScheme({ grade_scale_id: event.target.value || null }));
        });

    editor.querySelector("[data-scheme-toggle-publish]")
        ?.addEventListener("click", (event) => {
            runSave(() => updateGradingScheme({ status: event.currentTarget.dataset.targetStatus }));
        });

    editor.querySelectorAll("[data-recalculate-grades]").forEach((button) => {
        button.addEventListener("click", () => {
            button.disabled = true;
            runSave(recalculateGrades);
        });
    });

    editor.querySelector("[data-scheme-uses-periods]")
        ?.addEventListener("change", (event) => {
            runSave(() => updateGradingScheme({ uses_periods: event.target.checked }));
        });

    /*
    | Grading periods
    */

    editor.querySelector("[data-add-grading-period]")
        ?.addEventListener("click", () => {
            runSave(() => createGradingPeriod({ name: "New Period", weight_percentage: 0 }));
        });

    editor.querySelectorAll("[data-grading-period]").forEach((period) => {

        const periodId = period.dataset.gradingPeriod;

        period.querySelector("[data-grading-period-name]")
            ?.addEventListener("input", (event) => {
                queueSave(`period:${periodId}`, { name: event.target.value }, (payload) => updateGradingPeriod(periodId, payload));
            });

        const weight = period.querySelector("[data-grading-period-weight]");

        weight?.addEventListener("input", updateWeightTotal);

        weight?.addEventListener("change", (event) => {
            runSave(() => updateGradingPeriod(periodId, { weight_percentage: Number(event.target.value) }));
        });

        period.querySelector("[data-delete-grading-period]")
            ?.addEventListener("click", () => {
                if (confirm("Remove this period? Its categories become Unassigned and stop counting until you move them.")) {
                    runSave(() => deleteGradingPeriod(periodId));
                }
            });

    });

    /*
    | Categories
    */

    editor.querySelectorAll("[data-add-grade-category]").forEach((button) => {
        button.addEventListener("click", () => {

            const periodId = button.dataset.periodId || null;

            runSave(() => createGradeCategory({
                name: uniqueCategoryName(editor),
                weight_percentage: 0,
                ...(periodId ? { period_id: periodId } : {}),
            }));

        });
    });

    editor.querySelectorAll("[data-grade-category]").forEach((category) => {

        const categoryId = category.dataset.gradeCategory;

        category.querySelector("[data-grade-category-name]")
            ?.addEventListener("input", (event) => {
                queueSave(`category:${categoryId}`, { name: event.target.value }, (payload) => updateGradeCategory(categoryId, payload));
            });

        const weight = category.querySelector("[data-grade-category-weight]");

        weight?.addEventListener("input", () => {

            updateWeightTotal();

            const periodCard = category.closest("[data-grading-period]");

            if (periodCard) {
                updatePeriodCategoryWeightTotal(periodCard);
            }

        });

        weight?.addEventListener("change", (event) => {
            runSave(() => updateGradeCategory(categoryId, { weight_percentage: Number(event.target.value) }));
        });

        category.querySelector("[data-grade-category-period]")
            ?.addEventListener("change", (event) => {
                runSave(() => updateGradeCategory(categoryId, { period_id: event.target.value || null }));
            });

        category.querySelector("[data-delete-grade-category]")
            ?.addEventListener("click", () => {
                if (confirm("Remove this category? Its items become Uncategorized and stop counting until you move them.")) {
                    runSave(() => deleteGradeCategory(categoryId));
                }
            });

        category.querySelector("[data-add-grade-item]")
            ?.addEventListener("click", () => openItemModal(categoryId));

    });

    /*
    | Items
    */

    editor.querySelectorAll("[data-grade-item]").forEach((item) => {

        const itemId = item.dataset.gradeItem;

        item.querySelector("[data-grade-item-name]")
            ?.addEventListener("input", (event) => {
                queueSave(`item:${itemId}`, { name: event.target.value }, (payload) => updateGradeItem(itemId, payload));
            });

        item.querySelector("[data-grade-item-max]")
            ?.addEventListener("change", async (event) => {

                const max_points = Number(event.target.value);

                const result = await runSave(() => updateGradeItem(itemId, { max_points }), false);

                if (result && result.needs_rescale) {

                    const message = `${result.message}\n\nRescale every score to the new maximum? (8 of 10 becomes 4 of 5.)`;

                    if (confirm(message)) {
                        await runSave(() => updateGradeItem(itemId, { max_points, rescale_scores: true }));
                        return;
                    }

                }

                await rerender();

                if (result && result.success === false) {
                    showError(result.message);
                }

            });

        item.querySelector("[data-grade-item-category]")
            ?.addEventListener("change", (event) => {
                runSave(() => updateGradeItem(itemId, { category_id: event.target.value || null }));
            });

        item.querySelector("[data-resync-grade-item]")
            ?.addEventListener("click", () => runSave(() => resyncGradeItem(itemId)));

        item.querySelector("[data-remove-grade-item]")
            ?.addEventListener("click", () => {
                if (confirm("Stop counting this item in the grade? Its scores are kept.")) {
                    runSave(() => deleteGradeItem(itemId));
                }
            });

    });

    /*
    | Restore what was removed
    */

    editor.querySelectorAll("[data-restore-grade-category]").forEach((button) => {
        button.addEventListener("click", () => runSave(() => restoreGradeCategory(button.dataset.restoreGradeCategory)));
    });

    editor.querySelectorAll("[data-restore-grade-item]").forEach((button) => {
        button.addEventListener("click", () => runSave(() => restoreGradeItem(button.dataset.restoreGradeItem)));
    });

    /*
    | Order: move a category among its siblings, or an item within its category
    */

    editor.querySelectorAll("[data-move-grade-category]").forEach((button) => {
        button.addEventListener("click", () => {
            const ids = movedIds(editor, "[data-grade-category]", "gradeCategory", button.closest("[data-grade-category]"), button.dataset.moveGradeCategory);
            runSave(() => reorderGradeCategories(ids));
        });
    });

    editor.querySelectorAll("[data-move-grade-item]").forEach((button) => {
        button.addEventListener("click", () => {
            const row = button.closest("[data-grade-item]");
            const categoryId = row.closest("[data-grade-category]")?.dataset.gradeCategory;
            const ids = movedIds(row.parentElement, "[data-grade-item]", "gradeItem", row, button.dataset.moveGradeItem);
            runSave(() => reorderGradeItems(categoryId, ids));
        });
    });

    bindItemModal();
}

/** "New category", or "New category 2", 3 … : names must be unique in a scheme. */
export function uniqueCategoryName(editor)
{
    const taken = new Set(
        Array.from(editor.querySelectorAll("[data-grade-category-name]")).map((input) => input.value.trim().toLowerCase())
    );

    let name = "New category";

    for (let n = 2; taken.has(name.toLowerCase()); n++) {
        name = `New category ${n}`;
    }

    return name;
}

/**
 * The ids of the siblings matching `selector` under `scope`, with `element`
 * moved one place up or down.
 */
export function movedIds(scope, selector, dataKey, element, direction)
{
    const ids = Array.from(scope.querySelectorAll(selector))
        .filter((candidate) => candidate.parentElement === element.parentElement)
        .map((candidate) => candidate.dataset[dataKey]);

    const index = ids.indexOf(element.dataset[dataKey]);
    const target = direction === "up" ? index - 1 : index + 1;

    if (index < 0 || target < 0 || target >= ids.length) {
        return ids;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];

    return ids;
}

/**
 * Live top-level weight total while typing (saved on change) — periods'
 * own weights when the scheme uses them, else the flat categories'.
 */
function updateWeightTotal()
{
    const usesPeriods = document.querySelector("[data-grading-scheme-editor]")?.dataset.usesPeriods === "1";

    const selector = usesPeriods ? "[data-grading-period-weight]" : "[data-grade-category-weight]";

    const total = Array.from(document.querySelectorAll(selector))
        .reduce((sum, input) => sum + Number(input.value || 0), 0);

    setWeightChip(document.querySelector("[data-scheme-weight-total]"), "Total", total);
}

/**
 * Live weight total for one period's own categories.
 */
function updatePeriodCategoryWeightTotal(periodCard)
{
    const total = Array.from(periodCard.querySelectorAll("[data-grade-category-weight]"))
        .reduce((sum, input) => sum + Number(input.value || 0), 0);

    setWeightChip(periodCard.querySelector("[data-grading-period-weight-total]"), "Categories", total);
}

function setWeightChip(chip, label, total)
{
    if (!chip) {
        return;
    }

    const ok = Math.abs(total - 100) < 0.001;

    chip.textContent = `${label}: ${Math.round(total * 100) / 100}%${ok ? "" : " — must be 100%"}`;
    chip.classList.toggle("bg-[#E8F5EC]", ok);
    chip.classList.toggle("text-[#2E7D4F]", ok);
    chip.classList.toggle("bg-[#FDEDEC]", !ok);
    chip.classList.toggle("text-[#C33939]", !ok);
}

/*
|--------------------------------------------------------------------------
| Manual item modal
|--------------------------------------------------------------------------
*/

let itemModal = null;

function bindItemModal()
{
    itemModal = bindModal({
        modal: "#grade-item-create-modal",
        closeButtons: "[data-close-grade-item-modal]",
    });

    const form = document.querySelector("#grade-item-create-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const categoryId = form.querySelector("[data-grade-item-create-category]").value;
        const error = form.querySelector("[data-grade-item-create-error]");

        const result = await createGradeItem(categoryId, {
            name: form.querySelector("[data-grade-item-create-name]").value,
            max_points: Number(form.querySelector("[data-grade-item-create-max]").value),
        }).catch(() => null);

        if (!result || result.success === false) {
            error.textContent = result?.message || "Couldn't add the item.";
            error.classList.remove("hidden");
            return;
        }

        itemModal?.close();

        await rerender();

    });
}

function openItemModal(categoryId)
{
    const form = document.querySelector("#grade-item-create-form");

    if (!form || !itemModal) {
        return;
    }

    form.reset();
    form.querySelector("[data-grade-item-create-category]").value = categoryId;
    form.querySelector("[data-grade-item-create-error]").classList.add("hidden");

    itemModal.open();

    form.querySelector("[data-grade-item-create-name]").focus();
}
