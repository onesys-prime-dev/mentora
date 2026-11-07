/*
|--------------------------------------------------------------------------
| Specific students, in the assessment settings (every assessment)
|--------------------------------------------------------------------------
| The "Specific students" switch gives the assessment only to the students
| chosen. Under it: how many are chosen, and a button that opens a list of the
| section's students to tick or untick, with Select all.
|
| The list is fetched each time it opens (so it is never stale) and saved with
| Save as its own request. The switch itself is saved with the rest of the
| settings; problem() lets the page refuse saving it on with nobody chosen,
| because then nobody would see the assessment.
*/

import { bindModal } from "../modals/index.js";

const plural = (count) => `${count} ${count === 1 ? "student" : "students"} selected`;

/**
 * @param {object} options
 * @param {() => Promise<Array<{id: number, name: string, email: string, selected: boolean}>>} options.fetchStudents
 * @param {(ids: number[]) => Promise<{success: boolean, count?: number, message?: string}>} options.saveStudents
 * @param {(message: string, tone: string) => void} options.notify
 * @returns {{problem: () => string|null}}
 */
export function bindSpecificStudents({ fetchStudents, saveStudents, notify }) {

    const container = document.querySelector("[data-specific-students]");

    if (!container) {
        return { problem: () => null };
    }

    const toggle = container.querySelector("[data-specific-toggle]");
    const state = container.querySelector("[data-specific-state]");
    const countLabel = container.querySelector("[data-specific-count]");

    const root = document.querySelector("#assignees-modal");
    const list = root?.querySelector("[data-assignees-list]");
    const all = root?.querySelector("[data-assignees-all]");
    const summary = root?.querySelector("[data-assignees-summary]");
    const error = root?.querySelector("[data-assignees-error]");
    const saveButton = root?.querySelector("[data-assignees-save]");

    const modal = bindModal({
        modal: "#assignees-modal",
        closeButtons: "[data-close-assignees-modal]",
    });

    const count = () => Number(container.dataset.assigneeCount || 0);

    const boxes = () => [...(list?.querySelectorAll("[data-assignee-id]") ?? [])];

    function renderSwitch() {

        state?.classList.toggle("hidden", !toggle?.checked);

    }

    function renderSummary() {

        const all_ = boxes();
        const ticked = all_.filter((box) => box.checked).length;

        summary.textContent = `${ticked} of ${all_.length} selected`;
        all.checked = all_.length > 0 && ticked === all_.length;
        all.disabled = all_.length === 0;

    }

    function renderList(students) {

        list.replaceChildren();

        if (students.length === 0) {

            const empty = document.createElement("li");
            empty.className = "py-3 text-sm italic text-[#767676]";
            empty.textContent = "No students are enrolled in this section yet.";
            list.append(empty);

        }

        students.forEach((student) => {

            const item = document.createElement("li");
            const label = document.createElement("label");
            const box = document.createElement("input");
            const text = document.createElement("span");
            const name = document.createElement("span");
            const email = document.createElement("span");

            label.className = "flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-gray-50";

            box.type = "checkbox";
            box.className = "h-4 w-4 rounded border-gray-300 accent-brand";
            box.dataset.assigneeId = String(student.id);
            box.checked = Boolean(student.selected);

            text.className = "flex min-w-0 flex-col";
            name.className = "truncate text-sm font-medium text-gray-700";
            name.textContent = student.name;
            email.className = "truncate text-xs text-[#767676]";
            email.textContent = student.email;

            text.append(name, email);
            label.append(box, text);
            item.append(label);
            list.append(item);

        });

        renderSummary();

    }

    async function openModal() {

        if (!modal || !list) {
            return;
        }

        error.textContent = "";
        list.replaceChildren();
        summary.textContent = "";
        modal.open();

        try {

            renderList(await fetchStudents());

        } catch {

            error.textContent = "The students could not be loaded. Check your connection and try again.";

        }

    }

    toggle?.addEventListener("change", renderSwitch);
    container.querySelector("[data-specific-open]")?.addEventListener("click", openModal);

    list?.addEventListener("change", renderSummary);

    all?.addEventListener("change", () => {

        boxes().forEach((box) => {
            box.checked = all.checked;
        });

        renderSummary();

    });

    let saving = false;

    async function save() {

        if (saving || !saveButton) {
            return;
        }

        const ids = boxes().filter((box) => box.checked).map((box) => Number(box.dataset.assigneeId));

        saving = true;
        saveButton.disabled = true;
        error.textContent = "";

        try {

            const result = await saveStudents(ids);

            if (result?.success) {

                const saved = Number(result.count ?? ids.length);

                container.dataset.assigneeCount = String(saved);
                countLabel.textContent = plural(saved);
                modal.close();
                notify(result.message || "Students saved.", "success");

            } else {

                error.textContent = result?.message || "The students could not be saved.";

            }

        } catch {

            error.textContent = "The students could not be saved. Check your connection and try again.";

        } finally {

            saving = false;
            saveButton.disabled = false;

        }

    }

    saveButton?.addEventListener("click", save);

    renderSwitch();

    return {

        /** What stops the settings being saved with the switch on and nobody chosen, or null. */
        problem() {

            return toggle?.checked && count() === 0
                ? "Choose at least one student, or switch Specific students off."
                : null;

        },

    };

}
