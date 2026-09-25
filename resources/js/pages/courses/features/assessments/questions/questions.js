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
    clearAllQuestions,
} from "../api.js";

import {
    renderAcceptedAnswersEditor,
    collectAcceptedAnswers,
    handleAcceptedAnswersEvent,
    setCaseSensitiveBadge,
} from "./accepted-answers.js";

const state = {

    offeringId: null,
    assessmentId: null,
    refresh: null,

    deleteQuestionId: null,

};

let modals = {};

const pendingPayloads = new Map();
const saveTimers = new Map();

const PREVIEW_LABELS = {
    "Short Answer": "Students will type a short answer here.",
    "Essay": "Students will write a longer response here.",
    "Discussion": "Students will post a discussion response here.",
    "File Upload": "Students will upload a file here.",
};

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

        clearAll: bindModal({
            modal: "#questions-clear-all-modal",
            closeButtons: "[data-close-questions-clear-all-modal]",
        }),

    };

    bindPalette();
    bindCanvasDragDrop();
    bindCardActions();
    bindSettingsModal();
    bindDeleteModal();
    bindClearAllModal();

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

                isDraggingQuestionItem = true;

                lastDropTargetId = END_DROP_TARGET;

            });

            item.addEventListener("dragend", () => {

                isDraggingQuestionItem = false;

                if (currentQuestionList) {
                    removeDropIndicator(currentQuestionList);
                }

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

        const list =
            document.querySelector("[data-question-list]");

        const card =
            createQuestionCardElement(result.question);

        const beforeCard =
            beforeQuestionId
                ? list.querySelector(`[data-question-card][data-question-id="${beforeQuestionId}"]`)
                : null;

        if (beforeCard) {
            list.insertBefore(card, beforeCard);
        } else {
            list.appendChild(card);
        }

        renumberQuestions(list);
        toggleEmptyState();
        updateQuestionCountBadge();

        if (beforeCard) {

            // The card landed wherever it was dropped, but the server
            // still created it at the end of the list (order_index) —
            // persist the DOM order back so a later reload matches.
            const ids =
                Array.from(
                    list.querySelectorAll("[data-question-card]")
                ).map((c) => c.dataset.questionId);

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

        setActiveQuestion(card);

        card.querySelector("[data-question-text]")?.focus();

    } catch (error) {

        console.error(error);

    }

}

/*
|--------------------------------------------------------------------------
| Canvas Drag & Drop (new questions + reordering)
|--------------------------------------------------------------------------
*/

// The list currently being dragged over — kept up to date on every
// bindCanvasDragDrop() call (once per builder load) and read by the
// document-level handlers below, which are bound only once (see
// documentDragListenersBound) and outlive any single list element.
let currentQuestionList = null;

// True only while a palette item or question card of *this* feature is
// being dragged — guards the document-level listeners so they don't
// hijack unrelated drags happening elsewhere on the page (a file, a link,
// some other widget's own drag-and-drop).
let isDraggingQuestionItem = false;

let documentDragListenersBound = false;

function bindCanvasDragDrop() {

    const list =
        document.querySelector("[data-question-list]");

    if (!list) {
        return;
    }

    currentQuestionList = list;

    // Bound on the document, not the canvas container — the container is
    // only ever as tall as its content, so dragging past its edge (below
    // the last card, or anywhere else outside it) would leave dragover
    // with nothing to fire on, and the indicator would get stuck instead
    // of tracking the pointer. Bound once, ever: the document itself never
    // gets replaced across builder reloads the way [data-question-list]
    // does, so re-adding these on every init would stack duplicates.
    if (!documentDragListenersBound) {

        document.addEventListener("dragover", handleDocumentDragOver);
        document.addEventListener("drop", handleDocumentDrop);

        documentDragListenersBound = true;

    }

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

        isDraggingQuestionItem = true;

        // Fresh drag, fresh hysteresis — otherwise a target locked in from
        // a previous drag could bias the very first dragover of this one.
        lastDropTargetId = END_DROP_TARGET;

        // Deferred a frame so the browser snapshots the drag-ghost image
        // before the opacity change applies — otherwise the dragged
        // preview itself looks faded instead of just the card left behind.
        requestAnimationFrame(() => {
            card.classList.add("opacity-40");
        });

    });

    list.addEventListener("dragend", (event) => {

        const card =
            event.target.closest("[data-question-card]");

        if (card) {

            card.classList.remove("opacity-40");

            card.draggable = false;

        }

        isDraggingQuestionItem = false;

        removeDropIndicator(currentQuestionList);

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

function handleDocumentDragOver(event) {

    if (!isDraggingQuestionItem || !currentQuestionList) {
        return;
    }

    event.preventDefault();

    const target =
        getDropTarget(currentQuestionList, event.clientY);

    showDropIndicator(currentQuestionList, target);

}

async function handleDocumentDrop(event) {

    if (!isDraggingQuestionItem || !currentQuestionList) {
        return;
    }

    event.preventDefault();

    const list = currentQuestionList;

    const target =
        getDropTarget(list, event.clientY);

    removeDropIndicator(list);

    isDraggingQuestionItem = false;

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

        pulseSnap(draggedCard);

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

}

// Sentinel for "no card, drop at the end" — a real element can never equal
// this, so it's safe to compare directly against lastDropTargetId.
const END_DROP_TARGET = "__end__";

// The currently "locked in" drop position for the drag in progress. Reset
// on every dragstart (see bindCanvasDragDrop).
let lastDropTargetId = END_DROP_TARGET;

// How much further the pointer has to travel past a card's midpoint
// before the drop target is allowed to change — without this, hovering
// with the mouse sitting right on a boundary flickers the indicator back
// and forth on every dragover tick.
const DROP_TARGET_HYSTERESIS_PX = 12;

function getDropTarget(list, clientY) {

    const cards =
        Array.from(
            list.querySelectorAll("[data-question-card]:not(.opacity-40)")
        );

    for (let index = 0; index < cards.length; index++) {

        const card = cards[index];

        const rect =
            card.getBoundingClientRect();

        const midpoint =
            rect.top + rect.height / 2;

        const isCurrentTarget =
            card.dataset.questionId === lastDropTargetId;

        // The end-of-list drop point isn't tied to a card, so it needs its
        // own bias: once locked onto "end" (the blue line after the last
        // card), leaving it requires the pointer to clearly move back above
        // the last card's midpoint — not just tick past it — so a drag
        // that's gone far below the list stays snapped to the end no
        // matter how far past the bottom the pointer wanders.
        const isLastCardLeavingEnd =
            index === cards.length - 1 && lastDropTargetId === END_DROP_TARGET;

        let threshold = midpoint;

        if (isCurrentTarget) {
            threshold = midpoint + DROP_TARGET_HYSTERESIS_PX;
        } else if (isLastCardLeavingEnd) {
            threshold = midpoint - DROP_TARGET_HYSTERESIS_PX;
        }

        if (clientY < threshold) {

            lastDropTargetId = card.dataset.questionId;

            return card;

        }

    }

    lastDropTargetId = END_DROP_TARGET;

    return null;

}

function showDropIndicator(list, beforeCard) {

    let indicator =
        list.querySelector("[data-drop-indicator]");

    const isNewIndicator = !indicator;

    if (!indicator) {

        indicator =
            document.createElement("div");

        indicator.dataset.dropIndicator = "";

        indicator.className =
            "h-1 origin-left scale-x-95 rounded-full bg-[#0040A6] opacity-80 shadow-[0_0_0_3px_rgba(0,64,166,0.15)] transition-opacity duration-150 ease-out";

    }

    // dragover fires continuously while hovering the same gap — skip the
    // reinsert when the indicator is already exactly where it needs to be,
    // otherwise every tick forces a reflow and the line visibly jitters.
    const alreadyInPlace =
        beforeCard
            ? indicator.nextElementSibling === beforeCard
            : indicator === list.lastElementChild;

    if (alreadyInPlace) {
        return;
    }

    // FLIP the move: capture where the bar visually was, reposition it in
    // the DOM (an instant jump on its own), then animate from the old spot
    // to the new one so it reads as a smooth slide instead of a teleport.
    const previousRect =
        isNewIndicator ? null : indicator.getBoundingClientRect();

    indicator.style.transition = "none";
    indicator.style.transform = "";

    if (beforeCard) {
        list.insertBefore(indicator, beforeCard);
    } else {
        list.appendChild(indicator);
    }

    if (isNewIndicator) {

        requestAnimationFrame(() => {
            indicator.style.transition = "";
            indicator.classList.remove("scale-x-95", "opacity-80");
        });

        return;

    }

    const newRect =
        indicator.getBoundingClientRect();

    const deltaY =
        previousRect.top - newRect.top;

    if (Math.abs(deltaY) < 1) {
        indicator.style.transition = "";
        return;
    }

    indicator.style.transform = `translateY(${deltaY}px)`;

    requestAnimationFrame(() => {
        indicator.style.transition = "transform 150ms ease-out";
        indicator.style.transform = "translateY(0)";
    });

}

function removeDropIndicator(list) {

    list.querySelector("[data-drop-indicator]")?.remove();

}

// Brief highlight once a dragged card lands in its new spot — a bit of
// tactile "it snapped into place" feedback beyond just the card appearing.
function pulseSnap(card) {

    card.classList.add("ring-2", "ring-[#0040A6]/40");

    setTimeout(() => {
        card.classList.remove("ring-2", "ring-[#0040A6]/40");
    }, 220);

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

            c.classList.add("border-gray-100");

        });

    if (card) {

        card.classList.remove("border-gray-100");

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

        // Short Answer answer key: add / remove an accepted answer.
        const changedAnswers =
            handleAcceptedAnswersEvent(event);

        if (changedAnswers) {

            queueAcceptedAnswersSave(changedAnswers);

            setActiveQuestion(changedAnswers.closest("[data-question-card]"));

            return;

        }

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
                    createChoiceRowElement(
                        card.dataset.questionId,
                        "",
                        false,
                        card.dataset.questionAllowMultipleAnswers === "1"
                    )
                );

            applyChoiceCorrectStyles(card);

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

        const changedAnswers =
            handleAcceptedAnswersEvent(event);

        if (changedAnswers) {

            queueAcceptedAnswersSave(changedAnswers);

            return;

        }

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

        const pointsInput =
            event.target.closest("[data-question-points-input]");

        if (pointsInput) {

            const card =
                pointsInput.closest("[data-question-card]");

            queueQuestionSave(
                card.dataset.questionId,
                { points: Number(pointsInput.value || 0) }
            );

            updateQuestionCountBadge();

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

            const card =
                choiceCorrect.closest("[data-question-card]");

            applyChoiceCorrectStyles(card);

            queueChoicesSave(card);

        }

    });

}

