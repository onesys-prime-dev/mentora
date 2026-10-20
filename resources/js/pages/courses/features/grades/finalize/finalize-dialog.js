/*
|--------------------------------------------------------------------------
| Finalize / reopen the section's grades
|--------------------------------------------------------------------------
|
| The Grades toolbar's "Finalize grades" button opens a checklist: what still
| blocks it, and what the instructor should know (missing work will count as
| 0). "Reopen" asks for a reason. Either way the Grades page is loaded again
| so every screen shows the new state.
|
*/

import { fetchFinalization, finalizeGrades, reopenGrades } from "../api.js";
import { bindModal } from "../../assessments/modals/index.js";

const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

const list = (items, tone) => `
    <ul class="flex flex-col gap-2">
        ${items.map((item) => `
            <li class="flex gap-2 rounded-xl px-4 py-3 text-sm ${tone === "block" ? "bg-[#FDEDEC] text-[#9B2C2C]" : "bg-[#FFF8E6] text-[#946200]"}">
                <span aria-hidden="true">${tone === "block" ? "✕" : "!"}</span>
                <span>${escapeHtml(item)}</span>
            </li>`).join("")}
    </ul>`;

/**
 * @param {() => Promise<void>} reload loads the Grades page again
 */
export function bindFinalizeDialog(reload)
{
    const modal = document.querySelector("#grade-finalize-modal");

    if (!modal) {
        return;
    }

    const dialog = bindModal({
        modal: "#grade-finalize-modal",
        closeButtons: "[data-close-grade-finalize-modal]",
    });

    const title = modal.querySelector("[data-finalize-title]");
    const body = modal.querySelector("[data-finalize-body]");
    const confirm = modal.querySelector("[data-finalize-confirm]");

    let mode = "finalize";

    const fail = (message) => {
        body.insertAdjacentHTML("beforeend", `<p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600" role="alert">${escapeHtml(message)}</p>`);
    };

    const open = async (nextMode) => {

        mode = nextMode;

        confirm.classList.add("hidden");
        confirm.disabled = false;
        body.innerHTML = `<p class="text-sm text-gray-500">Checking the gradebook…</p>`;
        title.textContent = mode === "reopen" ? "Reopen grades" : "Finalize grades";

        dialog.open();

        try {

            const result = await fetchFinalization();

            if (mode === "reopen") {

                body.innerHTML = `
                    <p class="text-sm text-gray-600">Reopening lifts the lock and brings back running grades. Students will see their grade as "so far" again. The reason is kept in the audit log.</p>
                    <div class="flex flex-col gap-2">
                        <label for="finalize-reopen-reason" class="text-sm font-semibold text-gray-700">Reason</label>
                        <textarea id="finalize-reopen-reason" rows="3" data-finalize-reason class="rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-brand" placeholder="e.g. A score was entered wrongly"></textarea>
                    </div>`;

                confirm.textContent = "Reopen grades";
                confirm.classList.remove("hidden");

                return;

            }

            const { readiness } = result;

            body.innerHTML = `
                <p class="text-sm text-gray-600">
                    Finalizing locks the gradebook for ${readiness.counts.students} ${readiness.counts.students === 1 ? "student" : "students"}.
                    Work that was never graded or handed in counts as 0, and each student's grade becomes Passed or Failed.
                    You can reopen the grades later with a reason.
                </p>
                ${readiness.blockers.length ? `<div class="flex flex-col gap-2"><p class="text-sm font-semibold text-gray-700">Before you can finalize</p>${list(readiness.blockers, "block")}</div>` : ""}
                ${readiness.notes.length ? `<div class="flex flex-col gap-2"><p class="text-sm font-semibold text-gray-700">Good to know</p>${list(readiness.notes, "note")}</div>` : ""}
                ${readiness.can_finalize ? "" : `<p class="text-sm text-gray-500">Fix the items above, then come back.</p>`}`;

            confirm.textContent = "Finalize grades";
            confirm.classList.toggle("hidden", !readiness.can_finalize);

        } catch (error) {

            console.error(error);
            body.innerHTML = "";
            fail("Couldn't check the gradebook. Try again.");

        }

    };

    confirm.addEventListener("click", async () => {

        confirm.disabled = true;

        try {

            let result;

            if (mode === "reopen") {

                const reason = body.querySelector("[data-finalize-reason]")?.value ?? "";

                if (reason.trim() === "") {
                    fail("Give a reason for reopening the grades.");
                    confirm.disabled = false;
                    return;
                }

                result = await reopenGrades(reason);

            } else {

                result = await finalizeGrades();

            }

            if (!result.success) {
                fail(result.message);
                confirm.disabled = false;
                return;
            }

            dialog.close();

            await reload();

        } catch (error) {

            console.error(error);
            fail("That didn't go through. Check your connection and try again.");
            confirm.disabled = false;

        }

    });

    document.querySelector("[data-open-finalize]")?.addEventListener("click", () => open("finalize"));
    document.querySelector("[data-open-reopen]")?.addEventListener("click", () => open("reopen"));
}
