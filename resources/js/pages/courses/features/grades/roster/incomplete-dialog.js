/*
|--------------------------------------------------------------------------
| Incomplete (INC) dialog of the gradebook grid
|--------------------------------------------------------------------------
|
| The remarks chip of a row opens it. Marking saves the reason and the
| optional finish-by date; "Remove INC" takes it off. The grid is then
| loaded again so every cell shows what the server has.
|
*/

import { setIncomplete } from "../api.js";
import { bindModal } from "../../assessments/modals/index.js";

/**
 * @param {ParentNode} container the rendered gradebook grid
 * @param {() => Promise<void>} reload loads the grid again
 */
export function bindIncompleteDialog(container, reload)
{
    const modal = document.querySelector("#grade-incomplete-modal");
    const form = document.querySelector("#grade-incomplete-form");

    if (!modal || !form) {
        return;
    }

    const dialog = bindModal({
        modal: "#grade-incomplete-modal",
        closeButtons: "[data-close-grade-incomplete-modal]",
    });

    const studentId = form.querySelector("[data-incomplete-student-id]");
    const studentName = form.querySelector("[data-incomplete-student-name]");
    const reason = form.querySelector("[data-incomplete-reason]");
    const until = form.querySelector("[data-incomplete-until]");
    const error = form.querySelector("[data-incomplete-error]");
    const remove = modal.querySelector("[data-incomplete-remove]");
    const submit = modal.querySelector("[data-incomplete-submit]");

    const fail = (message) => {
        error.textContent = message;
        error.classList.remove("hidden");
    };

    container.querySelectorAll("[data-open-incomplete]").forEach((button) => {

        button.addEventListener("click", () => {

            const isInc = button.dataset.isInc === "1";

            studentId.value = button.dataset.studentId;
            studentName.textContent = button.dataset.studentName;
            reason.value = button.dataset.incReason ?? "";
            until.value = button.dataset.incUntil ?? "";
            error.classList.add("hidden");

            remove.classList.toggle("hidden", !isInc);
            submit.textContent = isInc ? "Save" : "Mark Incomplete";

            dialog.open();

        });

    });

    form.addEventListener("submit", async (event) => {

        event.preventDefault();

        if (reason.value.trim() === "") {
            fail("Give a reason for the Incomplete.");
            return;
        }

        try {

            const result = await setIncomplete(studentId.value, true, reason.value, until.value);

            if (!result.success) {
                fail(result.message);
                return;
            }

            dialog.close();

            await reload();

        } catch (exception) {

            console.error(exception);
            fail("Couldn't save. Check your connection and try again.");

        }

    });

    remove.addEventListener("click", async () => {

        try {

            const result = await setIncomplete(studentId.value, false);

            if (!result.success) {
                fail(result.message);
                return;
            }

            dialog.close();

            await reload();

        } catch (exception) {

            console.error(exception);
            fail("Couldn't remove it. Check your connection and try again.");

        }

    });
}