function createChoiceRowElement(questionId, text = "", isCorrect = false, isMultiple = false) {

    const row =
        document.createElement("div");

    row.className =
        `flex items-center gap-2 rounded-lg border px-2.5 py-1.5 transition-colors ${isCorrect ? "border-emerald-300 bg-emerald-50" : "border-gray-200"}`;

    row.dataset.choiceRow = "";

    row.innerHTML = `
        <input
            type="${isMultiple ? "checkbox" : "radio"}"
            data-choice-correct
            ${isMultiple ? "" : `name="correct-choice-${questionId}"`}
            class="h-4 w-4 shrink-0 accent-emerald-600"
            ${isCorrect ? "checked" : ""}>

        <input
            type="text"
            data-choice-text
            value="${escapeHtml(text)}"
            placeholder="Choice text"
            class="min-w-0 flex-1 border-0 bg-transparent px-1 py-0.5 text-sm outline-none transition ${isCorrect ? "font-semibold text-emerald-700" : "text-gray-700"}">

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

function applyChoiceCorrectStyles(card) {

    card
        .querySelectorAll("[data-choice-row]")
        .forEach((row) => {

            const isCorrect =
                row.querySelector("[data-choice-correct]")?.checked ?? false;

            row.classList.toggle("border-emerald-300", isCorrect);
            row.classList.toggle("bg-emerald-50", isCorrect);
            row.classList.toggle("border-gray-200", !isCorrect);

            const textInput =
                row.querySelector("[data-choice-text]");

            textInput?.classList.toggle("font-semibold", isCorrect);
            textInput?.classList.toggle("text-emerald-700", isCorrect);
            textInput?.classList.toggle("text-gray-700", !isCorrect);

        });

}

function collectChoices(card) {

    return Array.from(
        card.querySelectorAll("[data-choice-row]")
    ).map((row) => ({
        text: row.querySelector("[data-choice-text]")?.value ?? "",
        is_correct: row.querySelector("[data-choice-correct]")?.checked ?? false,
    }));

}

function queueAcceptedAnswersSave(editor) {

    queueQuestionSave(
        editor.closest("[data-question-card]").dataset.questionId,
        { accepted_answers: collectAcceptedAnswers(editor) }
    );

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

        const list =
            document.querySelector("[data-question-list]");

        const originalCard =
            list.querySelector(`[data-question-card][data-question-id="${questionId}"]`);

        const newCard =
            createQuestionCardElement(result.question);

        if (originalCard?.nextElementSibling) {
            list.insertBefore(newCard, originalCard.nextElementSibling);
        } else {
            list.appendChild(newCard);
        }

        renumberQuestions(list);
        toggleEmptyState();
        updateQuestionCountBadge();

        setActiveQuestion(newCard);

        newCard.querySelector("[data-question-text]")?.focus();

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

                document
                    .querySelector(`[data-question-card][data-question-id="${state.deleteQuestionId}"]`)
                    ?.remove();

                const list =
                    document.querySelector("[data-question-list]");

                if (list) {
                    renumberQuestions(list);
                }

                toggleEmptyState();
                updateQuestionCountBadge();

                state.deleteQuestionId = null;

                modals.delete.close();

            } catch (error) {

                console.error(error);

            }

        });

}

function bindClearAllModal() {

    document
        .querySelector("[data-clear-all-questions]")
        ?.addEventListener("click", () => {
            modals.clearAll?.open();
        });

    document
        .querySelector("[data-confirm-clear-all-questions]")
        ?.addEventListener("click", async () => {

            try {

                await clearAllQuestions(
                    state.offeringId,
                    state.assessmentId
                );

                document
                    .querySelectorAll("[data-question-card]")
                    .forEach((card) => card.remove());

                toggleEmptyState();
                updateQuestionCountBadge();

                modals.clearAll?.close();

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

    const pointsInput =
        card.querySelector("[data-question-points-input]");

    const requiredBadge =
        card.querySelector("[data-question-required-badge]");

    document.querySelector("[data-question-settings-id]").value =
        card.dataset.questionId;

    document.querySelector("[data-question-settings-points]").value =
        parseFloat(pointsInput?.value) || 0;

    document.querySelector("[data-question-settings-required]").checked =
        !requiredBadge?.classList.contains("hidden");

    const multipleSection =
        document.querySelector("[data-question-settings-multiple-section]");

    const isMultipleChoiceQuestion =
        card.dataset.questionType === "Multiple Choice";

    multipleSection?.classList.toggle("hidden", !isMultipleChoiceQuestion);
    multipleSection?.classList.toggle("flex", isMultipleChoiceQuestion);

    const allowMultipleCheckbox =
        document.querySelector("[data-question-settings-allow-multiple]");

    const allowMultiple =
        card.dataset.questionAllowMultipleAnswers === "1";

    if (allowMultipleCheckbox) {
        allowMultipleCheckbox.checked = allowMultiple;
    }

    document.querySelector("[data-question-settings-answers-count]").value =
        card.dataset.questionAnswersCount || "2";

    toggleAnswersCountWrapper(allowMultiple);

    // Short Answer: case sensitivity of the answer key (off by default).
    const shortAnswerSection =
        document.querySelector("[data-question-settings-short-answer-section]");

    const isShortAnswer =
        card.dataset.questionType === "Short Answer";

    shortAnswerSection?.classList.toggle("hidden", !isShortAnswer);
    shortAnswerSection?.classList.toggle("flex", isShortAnswer);

    const caseSensitiveCheckbox =
        document.querySelector("[data-question-settings-case-sensitive]");

    if (caseSensitiveCheckbox) {
        caseSensitiveCheckbox.checked = card.dataset.questionCaseSensitive === "1";
    }

    modals.settings.open();

}

function toggleAnswersCountWrapper(isVisible) {

    const wrapper =
        document.querySelector("[data-question-settings-answers-count-wrapper]");

    wrapper?.classList.toggle("hidden", !isVisible);
    wrapper?.classList.toggle("flex", isVisible);

}

function bindSettingsModal() {

    const form =
        document.querySelector("#question-settings-form");

    document
        .querySelector("[data-question-settings-allow-multiple]")
        ?.addEventListener("change", (event) => {
            toggleAnswersCountWrapper(event.target.checked);
        });

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

        const allowMultipleAnswers =
            document.querySelector("[data-question-settings-allow-multiple]")?.checked ?? false;

        const answersCount =
            Number(
                document.querySelector("[data-question-settings-answers-count]")?.value || 2
            );

        const card =
            document.querySelector(`[data-question-card][data-question-id="${questionId}"]`);

        const isShortAnswer =
            card?.dataset.questionType === "Short Answer";

        const caseSensitive =
            document.querySelector("[data-question-settings-case-sensitive]")?.checked ?? false;

        try {

            await updateQuestion(
                state.offeringId,
                questionId,
                {
                    points,
                    is_required: isRequired,
                    allow_multiple_answers: allowMultipleAnswers,
                    answers_count: answersCount,
                    ...(isShortAnswer ? { case_sensitive: caseSensitive } : {}),
                }
            );

            if (card) {

                const pointsInput =
                    card.querySelector("[data-question-points-input]");

                if (pointsInput) {
                    pointsInput.value = formatPointsValue(points);
                }

                card
                    .querySelector("[data-question-required-badge]")
                    ?.classList.toggle("hidden", !isRequired);

                card.dataset.questionAllowMultipleAnswers = allowMultipleAnswers ? "1" : "0";
                card.dataset.questionAnswersCount = String(answersCount);

                updateMultipleBadge(card, allowMultipleAnswers, answersCount);
                rebuildChoicesForMultiple(card, allowMultipleAnswers);

                if (isShortAnswer) {

                    card.dataset.questionCaseSensitive = caseSensitive ? "1" : "0";

                    setCaseSensitiveBadge(
                        card.querySelector("[data-accepted-answers]"),
                        caseSensitive
                    );

                }

                updateQuestionCountBadge();

            }

            modals.settings.close();

        } catch (error) {

            console.error(error);

        }

    });

}

/*
|--------------------------------------------------------------------------
| Card building (create / duplicate / AI-accept all build the DOM node
| directly from the API's JSON response instead of reloading the whole
| builder — the server-rendered partial is only used on the initial page
| load now).
|--------------------------------------------------------------------------
*/

/**
 * Used elsewhere (the AI review tray) to drop a freshly-accepted question
 * straight into the live canvas without reloading the whole builder.
 */
export function insertLiveQuestionCard(question) {

    const list =
        document.querySelector("[data-question-list]");

    if (!list) {
        return null;
    }

    const card =
        createQuestionCardElement(question);

    list.appendChild(card);

    renumberQuestions(list);
    toggleEmptyState();
    updateQuestionCountBadge();

    return card;

}

function createQuestionCardElement(question) {

    const isRequired = isFlagTrue(question.is_required);
    const isMultiple = isFlagTrue(question.allow_multiple_answers);
    const answersCount = question.answers_count ?? 2;

    const card = document.createElement("div");

    card.dataset.questionCard = "";
    card.dataset.questionId = question.id;
    card.dataset.questionType = question.question_type;
    card.dataset.questionAllowMultipleAnswers = isMultiple ? "1" : "0";
    card.dataset.questionAnswersCount = answersCount;
    card.dataset.questionCaseSensitive = isFlagTrue(question.case_sensitive) ? "1" : "0";
    card.draggable = false;

    card.className =
        "rounded-xl border-2 border-gray-100 bg-white p-4 shadow-sm transition";

    const bodyHtml = question.question_type === "Multiple Choice"
        ? `<div class="flex flex-col gap-2" data-choices-list></div>
           <button type="button" data-add-choice-inline class="self-start text-xs font-semibold text-[#0040A6] transition hover:underline">+ Add choice</button>`
        : question.question_type === "Short Answer"
            ? renderAcceptedAnswersEditor(question.accepted_answers || [], isFlagTrue(question.case_sensitive))
            : `<div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-400">${escapeHtml(PREVIEW_LABELS[question.question_type] || "Students will respond here.")}</div>`;

    card.innerHTML = `
        <div class="flex items-center gap-2 border-b border-gray-100 pb-3">

            <button type="button" data-drag-handle title="Drag to reorder" class="cursor-grab rounded p-1 text-gray-400 transition hover:bg-gray-100 active:cursor-grabbing">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                    <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                    <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                    <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
                </svg>
            </button>

            <span class="text-sm font-bold text-gray-400" data-question-number>Q?</span>

            <span class="rounded-full bg-[#0040A6]/10 px-2.5 py-0.5 text-xs font-bold text-[#0040A6]" data-question-type-badge>${escapeHtml(question.question_type)}</span>

            <input type="number" data-question-points-input min="0" step="0.5" value="${formatPointsValue(question.points)}" class="w-14 rounded-lg border border-gray-200 px-1.5 py-0.5 text-xs text-center outline-none transition focus:border-[#0040A6]">
            <span class="text-[10px] text-gray-400">pts</span>

            <span class="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-500 ${isRequired ? "" : "hidden"}" data-question-required-badge>Required</span>

            <span class="rounded-full bg-[#0040A6]/10 px-2 py-0.5 text-[10px] font-bold text-[#0040A6] ${isMultiple ? "" : "hidden"}" data-question-multiple-badge>Select ${answersCount}</span>

            <div class="ml-auto flex shrink-0 items-center gap-1">

                <button type="button" data-open-question-settings title="Question settings" class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>

                <button type="button" data-duplicate-question title="Duplicate question" class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </button>

                <button type="button" data-delete-question title="Delete question" class="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2m3 0v12a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12zM10 11v6M14 11v6" />
                    </svg>
                </button>

            </div>

        </div>

        <div class="flex flex-col gap-3 pt-3">
            <textarea rows="2" data-question-text placeholder="Type your question..." class="w-full resize-none rounded-lg border-0 bg-transparent px-1 py-1 text-sm font-medium text-[#1F2937] outline-none transition placeholder:text-gray-300 focus:bg-gray-50">${escapeHtml(question.question_text)}</textarea>
            ${bodyHtml}
        </div>
    `;

    if (question.question_type === "Multiple Choice") {

        const choicesList =
            card.querySelector("[data-choices-list]");

        (question.choices || []).forEach((choice) => {

            choicesList?.appendChild(
                createChoiceRowElement(
                    question.id,
                    choice.choice_text ?? choice.text ?? "",
                    isFlagTrue(choice.is_correct),
                    isMultiple
                )
            );

        });

    }

    return card;

}

