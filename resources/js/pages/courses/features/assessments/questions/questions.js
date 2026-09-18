/*
|--------------------------------------------------------------------------
| Quiz Builder (drag-and-drop canvas)
|--------------------------------------------------------------------------
*/

import { bindModal } from "../modals/index.js";

import {
    createQuestion,
    updateQuestion,
    deleteQuestion,
    duplicateQuestion,
    reorderQuestions,
} from "../api.js";

const state = {

    offeringId: null,
    assessmentId: null,
    refresh: null,

    deleteQuestionId: null,

};

let modals = {};

let pendingActiveId = null;

const pendingPayloads = new Map();
const saveTimers = new Map();

export function initializeQuestions(offeringId, assessmentId, refresh) {

    state.offeringId = offeringId;
    state.assessmentId = assessmentId;
    state.refresh = refresh;

    modals = {

        settings: bindModal({
            modal: "#question-settings-modal",
            closeButtons: "[data-close-question-settings-modal]",
        }),

        delete: bindModal({
            modal: "#question-delete-modal",
            closeButtons: "[data-close-question-delete-modal]",
        }),

    };

    bindPalette();
    bindCanvasDragDrop();
    bindCardActions();
    bindSettingsModal();
    bindDeleteModal();

    if (pendingActiveId) {

        const card =
            document.querySelector(`[data-question-card][data-question-id="${pendingActiveId}"]`);

        if (card) {

            setActiveQuestion(card);

            card.querySelector("[data-question-text]")?.focus();

        }

        pendingActiveId = null;

    }

}

/*
|--------------------------------------------------------------------------
| Palette
|--------------------------------------------------------------------------
*/

function bindPalette() {

    document
        .querySelectorAll("[data-palette-item]")
        .forEach((item) => {

            item.addEventListener("dragstart", (event) => {

                event.dataTransfer.setData(
                    "application/x-palette-type",
                    item.dataset.paletteType
                );

                event.dataTransfer.effectAllowed = "copy";

            });

            item.addEventListener("click", () => {

                createQuestionAt(item.dataset.paletteType, null);

            });

        });

}

async function createQuestionAt(type, beforeQuestionId) {

    const defaultChoices =
        type === "Multiple Choice"
            ? [
                { text: "Option 1", is_correct: true },
                { text: "Option 2", is_correct: false },
            ]
            : [];

    try {

        const result =
            await createQuestion(
                state.offeringId,
                state.assessmentId,
                {
                    question_text: "",
                    question_type: type,
                    points: 1,
                    is_required: true,
                    choices: defaultChoices,
                }
            );

        const newId = result.question.id;

        if (beforeQuestionId) {

            const list =
                document.querySelector("[data-question-list]");

            const ids =
                Array.from(
                    list.querySelectorAll("[data-question-card]")
                ).map((card) => card.dataset.questionId);

            const filtered =
                ids.filter((id) => id != newId);

            const targetIndex =
                filtered.indexOf(String(beforeQuestionId));

            filtered.splice(
                targetIndex === -1 ? filtered.length : targetIndex,
                0,
                newId
            );

            await reorderQuestions(
                state.offeringId,
                state.assessmentId,
                filtered
            );

        }

        pendingActiveId = newId;

        await state.refresh();

    } catch (error) {

        console.error(error);

    }

}

/*
|--------------------------------------------------------------------------
| Canvas Drag & Drop (new questions + reordering)
|--------------------------------------------------------------------------
*/

function bindCanvasDragDrop() {

    const list =
        document.querySelector("[data-question-list]");

    if (!list) {
        return;
    }

    list.addEventListener("dragover", (event) => {

        event.preventDefault();

        const target =
            getDropTarget(list, event.clientY);

        showDropIndicator(list, target);

    });

    list.addEventListener("drop", async (event) => {

        event.preventDefault();

        const target =
            getDropTarget(list, event.clientY);

        removeDropIndicator(list);

        const paletteType =
            event.dataTransfer.getData("application/x-palette-type");

        const draggedQuestionId =
            event.dataTransfer.getData("application/x-question-id");

        if (paletteType) {

            await createQuestionAt(
                paletteType,
                target ? target.dataset.questionId : null
            );

            return;

        }

        if (draggedQuestionId) {

            const draggedCard =
                list.querySelector(
                    `[data-question-card][data-question-id="${draggedQuestionId}"]`
                );

            if (!draggedCard) {
                return;
            }

            if (target) {
                list.insertBefore(draggedCard, target);
            } else {
                list.appendChild(draggedCard);
            }

            draggedCard.classList.remove("opacity-40");

            draggedCard.draggable = false;

            renumberQuestions(list);

            const ids =
                Array.from(
                    list.querySelectorAll("[data-question-card]")
                ).map((card) => card.dataset.questionId);

            try {

                await reorderQuestions(
                    state.offeringId,
                    state.assessmentId,
                    ids
                );

            } catch (error) {

                console.error(error);

            }

        }

    });

    list.addEventListener("dragstart", (event) => {

        const card =
            event.target.closest("[data-question-card]");

        if (!card || !card.draggable) {
            return;
        }

        event.dataTransfer.setData(
            "application/x-question-id",
            card.dataset.questionId
        );

        event.dataTransfer.effectAllowed = "move";

        card.classList.add("opacity-40");

    });

    list.addEventListener("dragend", (event) => {

        const card =
            event.target.closest("[data-question-card]");

        if (card) {

            card.classList.remove("opacity-40");

            card.draggable = false;

        }

        removeDropIndicator(list);

    });

    list.addEventListener("mousedown", (event) => {

        const handle =
            event.target.closest("[data-drag-handle]");

        if (!handle) {
            return;
        }

        const card =
            handle.closest("[data-question-card]");

        if (card) {
            card.draggable = true;
        }

    });

    list.addEventListener("mouseup", () => {

        setTimeout(() => {

            list
                .querySelectorAll("[data-question-card]")
                .forEach((card) => {
                    card.draggable = false;
                });

        }, 0);

    });

}

