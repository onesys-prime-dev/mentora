/*
|--------------------------------------------------------------------------
| Assignment Rubrics
|--------------------------------------------------------------------------
*/

import { bindModal } from "../modals/index.js";

import {
    fetchRubrics,
    fetchRubric,
    createRubric,
    selectRubric,
    updateRubric,
    deleteRubric,
    createRubricCriterion,
    updateRubricCriterion,
    deleteRubricCriterion,
} from "../api.js";

const state = {

    offeringId: null,
    assessmentId: null,
    refresh: null,

    // "create" | "editor" — which rubric modal the criteria flow belongs to
    criteriaContext: null,

    // staged (unsaved) criteria for the "create rubric" flow
    stagedCriteria: [],
    stagedCriteriaEditIndex: null,

    // live criteria for the "edit rubric" flow (rubric already persisted)
    editorRubricId: null,
    editorCriteria: [],
    editorCriterionEditId: null,

    // pending deletions
    deleteRubricId: null,
    deleteCriterionTarget: null, // { context, id | index }

};

let modals = {};

export function initializeRubrics(offeringId, assessmentId, refresh) {

    state.offeringId = offeringId;
    state.assessmentId = assessmentId;
    state.refresh = refresh;

    modals = {

        selector: bindModal({
            modal: "#rubric-selector-modal",
            closeButtons: "[data-close-rubric-modal]",
        }),

        editor: bindModal({
            modal: "#rubric-editor-modal",
            closeButtons: "[data-close-rubric-modal]",
        }),

        create: bindModal({
            modal: "#rubric-create-modal",
            closeButtons: "[data-close-rubric-modal]",
        }),

        delete: bindModal({
            modal: "#rubric-delete-modal",
            closeButtons: "[data-close-rubric-delete-modal]",
        }),

        criteriaCreate: bindModal({
            modal: "#rubric-criteria-create-modal",
            closeButtons: "[data-close-rubric-criteria-modal]",
        }),

        criteriaEditor: bindModal({
            modal: "#rubric-criteria-editor-modal",
            closeButtons: "[data-close-rubric-criteria-modal]",
        }),

        criteriaDelete: bindModal({
            modal: "#rubric-criteria-delete-modal",
            closeButtons: "[data-close-rubric-criteria-delete-modal]",
        }),

    };

    bindOptionButtons();
    bindUnselectButton();
    bindEditRubricButtons();
    bindCreateModal();
    bindEditorModal();
    bindDeleteModal();
    bindCriteriaCreateModal();
    bindCriteriaEditorModal();
    bindCriteriaDeleteModal();

}

/*
|--------------------------------------------------------------------------
| Entry Points (Use Existing / Create New / Unselect / Edit)
|--------------------------------------------------------------------------
*/

function bindOptionButtons() {

    document
        .querySelector("[data-use-existing-rubric]")
        ?.addEventListener("click", async () => {

            await openSelectorModal();

        });

    document
        .querySelector("[data-create-new-rubric]")
        ?.addEventListener("click", () => {

            openCreateModal();

        });

}

function bindUnselectButton() {

    document
        .querySelector("[data-unselect-rubric]")
        ?.addEventListener("click", async (event) => {

            const rubricId =
                event.currentTarget.dataset.rubricId;

            if (!rubricId) {
                return;
            }

            await deleteRubric(
                state.offeringId,
                rubricId
            );

            await state.refresh();

        });

}

function bindEditRubricButtons() {

    document
        .querySelectorAll("[data-edit-rubric]")
        .forEach((button) => {

            button.addEventListener("click", async () => {

                const rubricId =
                    button.dataset.rubricId;

                if (!rubricId) {
                    return;
                }

                await openEditorModal(rubricId);

            });

        });

}

/*
|--------------------------------------------------------------------------
| Rubric Selector Modal
|--------------------------------------------------------------------------
*/