function updateMultipleBadge(card, allowMultiple, answersCount) {

    const badge =
        card.querySelector("[data-question-multiple-badge]");

    if (!badge) {
        return;
    }

    badge.textContent = `Select ${answersCount}`;

    badge.classList.toggle("hidden", !allowMultiple);

}

function rebuildChoicesForMultiple(card, isMultiple) {

    const list =
        card.querySelector("[data-choices-list]");

    if (!list) {
        return;
    }

    let choices =
        collectChoices(card);

    if (!isMultiple) {

        // A radio group can only ever have one selection anyway — collapse
        // to the first correct choice so switching multi-answer off can't
        // silently leave more than one choice marked correct.
        let keptFirstCorrect = false;

        choices = choices.map((choice) => {

            if (!choice.is_correct) {
                return choice;
            }

            if (keptFirstCorrect) {
                return { ...choice, is_correct: false };
            }

            keptFirstCorrect = true;

            return choice;

        });

    }

    list.innerHTML = "";

    choices.forEach((choice) => {

        list.appendChild(
            createChoiceRowElement(card.dataset.questionId, choice.text, choice.is_correct, isMultiple)
        );

    });

    queueChoicesSave(card);

}

function toggleEmptyState() {

    const list =
        document.querySelector("[data-question-list]");

    const emptyBox =
        document.querySelector("[data-question-list-empty]");

    if (!list || !emptyBox) {
        return;
    }

    const isEmpty =
        list.querySelectorAll("[data-question-card]").length === 0;

    emptyBox.classList.toggle("flex", isEmpty);
    emptyBox.classList.toggle("hidden", !isEmpty);

}

function updateQuestionCountBadge() {

    const badge =
        document.querySelector("[data-question-count-badge]");

    if (!badge) {
        return;
    }

    const cards =
        Array.from(document.querySelectorAll("[data-question-card]"));

    const totalPoints =
        cards.reduce(
            (sum, card) => sum + (parseFloat(card.querySelector("[data-question-points-input]")?.value) || 0),
            0
        );

    badge.textContent =
        `${cards.length} question${cards.length === 1 ? "" : "s"} · ${totalPoints} pts total`;

}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

// MySQLi returns BOOLEAN columns as the strings "0"/"1" (not JS booleans),
// and "0" is truthy in JS — a plain `!!value` check on a fresh API response
// treats every unset flag as true. Never trust a raw truthy check on a
// value that might have come straight from the database this way.
function isFlagTrue(value) {
    return value === true || value === 1 || value === "1";
}

function formatPointsValue(points) {

    const num =
        Number(points) || 0;

    return num % 1 === 0 ? String(num) : String(parseFloat(num.toFixed(2)));

}

function escapeHtml(value) {

    const div =
        document.createElement("div");

    div.textContent = value ?? "";

    return div.innerHTML;

}