function getDropTarget(list, clientY) {

    const cards =
        Array.from(
            list.querySelectorAll("[data-question-card]:not(.opacity-40)")
        );

    for (const card of cards) {

        const rect =
            card.getBoundingClientRect();

        const midpoint =
            rect.top + rect.height / 2;

        if (clientY < midpoint) {
            return card;
        }

    }

    return null;

}

function showDropIndicator(list, beforeCard) {

    let indicator =
        list.querySelector("[data-drop-indicator]");

    if (!indicator) {

        indicator =
            document.createElement("div");

        indicator.dataset.dropIndicator = "";

        indicator.className =
            "h-1 rounded-full bg-[#0040A6]";

    }

    if (beforeCard) {
        list.insertBefore(indicator, beforeCard);
    } else {
        list.appendChild(indicator);
    }

}

function removeDropIndicator(list) {

    list.querySelector("[data-drop-indicator]")?.remove();

}

function renumberQuestions(list) {

    list
        .querySelectorAll("[data-question-card]")
        .forEach((card, index) => {

            const numberEl =
                card.querySelector("[data-question-number]");

            if (numberEl) {
                numberEl.textContent = `Q${index + 1}`;
            }

        });

}

/*
|--------------------------------------------------------------------------
| Active State / Card Actions
|--------------------------------------------------------------------------
*/

function setActiveQuestion(card) {

    document
        .querySelectorAll("[data-question-card]")
        .forEach((c) => {

            c.classList.remove("border-[#0040A6]", "bg-[#0040A6]/5");

            c.classList.add("border-transparent");

        });

    if (card) {

        card.classList.remove("border-transparent");

        card.classList.add("border-[#0040A6]", "bg-[#0040A6]/5");

    }

}

function bindCardActions() {

    const list =
        document.querySelector("[data-question-list]");

    if (!list) {
        return;
    }

    list.addEventListener("click", (event) => {

        const settingsBtn =
            event.target.closest("[data-open-question-settings]");

        if (settingsBtn) {

            openSettingsModal(
                settingsBtn.closest("[data-question-card]")
            );

            return;

        }

        const duplicateBtn =
            event.target.closest("[data-duplicate-question]");

        if (duplicateBtn) {

            duplicateQuestionAction(
                duplicateBtn.closest("[data-question-card]").dataset.questionId
            );

            return;

        }

        const deleteBtn =
            event.target.closest("[data-delete-question]");

        if (deleteBtn) {

            state.deleteQuestionId =
                deleteBtn.closest("[data-question-card]").dataset.questionId;

            modals.delete.open();

            return;

        }

        const addChoiceBtn =
            event.target.closest("[data-add-choice-inline]");

        if (addChoiceBtn) {

            const card =
                addChoiceBtn.closest("[data-question-card]");

            card
                .querySelector("[data-choices-list]")
                ?.appendChild(
                    createChoiceRowElement(card.dataset.questionId)
                );

            queueChoicesSave(card);

            return;

        }

        const removeChoiceBtn =
            event.target.closest("[data-remove-choice]");

        if (removeChoiceBtn) {

            const card =
                removeChoiceBtn.closest("[data-question-card]");

            removeChoiceBtn.closest("[data-choice-row]")?.remove();

            queueChoicesSave(card);

            return;

        }

        const card =
            event.target.closest("[data-question-card]");

        if (card) {
            setActiveQuestion(card);
        }

    });

    list.addEventListener("input", (event) => {

        const textarea =
            event.target.closest("[data-question-text]");

        if (textarea) {

            const card =
                textarea.closest("[data-question-card]");

            queueQuestionSave(
                card.dataset.questionId,
                { question_text: textarea.value }
            );

            return;

        }

        const choiceText =
            event.target.closest("[data-choice-text]");

        if (choiceText) {

            queueChoicesSave(
                choiceText.closest("[data-question-card]")
            );

        }

    });

    list.addEventListener("change", (event) => {

        const choiceCorrect =
            event.target.closest("[data-choice-correct]");

        if (choiceCorrect) {

            queueChoicesSave(
                choiceCorrect.closest("[data-question-card]")
            );

        }

    });

}