async function openSelectorModal() {

    const list =
        document.querySelector("[data-rubric-list]");

    const empty =
        document.querySelector("[data-rubric-list-empty]");

    if (!list) {
        return;
    }

    list.innerHTML =
        "<p class=\"text-sm text-gray-400\">Loading...</p>";

    modals.selector.open();

    try {

        const result =
            await fetchRubrics(
                state.offeringId,
                state.assessmentId
            );

        const rubrics =
            result.rubrics ?? [];

        if (!rubrics.length) {

            list.innerHTML = "";

            list.classList.add("hidden");

            empty?.classList.remove("hidden");

            empty?.classList.add("flex");

            return;

        }

        list.classList.remove("hidden");

        empty?.classList.add("hidden");

        empty?.classList.remove("flex");

        list.innerHTML =
            rubrics.map(renderRubricCard).join("");

        list
            .querySelectorAll("[data-rubric-card]")
            .forEach((card) => {

                card.addEventListener("click", async (event) => {

                    if (event.target.closest("[data-edit-rubric]")) {
                        return;
                    }

                    const rubricId =
                        card.dataset.rubricId;

                    await selectRubric(
                        state.offeringId,
                        state.assessmentId,
                        rubricId
                    );

                    modals.selector.close();

                    await state.refresh();

                });

            });

        list
            .querySelectorAll("[data-edit-rubric]")
            .forEach((button) => {

                button.addEventListener("click", async (event) => {

                    event.stopPropagation();

                    const rubricId =
                        button.dataset.rubricId;

                    modals.selector.close();

                    await openEditorModal(rubricId);

                });

            });

    } catch (error) {

        console.error(error);

        list.innerHTML =
            "<p class=\"text-sm text-red-500\">Unable to load rubrics.</p>";

    }

}

function renderRubricCard(rubric) {

    const criteriaCount =
        Number(rubric.criteria_count ?? 0);

    return `
        <div
            data-rubric-card
            data-rubric-id="${rubric.id}"
            class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-gray-200 p-4 transition hover:border-[#0040A6] hover:bg-[#0040A6]/5">

            <div class="flex flex-col gap-1">

                <span class="font-semibold text-[#0040A6]">
                    ${escapeHtml(rubric.title)}
                </span>

                <span class="text-sm text-gray-500">
                    ${escapeHtml(rubric.description ?? "")}
                </span>

                <span class="text-xs font-medium text-gray-400">
                    ${criteriaCount} ${criteriaCount === 1 ? "criterion" : "criteria"}
                </span>

            </div>

            <button
                type="button"
                data-edit-rubric
                data-rubric-id="${rubric.id}"
                class="shrink-0 rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>
                </svg>

            </button>

        </div>
    `;

}

/*
|--------------------------------------------------------------------------
| Create Rubric Modal
|--------------------------------------------------------------------------
*/

function openCreateModal() {

    const form =
        document.querySelector("#rubric-create-form");

    form?.reset();

    state.stagedCriteria = [];

    renderStagedCriteria();

    modals.create.open();

}

function bindCreateModal() {

    const form =
        document.querySelector("#rubric-create-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const title =
            document.querySelector("[data-rubric-create-title]")?.value.trim();

        const description =
            document.querySelector("[data-rubric-create-description]")?.value.trim();

        if (!title) {
            return;
        }

        await createRubric(
            state.offeringId,
            state.assessmentId,
            {
                title,
                description,
                criteria: state.stagedCriteria,
            }
        );

        modals.create.close();

        await state.refresh();

    });

}

function renderStagedCriteria() {

    const list =
        document.querySelector("[data-rubric-criteria-create-list]");

    const empty =
        document.querySelector("[data-rubric-criteria-create-empty]");

    if (!list) {
        return;
    }

    if (!state.stagedCriteria.length) {

        list.innerHTML = "";

        empty?.classList.remove("hidden");

        return;

    }

    empty?.classList.add("hidden");

    list.innerHTML =
        state.stagedCriteria
            .map((criterion, index) => renderCriteriaCard(criterion, index, "create"))
            .join("");

    bindCriteriaCardButtons(list, "create");

}

/*
|--------------------------------------------------------------------------
| Edit Rubric Modal
|--------------------------------------------------------------------------
*/

async function openEditorModal(rubricId) {

    try {

        const result =
            await fetchRubric(
                state.offeringId,
                rubricId
            );

        if (!result.rubric) {
            return;
        }

        state.editorRubricId = rubricId;

        state.editorCriteria =
            result.rubric.criteria ?? [];

        const titleInput =
            document.querySelector("[data-rubric-editor-title]");

        const descriptionInput =
            document.querySelector("[data-rubric-editor-description]");

        const idInput =
            document.querySelector("[data-rubric-editor-id]");

        if (titleInput) {
            titleInput.value = result.rubric.title ?? "";
        }

        if (descriptionInput) {
            descriptionInput.value = result.rubric.description ?? "";
        }

        if (idInput) {
            idInput.value = rubricId;
        }

        renderEditorCriteria();

        modals.editor.open();

    } catch (error) {

        console.error(error);

    }

}

