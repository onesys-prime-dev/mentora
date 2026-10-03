/*
|--------------------------------------------------------------------------
| Learning Item Dependencies Modal
|--------------------------------------------------------------------------
|
| "Allow dependencies" toggle → Previous learning item | Custom dependency
| (checkbox list of the earlier items in the same unit). Options come from
| GET …/modules/items/{id}/dependencies; saving PATCHes the same URL and
| reloads the module list so the lock badges update.
|
*/

import {
    fetchLearningItemDependencies,
    saveLearningItemDependencies,
} from "../api.js";

export function bindLearningItemDependenciesModal({ modal, refresh }) {

    if (!modal) {
        return;
    }

    const root = modal.element;

    const title = root.querySelector("[data-dependencies-item-title]");
    const enabled = root.querySelector("[data-dependencies-enabled]");
    const options = root.querySelector("[data-dependencies-options]");
    const previousHint = root.querySelector("[data-dependencies-previous-hint]");
    const customBox = root.querySelector("[data-dependencies-custom]");
    const candidateList = root.querySelector("[data-dependencies-candidates]");
    const errorLabel = root.querySelector("[data-dependencies-error]");
    const saveButton = root.querySelector("[data-dependencies-save]");

    const modeInput = (value) =>
        root.querySelector(`[data-dependencies-mode][value="${value}"]`);

    let learningItemId = null;

    const showError = (message) => {

        errorLabel.textContent = message ?? "";

        errorLabel.classList.toggle("hidden", !message);

    };

    const selectedMode = () =>
        root.querySelector("[data-dependencies-mode]:checked")?.value ?? null;

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
            : "This is the first item in its unit, so there's nothing before it.";

        candidateList.innerHTML = "";

        if (!data.candidates.length) {

            const empty = document.createElement("p");

            empty.className = "px-2 py-3 text-center text-xs text-[#767676]";

            empty.textContent = "There are no earlier items in this unit.";

            candidateList.append(empty);

        }

        data.candidates.forEach((candidate) => {

            const row = document.createElement("label");

            row.className =
                "flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-white";

            const checkbox = document.createElement("input");

            checkbox.type = "checkbox";
            checkbox.value = String(candidate.id);
            checkbox.className = "accent-[#0040A6]";
            checkbox.dataset.dependencyCandidate = "";
            checkbox.checked = data.prerequisiteIds.includes(candidate.id);

            const name = document.createElement("span");

            name.className = "flex-1 truncate";
            name.textContent = candidate.title;

            const meta = document.createElement("span");

            meta.className = "shrink-0 text-xs capitalize text-[#767676]";
            meta.textContent = candidate.status === "published"
                ? candidate.item_type
                : `${candidate.item_type} · ${candidate.status}`;

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

    document
        .querySelectorAll("[data-learning-item-dependencies]")
        .forEach((button) => {

            button.addEventListener("click", async (event) => {

                event.preventDefault();
                event.stopPropagation();

                learningItemId = Number(button.dataset.learningItemId);

                showError("");

                title.textContent = `“${button.dataset.learningItemTitle ?? ""}”`;

                saveButton.disabled = true;

                modal.open();

                try {

                    render(await fetchLearningItemDependencies(learningItemId));

                    saveButton.disabled = false;

                }

                catch (error) {

                    console.error(error);

                    showError("Couldn't load this item's dependencies.");

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

    root
        .querySelectorAll("[data-dependencies-mode]")
        .forEach((input) => input.addEventListener("change", syncVisibility));

    saveButton.addEventListener("click", async () => {

        showError("");

        const mode = enabled.checked ? selectedMode() : "none";

        const prerequisiteIds = [
            ...root.querySelectorAll("[data-dependency-candidate]:checked"),
        ].map((checkbox) => Number(checkbox.value));

        if (mode === "custom" && !prerequisiteIds.length) {

            showError("Pick at least one item, or turn dependencies off.");

            return;

        }

        saveButton.disabled = true;

        try {

            const result = await saveLearningItemDependencies(learningItemId, {
                mode: mode ?? "none",
                prerequisite_ids: prerequisiteIds,
            });

            if (!result.success) {

                showError(result.message);

                return;

            }

            modal.close();

            await refresh();

        }

        catch (error) {

            console.error(error);

            showError("Couldn't save. Please try again.");

        }

        finally {

            saveButton.disabled = false;

        }

    });

}