function createChoiceRowElement(questionId, text = "", isCorrect = false) {

    const row =
        document.createElement("div");

    row.className =
        "flex items-center gap-2";

    row.dataset.choiceRow = "";

    row.innerHTML = `
        <input
            type="radio"
            data-choice-correct
            name="correct-choice-${questionId}"
            class="h-4 w-4 shrink-0 accent-[#0040A6]"
            ${isCorrect ? "checked" : ""}>

        <input
            type="text"
            data-choice-text
            value="${escapeHtml(text)}"
            placeholder="Choice text"
            class="flex-1 rounded-lg border border-gray-200 px-3 py-1.5 text-sm outline-none transition focus:border-[#0040A6]">

        <button
            type="button"
            data-remove-choice
            class="shrink-0 rounded-lg p-1 text-gray-300 transition hover:bg-red-50 hover:text-red-500">

            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>

        </button>
    `;

    return row;

}

function collectChoices(card) {

    return Array.from(
        card.querySelectorAll("[data-choice-row]")
    ).map((row) => ({
        text: row.querySelector("[data-choice-text]")?.value ?? "",
        is_correct: row.querySelector("[data-choice-correct]")?.checked ?? false,
    }));

}

function queueChoicesSave(card) {

    queueQuestionSave(
        card.dataset.questionId,
        { choices: collectChoices(card) }
    );

}

function queueQuestionSave(questionId, partialPayload, delay = 600) {

    const existing =
        pendingPayloads.get(questionId) || {};

    pendingPayloads.set(
        questionId,
        { ...existing, ...partialPayload }
    );

    clearTimeout(saveTimers.get(questionId));

    updateSaveStatus("saving");

    const timer = setTimeout(async () => {

        const payload =
            pendingPayloads.get(questionId);

        pendingPayloads.delete(questionId);

        try {

            await updateQuestion(
                state.offeringId,
                questionId,
                payload
            );

            updateSaveStatus("saved");

        } catch (error) {

            console.error(error);

            updateSaveStatus("error");

        }

    }, delay);

    saveTimers.set(questionId, timer);

}

function updateSaveStatus(status) {

    const indicator =
        document.querySelector("[data-builder-save-indicator]");

    const message =
        document.querySelector("[data-builder-save-message]");

    if (!indicator || !message) {
        return;
    }

    indicator.className = "h-2 w-2 rounded-full";

    if (status === "saving") {

        indicator.classList.add("bg-orange-500");

        message.textContent = "Saving...";

    } else if (status === "error") {

        indicator.classList.add("bg-red-500");

        message.textContent = "Save failed";

    } else {

        indicator.classList.add("bg-emerald-500");

        message.textContent = "All changes saved";

    }

}

/*
|--------------------------------------------------------------------------
| Duplicate / Delete
|--------------------------------------------------------------------------
*/

async function duplicateQuestionAction(questionId) {

    try {

        const result =
            await duplicateQuestion(state.offeringId, questionId);

        pendingActiveId = result.question.id;

        await state.refresh();

    } catch (error) {

        console.error(error);

    }

}

function bindDeleteModal() {

    document
        .querySelector("[data-confirm-delete-question]")
        ?.addEventListener("click", async () => {

            if (!state.deleteQuestionId) {
                return;
            }

            try {

                await deleteQuestion(
                    state.offeringId,
                    state.deleteQuestionId
                );

                state.deleteQuestionId = null;

                modals.delete.close();

                await state.refresh();

            } catch (error) {

                console.error(error);

            }

        });

}

/*
|--------------------------------------------------------------------------
| Settings Modal (Points / Required)
|--------------------------------------------------------------------------
*/

function openSettingsModal(card) {

    const pointsBadge =
        card.querySelector("[data-question-points-badge]");

    const requiredBadge =
        card.querySelector("[data-question-required-badge]");

    document.querySelector("[data-question-settings-id]").value =
        card.dataset.questionId;

    document.querySelector("[data-question-settings-points]").value =
        parseInt(pointsBadge?.textContent, 10) || 0;

    document.querySelector("[data-question-settings-required]").checked =
        !requiredBadge?.classList.contains("hidden");

    modals.settings.open();

}

function bindSettingsModal() {

    const form =
        document.querySelector("#question-settings-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const questionId =
            document.querySelector("[data-question-settings-id]")?.value;

        if (!questionId) {
            return;
        }

        const points =
            Number(
                document.querySelector("[data-question-settings-points]")?.value || 0
            );

        const isRequired =
            document.querySelector("[data-question-settings-required]")?.checked ?? false;

        try {

            await updateQuestion(
                state.offeringId,
                questionId,
                { points, is_required: isRequired }
            );

            modals.settings.close();

            pendingActiveId = questionId;

            await state.refresh();

        } catch (error) {

            console.error(error);

        }

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

    div.textContent = value ?? "";

    return div.innerHTML;

}