function bindEditorModal() {

    const form =
        document.querySelector("#rubric-editor-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const title =
            document.querySelector("[data-rubric-editor-title]")?.value.trim();

        const description =
            document.querySelector("[data-rubric-editor-description]")?.value.trim();

        if (!title || !state.editorRubricId) {
            return;
        }

        await updateRubric(
            state.offeringId,
            state.editorRubricId,
            { title, description }
        );

        modals.editor.close();

        await state.refresh();

    });

    document
        .querySelector("[data-delete-rubric]")
        ?.addEventListener("click", () => {

            if (!state.editorRubricId) {
                return;
            }

            state.deleteRubricId =
                state.editorRubricId;

            modals.delete.open();

        });

}

function renderEditorCriteria() {

    const list =
        document.querySelector("[data-rubric-criteria-editor-list]");

    const empty =
        document.querySelector("[data-rubric-criteria-editor-empty]");

    if (!list) {
        return;
    }

    if (!state.editorCriteria.length) {

        list.innerHTML = "";

        empty?.classList.remove("hidden");

        return;

    }

    empty?.classList.add("hidden");

    list.innerHTML =
        state.editorCriteria
            .map((criterion) => renderCriteriaCard(criterion, criterion.id, "editor"))
            .join("");

    bindCriteriaCardButtons(list, "editor");

}

/*
|--------------------------------------------------------------------------
| Delete Rubric Modal
|--------------------------------------------------------------------------
*/

function bindDeleteModal() {

    document
        .querySelector("[data-confirm-delete-rubric]")
        ?.addEventListener("click", async () => {

            if (!state.deleteRubricId) {
                return;
            }

            await deleteRubric(
                state.offeringId,
                state.deleteRubricId
            );

            state.deleteRubricId = null;

            modals.delete.close();

            modals.editor.close();

            await state.refresh();

        });

}

/*
|--------------------------------------------------------------------------
| Criteria Cards (shared render for create / editor contexts)
|--------------------------------------------------------------------------
*/

function renderCriteriaCard(criterion, key, context) {

    const title =
        criterion.title ?? criterion.criteria_title ?? "";

    const description =
        criterion.description ?? "";

    const maxPoints =
        criterion.max_points ?? 0;

    return `
        <div class="flex flex-col gap-1 rounded-xl bg-[#F2F2F2] p-4">

            <div class="flex items-start justify-between gap-2">

                <span class="font-bold text-md text-[#0040A6]">
                    ${escapeHtml(String(maxPoints))} pts
                </span>

                <button
                    type="button"
                    data-edit-rubric-criteria
                    data-context="${context}"
                    data-key="${key}"
                    class="shrink-0 rounded-lg p-1 text-gray-500 transition hover:bg-white hover:text-[#0040A6]">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>
                    </svg>

                </button>

            </div>

            <span class="font-bold text-sm text-[#646464]">
                ${escapeHtml(title)}
            </span>

            <span class="text-sm text-[#646464]">
                ${escapeHtml(description)}
            </span>

        </div>
    `;

}

function bindCriteriaCardButtons(container, context) {

    container
        .querySelectorAll("[data-edit-rubric-criteria]")
        .forEach((button) => {

            button.addEventListener("click", () => {

                const key =
                    button.dataset.key;

                openCriteriaEditorModal(context, key);

            });

        });

}

/*
|--------------------------------------------------------------------------
| Add / Edit Criteria Modal
|--------------------------------------------------------------------------
*/

