/*
|--------------------------------------------------------------------------
| Dependencies of a module or a learning unit
|--------------------------------------------------------------------------
| The same flow as a learning item's (learning-item-dependencies.js): "Allow
| dependencies", then the previous module (unit), or a custom list of earlier
| ones that must all be completed. A module (unit) is completed when every
| published item in it is. Options come from GET …/modules/{id}/dependencies
| (…/learning-units/{id}/dependencies) and saving PATCHes the same address,
| then the page reloads so the lock labels update.
*/

import { fetchContainerDependencies, saveContainerDependencies } from "../api.js";

const KINDS = {
    module: {
        trigger: "[data-module-dependencies]",
        idKey: "moduleId",
        titleKey: "moduleTitle",
        previous: "previous module",
        earlier: "earlier modules in this course",
        none: "There are no earlier modules in this course.",
        first: "This is the first module, so there's nothing before it.",
        what: "module",
    },
    unit: {
        trigger: "[data-learning-unit-dependencies]",
        idKey: "learningUnitId",
        titleKey: "learningUnitTitle",
        previous: "previous learning unit",
        earlier: "earlier learning units in this module",
        none: "There are no earlier learning units in this module.",
        first: "This is the first learning unit in its module, so there's nothing before it.",
        what: "learning unit",
    },
};

/**
 * @param {{kind: "module"|"unit", modal: {element: HTMLElement, open: Function, close: Function}|null, refresh: () => Promise<void>|void}} options
 */
export function bindContainerDependenciesModal({ kind, modal, refresh }) {

    if (!modal) {
        return;
    }

    const words = KINDS[kind];
    const root = modal.element;

    const title = root.querySelector("[data-dependencies-item-title]");
    const enabled = root.querySelector("[data-dependencies-enabled]");
    const options = root.querySelector("[data-dependencies-options]");
    const previousHint = root.querySelector("[data-dependencies-previous-hint]");
    const customBox = root.querySelector("[data-dependencies-custom]");
    const candidateList = root.querySelector("[data-dependencies-candidates]");
    const errorLabel = root.querySelector("[data-dependencies-error]");
    const saveButton = root.querySelector("[data-dependencies-save]");

    const modeInput = (value) => root.querySelector(`[data-dependencies-mode][value="${value}"]`);
    const selectedMode = () => root.querySelector("[data-dependencies-mode]:checked")?.value ?? null;

    let currentId = null;

    const showError = (message) => {

        errorLabel.textContent = message ?? "";
        errorLabel.classList.toggle("hidden", !message);

    };

    const syncVisibility = () => {

        options.classList.toggle("hidden", !enabled.checked);
        options.classList.toggle("flex", enabled.checked);

        const showCustom = enabled.checked && selectedMode() === "custom";

        customBox.classList.toggle("hidden", !showCustom);
        customBox.classList.toggle("flex", showCustom);

    };

    const render = (data) => {

        title.textContent = `“${data.item.title}”`;

        const previous = modeInput("previous");

        previous.disabled = !data.previous;

        previousHint.textContent = data.previous
            ? `Unlocks after “${data.previous.title}” is completed.`
            : words.first;

        candidateList.replaceChildren();

        if (!data.candidates.length) {

            const empty = document.createElement("p");

            empty.className = "px-2 py-3 text-center text-xs text-[#767676]";
            empty.textContent = words.none;
            candidateList.append(empty);

        }

        data.candidates.forEach((candidate) => {

            const row = document.createElement("label");
            const checkbox = document.createElement("input");
            const name = document.createElement("span");
            const meta = document.createElement("span");

            row.className = "flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-white";

            checkbox.type = "checkbox";
            checkbox.value = String(candidate.id);
            checkbox.className = "accent-brand";
            checkbox.dataset.dependencyCandidate = "";
            checkbox.checked = data.prerequisiteIds.includes(candidate.id);

            name.className = "flex-1 truncate";
            name.textContent = candidate.title;

            meta.className = "shrink-0 text-xs capitalize text-[#767676]";
            meta.textContent = candidate.status === "published" ? "" : candidate.status;

            row.append(checkbox, name, meta);
            candidateList.append(row);

        });

        enabled.checked = data.mode !== "none";

        root.querySelectorAll("[data-dependencies-mode]").forEach((input) => {
            input.checked = input.value === data.mode;
        });

        if (enabled.checked && !selectedMode()) {
            (data.previous ? previous : modeInput("custom")).checked = true;
        }

        syncVisibility();

    };

    document.querySelectorAll(words.trigger).forEach((button) => {

        button.addEventListener("click", async (event) => {

            event.preventDefault();
            event.stopPropagation();

            currentId = Number(button.dataset[words.idKey]);

            showError("");
            title.textContent = `“${button.dataset[words.titleKey] ?? ""}”`;
            saveButton.disabled = true;

            modal.open();

            try {

                render(await fetchContainerDependencies(kind, currentId));

                saveButton.disabled = false;

            } catch (error) {

                console.error(error);

                showError(`Couldn't load this ${words.what}'s dependencies.`);

            }

        });

    });

    enabled.addEventListener("change", () => {

        if (enabled.checked && !selectedMode()) {

            const previous = modeInput("previous");

            (previous.disabled ? modeInput("custom") : previous).checked = true;

        }

        syncVisibility();

    });

    root.querySelectorAll("[data-dependencies-mode]").forEach((input) => input.addEventListener("change", syncVisibility));

    saveButton.addEventListener("click", async () => {

        showError("");

        const mode = enabled.checked ? selectedMode() : "none";

        const prerequisiteIds = [...root.querySelectorAll("[data-dependency-candidate]:checked")].map((box) => Number(box.value));

        if (mode === "custom" && !prerequisiteIds.length) {

            showError(`Pick at least one ${words.what}, or turn dependencies off.`);

            return;

        }

        saveButton.disabled = true;

        try {

            const result = await saveContainerDependencies(kind, currentId, { mode: mode ?? "none", prerequisite_ids: prerequisiteIds });

            if (!result.success) {

                showError(result.message);

                return;

            }

            modal.close();

            await refresh();

        } catch (error) {

            console.error(error);

            showError("Couldn't save. Please try again.");

        } finally {

            saveButton.disabled = false;

        }

    });

}
