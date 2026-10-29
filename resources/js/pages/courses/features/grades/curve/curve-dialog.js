/*
|--------------------------------------------------------------------------
| Grade on a curve (instructor)
|--------------------------------------------------------------------------
|
| The Grades toolbar's "Curve" button opens it. Choose what to curve and how,
| Preview shows who goes up, who passes and the new class average without
| saving anything, Apply keeps the curve, and a curve in force can be removed.
| Changing anything in the form makes the last preview stale, so a curve is
| only ever applied exactly as it was previewed.
|
*/

import { fetchCurves, previewCurve, applyCurve, undoCurve } from "../api.js";
import { bindModal } from "../../assessments/modals/index.js";

const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

const fmt = (value) => (value === null || value === undefined ? "—" : String(Math.round(value * 100) / 100));

/** The request a form describes: scope, item, method and the numbers typed. */
export function curveRequest(target, method, fields, note = "")
{
    const input = {};

    Object.entries(fields).forEach(([name, value]) => {
        if (value !== "" && value !== null && value !== undefined) {
            input[name] = Number(value);
        }
    });

    return {
        scope: target === "final" ? "final" : "item",
        item_id: target === "final" ? null : Number(target),
        method,
        input,
        note,
    };
}

/** Which of the form's inputs a method needs. */
export const methodInputs = (method) => ({
    add_points: ["points"],
    scale_to_max: [],
    linear: ["new_low", "new_high"],
    set_mean: ["target_mean"],
}[method] ?? []);

/**
 * @param {() => Promise<void>} reload loads the Grades page again
 */