function bindCriteriaCreateModal() {

    document
        .querySelectorAll("[data-add-rubric-criteria]")
        .forEach((button) => {

            button.addEventListener("click", () => {

                state.criteriaContext =
                    button.dataset.context;

                const form =
                    document.querySelector("#rubric-criteria-create-form");

                form?.reset();

                modals.criteriaCreate.open();

            });

        });

    const form =
        document.querySelector("#rubric-criteria-create-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const title =
            document.querySelector("[data-criteria-create-title]")?.value.trim();

        const description =
            document.querySelector("[data-criteria-create-description]")?.value.trim();

        const maxPoints =
            Number(
                document.querySelector("[data-criteria-create-max-points]")?.value
            );

        if (!title) {
            return;
        }

        if (state.criteriaContext === "editor" && state.editorRubricId) {

            const result =
                await createRubricCriterion(
                    state.offeringId,
                    state.editorRubricId,
                    { title, description, max_points: maxPoints }
                );

            state.editorCriteria =
                result.rubric?.criteria ?? state.editorCriteria;

            renderEditorCriteria();

        }

        else {

            state.stagedCriteria.push({
                title,
                description,
                max_points: maxPoints,
            });

            renderStagedCriteria();

        }

        modals.criteriaCreate.close();

    });

}

function openCriteriaEditorModal(context, key) {

    state.criteriaContext = context;

    let criterion = null;

    if (context === "create") {

        state.stagedCriteriaEditIndex = Number(key);

        criterion = state.stagedCriteria[state.stagedCriteriaEditIndex];

    }

    else {

        state.editorCriterionEditId = key;

        criterion = state.editorCriteria.find(
            (item) => String(item.id) === String(key)
        );

    }

    if (!criterion) {
        return;
    }

    const titleInput =
        document.querySelector("[data-criteria-editor-title]");

    const descriptionInput =
        document.querySelector("[data-criteria-editor-description]");

    const maxPointsInput =
        document.querySelector("[data-criteria-editor-max-points]");

    if (titleInput) {
        titleInput.value = criterion.title ?? criterion.criteria_title ?? "";
    }

    if (descriptionInput) {
        descriptionInput.value = criterion.description ?? "";
    }

    if (maxPointsInput) {
        maxPointsInput.value = criterion.max_points ?? 0;
    }

    modals.criteriaEditor.open();

}

function bindCriteriaEditorModal() {

    const form =
        document.querySelector("#rubric-criteria-editor-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const title =
            document.querySelector("[data-criteria-editor-title]")?.value.trim();

        const description =
            document.querySelector("[data-criteria-editor-description]")?.value.trim();

        const maxPoints =
            Number(
                document.querySelector("[data-criteria-editor-max-points]")?.value
            );

        if (!title) {
            return;
        }

        if (state.criteriaContext === "editor" && state.editorCriterionEditId) {

            const result =
                await updateRubricCriterion(
                    state.offeringId,
                    state.editorCriterionEditId,
                    { title, description, max_points: maxPoints }
                );

            state.editorCriteria =
                result.rubric?.criteria ?? state.editorCriteria;

            renderEditorCriteria();

        }

        else if (state.stagedCriteriaEditIndex !== null) {

            state.stagedCriteria[state.stagedCriteriaEditIndex] = {
                title,
                description,
                max_points: maxPoints,
            };

            renderStagedCriteria();

        }

        modals.criteriaEditor.close();

    });

    document
        .querySelector("[data-delete-rubric-criteria]")
        ?.addEventListener("click", () => {

            state.deleteCriterionTarget = {
                context: state.criteriaContext,
                key: state.criteriaContext === "editor"
                    ? state.editorCriterionEditId
                    : state.stagedCriteriaEditIndex,
            };

            modals.criteriaDelete.open();

        });

}

function bindCriteriaDeleteModal() {

    document
        .querySelector("[data-confirm-delete-rubric-criteria]")
        ?.addEventListener("click", async () => {

            const target =
                state.deleteCriterionTarget;

            if (!target) {
                return;
            }

            if (target.context === "editor" && target.key) {

                const result =
                    await deleteRubricCriterion(
                        state.offeringId,
                        target.key
                    );

                state.editorCriteria =
                    result.rubric?.criteria ?? [];

                renderEditorCriteria();

            }

            else if (target.key !== null && target.key !== undefined) {

                state.stagedCriteria.splice(target.key, 1);

                renderStagedCriteria();

            }

            state.deleteCriterionTarget = null;

            modals.criteriaDelete.close();

            modals.criteriaEditor.close();

        });

}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    const div =
        document.createElement("div");

    div.textContent =
        value ?? "";

    return div.innerHTML;

}