export function bindCurveDialog(reload)
{
    const modal = document.querySelector("#grade-curve-modal");
    const open = document.querySelector("[data-open-curve]");

    if (!modal || !open) {
        return;
    }

    const dialog = bindModal({
        modal: "#grade-curve-modal",
        closeButtons: "[data-close-grade-curve-modal]",
    });

    const form = modal.querySelector("#grade-curve-form");
    const target = modal.querySelector("[data-curve-target]");
    const method = modal.querySelector("[data-curve-method]");
    const note = modal.querySelector("[data-curve-note]");
    const result = modal.querySelector("[data-curve-result]");
    const error = modal.querySelector("[data-curve-error]");
    const apply = modal.querySelector("[data-curve-apply]");
    const live = modal.querySelector("[data-curve-live]");
    const liveList = modal.querySelector("[data-curve-live-list]");

    const fail = (message) => {
        error.textContent = message;
        error.classList.remove("hidden");
    };

    const fields = () => Object.fromEntries(
        Array.from(modal.querySelectorAll("[data-curve-field]")).map((input) => [input.dataset.curveField, input.value])
    );

    const request = () => curveRequest(target.value, method.value, pick(fields(), methodInputs(method.value)), note.value);

    const stale = () => {
        result.classList.add("hidden");
        result.innerHTML = "";
        apply.classList.add("hidden");
        error.classList.add("hidden");
    };

    const showInputs = () => {

        const needed = methodInputs(method.value);

        modal.querySelectorAll("[data-curve-input]").forEach((element) => {
            const shown = element.dataset.curveInput === method.value;
            element.classList.toggle("hidden", !shown);
            element.classList.toggle("flex", shown && element.tagName === "LABEL");
        });

        modal.querySelectorAll("[data-curve-field]").forEach((input) => {
            input.disabled = !needed.includes(input.dataset.curveField);
        });

    };

    const render = (data) => {

        target.innerHTML = [
            `<option value="final">The final grade (percentage)</option>`,
            ...data.items.map((item) => `<option value="${item.id}">${escapeHtml(item.name)} (out of ${fmt(item.max)})</option>`),
        ].join("");

        live.classList.toggle("hidden", data.live.length === 0);
        live.classList.toggle("flex", data.live.length > 0);

        liveList.innerHTML = data.live.map((curve) => `
            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <span class="min-w-0">
                    <span class="font-semibold text-[#646464]">${escapeHtml(curve.target)}</span>:
                    ${escapeHtml(curve.label)}
                    ${curve.note ? `<span class="block text-xs text-gray-500">${escapeHtml(curve.note)}</span>` : ""}
                </span>
                <button type="button" class="shrink-0 font-semibold text-[#C33939] hover:underline" data-curve-undo="${curve.id}">Remove</button>
            </li>`).join("");

        liveList.querySelectorAll("[data-curve-undo]").forEach((button) => {
            button.addEventListener("click", async () => {

                button.disabled = true;

                try {

                    const outcome = await undoCurve(button.dataset.curveUndo);

                    if (!outcome.success) {
                        fail(outcome.message);
                        button.disabled = false;
                        return;
                    }

                    dialog.close();

                    await reload();

                } catch (exception) {

                    console.error(exception);
                    fail("That didn't go through. Check your connection and try again.");
                    button.disabled = false;

                }

            });
        });

    };

    const preview = (data) => {

        const s = data.summary;

        const rows = data.rows.slice(0, 12).map((row) => `
            <tr class="border-t border-gray-100">
                <td class="py-1.5 pr-3">${escapeHtml(row.name)}</td>
                ${data.scope === "item" ? `<td class="py-1.5 pr-3 text-right">${fmt(row.item_before)} → <strong>${fmt(row.item_after)}</strong></td>` : ""}
                <td class="py-1.5 text-right">${fmt(row.final_before)}% → <strong>${fmt(row.final_after)}%</strong></td>
            </tr>`).join("");

        result.innerHTML = `
            <p class="text-sm font-semibold text-gray-700">${escapeHtml(data.label)}</p>
            <ul class="grid gap-2 text-sm sm:grid-cols-2">
                <li class="rounded-xl bg-brand-tint px-4 py-3"><strong>${s.raised}</strong> of ${s.students} go up${s.at_full_marks ? `, ${s.at_full_marks} reach full marks` : ""}</li>
                <li class="rounded-xl bg-brand-tint px-4 py-3">Class average <strong>${fmt(s.mean_before)}%</strong> → <strong>${fmt(s.mean_after)}%</strong></li>
                <li class="rounded-xl bg-brand-tint px-4 py-3">Passing (${fmt(s.passing_mark)}%): <strong>${s.passing_before}</strong> → <strong>${s.passing_after}</strong></li>
                <li class="rounded-xl bg-brand-tint px-4 py-3">${s.unchanged} unchanged${s.lowered ? `, ${s.lowered} go down` : ""}</li>
            </ul>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500"><tr><th class="pb-1">Student</th>${data.scope === "item" ? `<th class="pb-1 text-right">Score</th>` : ""}<th class="pb-1 text-right">Final grade</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            ${data.rows.length > 12 ? `<p class="text-xs text-gray-500">Showing 12 of ${data.rows.length} students.</p>` : ""}`;

        result.classList.remove("hidden");
        result.classList.add("flex");
        apply.classList.remove("hidden");
        apply.disabled = false;

    };

    open.addEventListener("click", async () => {

        stale();
        form.reset();
        showInputs();
        dialog.open();

        try {
            render(await fetchCurves());
        } catch (exception) {
            console.error(exception);
            fail("Couldn't load the curve options. Try again.");
        }

    });

    method.addEventListener("change", () => {
        showInputs();
        stale();
    });

    form.addEventListener("input", stale);
    target.addEventListener("change", stale);

    form.addEventListener("submit", async (event) => {

        event.preventDefault();

        stale();

        try {

            const data = await previewCurve(request());

            if (!data.success) {
                fail(data.message);
                return;
            }

            preview(data);

        } catch (exception) {

            console.error(exception);
            fail("Couldn't preview the curve. Check your connection and try again.");

        }

    });

    apply.addEventListener("click", async () => {

        apply.disabled = true;

        try {

            const outcome = await applyCurve(request());

            if (!outcome.success) {
                fail(outcome.message);
                apply.disabled = false;
                return;
            }

            dialog.close();

            await reload();

        } catch (exception) {

            console.error(exception);
            fail("That didn't go through. Check your connection and try again.");
            apply.disabled = false;

        }

    });
}

function pick(values, names)
{
    return Object.fromEntries(Object.entries(values).filter(([name]) => names.includes(name)));
}
