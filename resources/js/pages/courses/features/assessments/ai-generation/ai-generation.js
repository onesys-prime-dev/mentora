/*
|--------------------------------------------------------------------------
| AI Question Generation — review tray
|--------------------------------------------------------------------------
*/

import { bindModal } from "../modals/index.js";

import {
    startGeneration,
    fetchNextChunk,
    fetchGenerationBatch,
    fetchPendingGeneration,
    cancelGenerationBatch,
    updateGeneratedQuestion,
    acceptGeneratedQuestion,
    discardGeneratedQuestion,
    acceptAllGeneratedQuestions,
} from "../api.js";

import { insertLiveQuestionCard } from "../questions/questions.js";

import {
    renderAcceptedAnswersEditor,
    collectAcceptedAnswers,
    handleAcceptedAnswersEvent,
    setCaseSensitiveBadge,
} from "../questions/accepted-answers.js";

let state = {
    offeringId: null,
    assessmentId: null,
};

// Module-level, not per-call — reloading the builder re-fetches HTML and
// swaps #course-content without reloading this module, so this survives it
// and lets the tray pick back up where it left off.
let activeBatchId = null;

// Set on every renderBatch() call — used to size the tray's progress-ring
// estimate for the *next* chunk request before that request's own response
// tells us anything.
let latestBatch = null;

let modal = null;
let settingsModal = null;

let typeMixValidate = null;

let submitProgressRing = null;
let trayProgressRing = null;

const saveTimers = new Map();
const pendingPayloads = new Map();

const SIMILARITY_FLAG_THRESHOLD = 0.4;

// How often a background job is asked how far it has got.
const JOB_POLL_MS = 3000;
const JOB_POLL_LIMIT = 400;

// Mirrors python-question-builder-service/app/config.py MAX_QUESTIONS_PER_CALL
// — used only to scale the progress-ring's time estimate, never sent to or
// enforced against the server.
const MAX_QUESTIONS_PER_CALL = 15;

// ~160 tokens/question (config.TOKENS_PER_QUESTION) at ~70 tok/s effective
// decode speed on this hardware, rounded up for schema/prefill overhead —
// a rough dial for how fast the progress ring *feels* like it should fill,
// not a real ETA from the server.
const AVERAGE_SECONDS_PER_QUESTION = 4;

export function initializeAiGeneration(offeringId, assessmentId) {

    state = { offeringId, assessmentId };

    modal = bindModal({
        modal: "#ai-generate-modal",
        closeButtons: "[data-close-ai-generate-modal]",
    });

    bindOpenButton();
    bindItemSelection();
    typeMixValidate = bindTypeMixValidation();
    bindForm();
    bindTrayActions();
    bindAiSettingsModal();
    initTrayProgressRing();

    bindJobControls();

    if (activeBatchId) {
        resumeBatch(activeBatchId);
    } else {
        restorePending();
    }

}

/*
|--------------------------------------------------------------------------
| Background jobs: follow, cancel, restore after a reload
|--------------------------------------------------------------------------
*/

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

export function jobIsRunning(result) {
    return !!result.job && ["queued", "running"].includes(result.job.status);
}

export function jobLabel(job) {

    const parts = [job.note || "Working on your questions"];

    if (job.of > 0 && job.stage === "write") {
        parts.push(`${job.done} of ${job.of}`);
    }

    if (job.waiting_seconds) {
        parts.push(`waiting about ${job.waiting_seconds} s for the free allowance`);
    }

    return parts.join(" - ");

}

function renderJobError(message) {

    const box = document.querySelector("[data-ai-tray-job-error]");
    const text = document.querySelector("[data-ai-tray-job-error-text]");

    if (!box) {
        return;
    }

    if (text) {
        text.textContent = message || "";
    }

    box.classList.toggle("hidden", !message);
    box.classList.toggle("flex", !!message);

}

async function followJob(batchId, first) {

    let result = first;
    let polls = 0;

    while (jobIsRunning(result) && polls < JOB_POLL_LIMIT) {

        const label = document.querySelector("[data-ai-tray-generating-label]");

        if (label) {
            label.textContent = jobLabel(result.job);
        }

        await wait(JOB_POLL_MS);

        // The tray was dismissed or another batch took over.
        if (activeBatchId !== batchId) {
            return;
        }

        try {
            result = await fetchGenerationBatch(state.offeringId, batchId);
        } catch (error) {
            console.error(error);
            polls += 5; // a flaky network gives up sooner, but not at once
        }

        polls++;

    }

    renderBatch(result);

    if (result.job?.status === "failed") {
        renderJobError(result.job.message);
    } else if (result.job?.status === "cancelled") {
        renderJobError("Generation was cancelled.");
    } else if (jobIsRunning(result)) {
        renderJobError("This is taking longer than expected. Your questions will appear here when they are ready.");
    } else {
        renderJobError("");
    }

}

function bindJobControls() {

    const cancel = document.querySelector("[data-ai-tray-cancel]");

    if (!cancel || cancel.dataset.bound) {
        return;
    }

    cancel.dataset.bound = "1";

    cancel.addEventListener("click", async () => {

        if (!activeBatchId) {
            return;
        }

        cancel.disabled = true;

        try {
            const result = await cancelGenerationBatch(state.offeringId, activeBatchId);

            renderBatch(result);
            renderJobError("Generation was cancelled.");
        } catch (error) {
            console.error(error);
            renderJobError("Could not cancel. Please try again.");
        } finally {
            cancel.disabled = false;
        }

    });

}

async function restorePending() {

    if (!state.assessmentId) {
        return;
    }

    try {

        const result = await fetchPendingGeneration(state.offeringId, state.assessmentId);

        if (!result.success || !result.pending) {
            return;
        }

        activeBatchId = result.pending.batch.id;

        showTray();

        renderBatch(result.pending);

        if (jobIsRunning(result.pending)) {
            setGeneratingIndicator(true);
            await followJob(activeBatchId, result.pending);
            setGeneratingIndicator(false);
        }

    } catch (error) {

        console.error(error);

    }

}

/*
|--------------------------------------------------------------------------
| Modal open / source tabs
|--------------------------------------------------------------------------
*/

function bindOpenButton() {

    document
        .querySelector("[data-open-ai-generate]")
        ?.addEventListener("click", () => {

            document.querySelector("[data-ai-generate-error]")?.classList.add("hidden");

            modal?.open();

        });

}

/**
 * The ids of the ticked learning items, in the order they are listed.
 *
 * @param {ParentNode} root
 * @returns {number[]}
 */
export function selectedItemIds(root = document) {

    return Array.from(root.querySelectorAll("[data-ai-item]"))
        .filter((box) => box.checked)
        .map((box) => Number(box.value));

}

/**
 * "3 of 5 selected", with the words for none and all.
 *
 * @param {number} selected
 * @param {number} total
 * @returns {string}
 */
export function selectionSummary(selected, total) {

    if (total === 0) {
        return "Nothing to choose from";
    }

    if (selected === 0) {
        return `None of ${total} selected`;
    }

    return `${selected} of ${total} selected`;

}

function bindItemSelection() {

    const boxes = () => Array.from(document.querySelectorAll("[data-ai-item]"));
    const summary = document.querySelector("[data-ai-selected-summary]");

    function refresh() {

        if (summary) {
            summary.textContent = selectionSummary(selectedItemIds().length, boxes().length);
        }

        // The Generate button follows the selection.
        typeMixValidate?.();

    }

    function setAll(checked) {

        boxes().forEach((box) => {
            box.checked = checked;
        });

        refresh();

    }

    boxes().forEach((box) => box.addEventListener("change", refresh));

    document.querySelector("[data-ai-select-all]")?.addEventListener("click", () => setAll(true));
    document.querySelector("[data-ai-select-none]")?.addEventListener("click", () => setAll(false));

}

/*
|--------------------------------------------------------------------------
| Type-mix validation — the per-type counts must sum to exactly the
| "how many questions" total, otherwise the request is ambiguous (which
| type should the remainder be?). Real-time, not just on submit, so the
| teacher sees the mismatch as they type instead of after clicking Generate.
|--------------------------------------------------------------------------
*/

function bindTypeMixValidation() {

    const form = document.querySelector("#ai-generate-form");

    if (!form) {
        return () => true;
    }

    const countInput = form.querySelector('[name="count"]');
    const typeInputs = form.querySelectorAll("[data-ai-type-mix-count]");
    const statusEl = form.querySelector("[data-ai-type-mix-status]");

    function validate() {

        const target = Number(countInput?.value || 0);

        const sum = Array.from(typeInputs).reduce(
            (total, input) => total + Number(input.value || 0),
            0
        );

        const isValid = target > 0 && sum === target;

        // There has to be something to read, and at least one item ticked.
        const sourceIsUsable = form.dataset.aiSourceUsable === "1" && selectedItemIds().length > 0;

        if (statusEl) {

            statusEl.textContent = isValid
                ? `${sum} of ${target} questions allocated`
                : `${sum} of ${target} questions allocated — must add up to exactly ${target}`;

            statusEl.classList.toggle("text-emerald-600", isValid);
            statusEl.classList.toggle("text-red-500", !isValid);

        }

        const submitButton = document.querySelector("[data-ai-generate-submit]");

        if (submitButton) {
            submitButton.disabled = !isValid || !sourceIsUsable;
        }

        return isValid && sourceIsUsable;

    }

    countInput?.addEventListener("input", validate);

    typeInputs.forEach((input) => {
        input.addEventListener("input", validate);
    });

    validate();

    return validate;

}

/*
|--------------------------------------------------------------------------
| Form submit — start a batch
|--------------------------------------------------------------------------
*/

function bindForm() {

    const form = document.querySelector("#ai-generate-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        if (typeMixValidate && !typeMixValidate()) {
            return;
        }

        const submitButton = document.querySelector("[data-ai-generate-submit]");

        const submitIcon = submitButton.querySelector("[data-ai-generate-submit-icon]");
        const submitLabel = submitButton.querySelector("[data-ai-generate-submit-label]");

        const errorBox = document.querySelector("[data-ai-generate-error]");

        errorBox.classList.add("hidden");

        const formData = new FormData();

        formData.set("assessment_id", state.assessmentId);
        formData.set("count", form.querySelector('[name="count"]').value || "10");

        // The unit is the quiz's own, decided on the server; only the chosen
        // items are sent.
        formData.set("learning_item_ids", JSON.stringify(selectedItemIds()));

        const pointsField = form.querySelector('[name="points_per_question"]');

        if (pointsField.value !== "") {
            formData.set("points_per_question", pointsField.value);
        }

        const typeMix = {};

        form.querySelectorAll("[data-ai-type-mix-count]").forEach((input) => {

            const count = Number(input.value || 0);

            if (count > 0) {
                typeMix[input.dataset.typeKey] = count;
            }

        });

        formData.set("type_mix", JSON.stringify(typeMix));

        const requestedTotal = Number(formData.get("count") || 0);
        const firstChunkCount = Math.min(requestedTotal || 1, MAX_QUESTIONS_PER_CALL);

        submitButton.disabled = true;
        submitLabel.textContent = "Generating...";

        if (!submitProgressRing) {
            submitProgressRing = createProgressRing("h-4 w-4");
        }

        submitIcon.innerHTML = "";
        submitIcon.appendChild(submitProgressRing.element);
        submitIcon.classList.remove("hidden");

        const ticker = startProgressTicker(
            submitProgressRing.setProgress,
            estimateSecondsForCount(firstChunkCount)
        );

        try {

            const result = await startGeneration(state.offeringId, formData);

            if (!result.success) {
                throw new Error(result.message || "Unable to start generation.");
            }

            ticker.finish();

            modal?.close();

            activeBatchId = result.batch.id;

            showTray();

            renderBatch(result);

            updateSkippedSourcesBanner(result.skipped_sources);

            if (result.batch.status === "generating") {
                await continueGenerating(result.batch.id);
            }

        } catch (error) {

            ticker.stop();

            console.error(error);

            errorBox.textContent = error.message || "Something went wrong.";
            errorBox.classList.remove("hidden");

        } finally {

            submitIcon.classList.add("hidden");
            submitIcon.innerHTML = "";
            submitLabel.textContent = "Generate";

            // Re-run validation rather than force-enabling — the button
            // should only come back if the type-mix is still valid.
            typeMixValidate?.();

        }

    });

}

async function continueGenerating(batchId) {

    setGeneratingIndicator(true);

    let status = "generating";

    // Bounded — a stalled model (or a source that's run dry) shouldn't
    // spin the tab forever.
    let guard = 0;

    while (status === "generating" && guard < 20) {

        guard++;

        const remaining = latestBatch
            ? Math.max(latestBatch.requested_count - latestBatch.generated_count, 1)
            : 5;

        const ticker = trayProgressRing
            ? startProgressTicker(
                trayProgressRing.setProgress,
                estimateSecondsForCount(Math.min(remaining, MAX_QUESTIONS_PER_CALL))
            )
            : null;

        try {

            const result = await fetchNextChunk(state.offeringId, batchId);

            // A background batch is filled by the worker: follow its job
            // instead of asking for more chunks.
            if (result.job) {
                ticker?.stop();
                renderBatch(result);
                await followJob(batchId, result);
                break;
            }

            ticker?.finish();

            renderBatch(result);

            status = result.batch.status;

        } catch (error) {

            ticker?.stop();

            console.error(error);
            break;

        }

    }

    setGeneratingIndicator(false);

}

async function resumeBatch(batchId) {

    try {

        const result = await fetchGenerationBatch(state.offeringId, batchId);

        if (!result.success || (result.questions.length === 0 && !jobIsRunning(result))) {
            activeBatchId = null;
            return;
        }

        showTray();

        renderBatch(result);

        if (result.batch.status === "generating") {
            await continueGenerating(batchId);
        }

    } catch (error) {

        console.error(error);
        activeBatchId = null;

    }

}

/*
|--------------------------------------------------------------------------
| Tray rendering
|--------------------------------------------------------------------------
*/

function showTray() {

    const tray = document.querySelector("[data-ai-review-tray]");

    tray?.classList.remove("hidden");
    tray?.classList.add("flex");

    renderJobError("");

    // A stale banner from a previous, already-dismissed batch shouldn't
    // reappear when a fresh generation starts — renderBatch() will show it
    // again soon enough if the new batch also stops early.
    updateStoppedEarlyBanner(false, null);
    updateSkippedSourcesBanner([]);

}

// Shown once, from the start response — it isn't stored on the batch, so
// resuming a batch after a reload won't bring it back.
function updateSkippedSourcesBanner(skippedSources) {

    const banner = document.querySelector("[data-ai-tray-skipped-sources]");

    if (!banner) {
        return;
    }

    const names = skippedSources || [];

    banner.classList.toggle("hidden", names.length === 0);
    banner.classList.toggle("flex", names.length > 0);

    const list = banner.querySelector("[data-ai-tray-skipped-sources-list]");

    if (list) {
        list.textContent = names.map((name) => `"${name}"`).join(", ");
    }

}

function hideTray() {

    const tray = document.querySelector("[data-ai-review-tray]");

    tray?.classList.add("hidden");
    tray?.classList.remove("flex");

    activeBatchId = null;
    latestBatch = null;

}

function setGeneratingIndicator(isGenerating) {

    const indicator = document.querySelector("[data-ai-tray-generating]");

    indicator?.classList.toggle("hidden", !isGenerating);
    indicator?.classList.toggle("flex", isGenerating);

}

function initTrayProgressRing() {

    // Re-created on every init, not just once — this SPA swaps in a fresh
    // #course-content DOM on every refresh(), so a ring instance from a
    // previous load would be appending into a detached slot that's no
    // longer on the page.
    const slot = document.querySelector("[data-ai-tray-progress-ring]");

    if (!slot) {
        trayProgressRing = null;
        return;
    }

    trayProgressRing = createProgressRing("h-3.5 w-3.5");

    slot.appendChild(trayProgressRing.element);

}

/*
|--------------------------------------------------------------------------
| Progress ring — a small filling circle driven by real elapsed time
| (not a static "Generating..." string). There's no real progress feed
| from the server for a single chunk call, so this uses an asymptotic
| curve (fast early, slowing as it approaches the estimate) and only ever
| snaps to 100% once the actual response comes back — it never claims
| to be done before the request has actually finished.
|--------------------------------------------------------------------------
*/

function createProgressRing(sizeClass = "h-4 w-4") {

    const circumference = 2 * Math.PI * 6.5;

    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");

    svg.setAttribute("viewBox", "0 0 16 16");
    svg.setAttribute("class", `${sizeClass} -rotate-90`);

    svg.innerHTML = `
        <circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-opacity="0.25" stroke-width="2.5"></circle>
        <circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="${circumference}" stroke-dashoffset="${circumference}"></circle>
    `;

    const fillCircle = svg.lastElementChild;

    return {

        element: svg,

        setProgress(fraction) {

            const clamped = Math.max(0, Math.min(1, fraction));

            fillCircle.setAttribute(
                "stroke-dashoffset",
                String(circumference * (1 - clamped))
            );

        },

    };

}

function estimateSecondsForCount(count) {
    return Math.max(count, 1) * AVERAGE_SECONDS_PER_QUESTION;
}

function startProgressTicker(setProgress, estimatedSeconds) {

    // tau tuned so the ring reaches ~85% right around the estimate, rather
    // than hitting 100% and then stalling if the real call runs long.
    const tauMs = Math.max(estimatedSeconds, 2) * 1000 * 0.6;

    const startedAt = Date.now();

    setProgress(0);

    const intervalId = setInterval(() => {

        const elapsed = Date.now() - startedAt;
        const eased = 1 - Math.exp(-elapsed / tauMs);

        setProgress(Math.min(eased, 0.92));

    }, 100);

    return {

        finish() {
            clearInterval(intervalId);
            setProgress(1);
        },

        stop() {
            clearInterval(intervalId);
        },

    };

}

function renderBatch(result) {

    latestBatch = result.batch;

    const list = document.querySelector("[data-ai-tray-list]");

    if (!list) {
        return;
    }

    const existingIds = new Set(
        Array.from(list.querySelectorAll("[data-ai-question-card]"))
            .map((el) => el.dataset.aiQuestionId)
    );

    result.questions.forEach((question) => {

        if (existingIds.has(String(question.id))) {
            return;
        }

        list.appendChild(createCard(question));

    });

    updateProgress(result.batch);

    updateStoppedEarlyBanner(result.stopped_early, result.batch);

}

function updateProgress(batch) {

    const progress = document.querySelector("[data-ai-tray-progress]");

    if (progress) {
        progress.textContent = `${batch.generated_count} of ${batch.requested_count}`;
    }

}

function updateStoppedEarlyBanner(stoppedEarly, batch) {

    const banner = document.querySelector("[data-ai-tray-stopped-early]");

    if (!banner) {
        return;
    }

    banner.classList.toggle("hidden", !stoppedEarly);
    banner.classList.toggle("flex", !!stoppedEarly);

    if (stoppedEarly) {

        const countLabel = banner.querySelector("[data-ai-tray-stopped-early-count]");

        if (countLabel) {
            countLabel.textContent = `${batch.generated_count} of ${batch.requested_count} questions`;
        }

    }

}

function createCard(question) {

    const card = document.createElement("div");

    card.dataset.aiQuestionCard = "";
    card.dataset.aiQuestionId = question.id;
    card.dataset.aiQuestionType = question.question_type;
    card.dataset.aiIsRequired = isFlagTrue(question.is_required) ? "1" : "0";
    card.dataset.aiAllowMultipleAnswers = isFlagTrue(question.allow_multiple_answers) ? "1" : "0";
    card.dataset.aiAnswersCount = question.answers_count ?? 2;
    card.dataset.aiCaseSensitive = isFlagTrue(question.case_sensitive) ? "1" : "0";

    card.className =
        "flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3";

    const similarityBadge =
        question.similarity_score !== null && question.similarity_score > SIMILARITY_FLAG_THRESHOLD
            ? `<span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700">Similar to source — review before accepting</span>`
            : "";

    // Deterministic sympy cross-check of the marked-correct answer for
    // math questions — the model is not reliable at arithmetic/algebra,
    // this is never a guess (see verification.py).
    const mathCheckBadge =
        question.math_check === "mismatch"
            ? `<span class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-700">⚠ Marked answer looks wrong — verify before accepting</span>`
            : "";

    const isMultipleAnswer = isFlagTrue(question.allow_multiple_answers);

    const multipleBadge =
        `<span data-ai-multiple-badge class="rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-bold text-brand ${isMultipleAnswer ? "" : "hidden"}">Select ${question.answers_count ?? 2}</span>`;

    const choicesHtml = question.question_type === "Multiple Choice"
        ? `<div class="flex flex-col gap-1" data-ai-choices-list>` +
            question.choices.map((choice) => createAiChoiceRowHtml(question.id, choice.text, choice.is_correct, isMultipleAnswer)).join("") +
          `</div>` +
          `<button type="button" data-ai-add-choice class="self-start text-[11px] font-semibold text-brand transition hover:underline">+ Add choice</button>`
        : question.question_type === "Short Answer"
            ? renderAcceptedAnswersEditor(question.accepted_answers || [], isFlagTrue(question.case_sensitive))
            : "";

    card.innerHTML = `
        <div class="flex items-center gap-2">
            <span class="rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-bold text-brand">${escapeHtml(question.question_type)}</span>
            <input type="number" data-ai-points-input min="0" step="0.5" value="${question.points}" class="w-14 rounded-lg border border-gray-200 px-1.5 py-0.5 text-xs text-center outline-none focus:border-brand">
            <span class="text-[10px] text-[#767676]">pts</span>
            ${multipleBadge}
            ${similarityBadge}
            ${mathCheckBadge}
            <div class="ml-auto flex items-center gap-1">
                <button type="button" data-ai-open-settings title="Question settings" class="rounded-lg p-1.5 text-[#767676] transition hover:bg-gray-100 hover:text-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>
                <button type="button" data-ai-discard-question title="Discard" class="rounded-lg p-1.5 text-[#767676] transition hover:bg-red-50 hover:text-red-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <button type="button" data-ai-accept-question class="rounded-xl bg-brand px-3 py-1 text-xs font-semibold text-white transition hover:bg-brand-dark">
                    Accept
                </button>
            </div>
        </div>
        <textarea data-ai-text-input rows="2" class="w-full resize-none rounded-lg border-0 bg-transparent px-1 py-1 text-sm outline-none transition focus:bg-gray-50">${escapeHtml(question.question_text)}</textarea>
        ${choicesHtml}
    `;

    return card;

}

function escapeHtml(value) {

    const div = document.createElement("div");

    div.textContent = value ?? "";

    return div.innerHTML;

}

// MySQLi returns BOOLEAN columns as the strings "0"/"1" (not JS booleans),
// and "0" is truthy in JS — a plain `!!value` check on a fresh API response
// treats every unset flag as true. Never trust a raw truthy check on a
// value that might have come straight from the database this way.
function isFlagTrue(value) {
    return value === true || value === 1 || value === "1";
}

function createAiChoiceRowHtml(questionId, text = "", isCorrect = false, isMultiple = false) {

    return `
        <div
            data-ai-choice-row
            class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 transition-colors ${isCorrect ? "border-emerald-300 bg-emerald-50" : "border-gray-200"}">
            <input
                type="${isMultiple ? "checkbox" : "radio"}"
                data-ai-choice-correct
                ${isMultiple ? "" : `name="ai-correct-choice-${questionId}"`}
                class="h-3.5 w-3.5 shrink-0 accent-emerald-600"
                ${isCorrect ? "checked" : ""}>
            <input
                type="text"
                data-ai-choice-text
                value="${escapeHtml(text)}"
                placeholder="Choice text"
                class="min-w-0 flex-1 border-0 bg-transparent px-1 py-0.5 text-xs outline-none transition ${isCorrect ? "font-semibold text-emerald-700" : "text-gray-600"}">
            <button type="button" data-ai-remove-choice class="shrink-0 rounded-lg p-1 text-gray-300 transition hover:bg-red-50 hover:text-red-500">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
    `;

}

function applyAiChoiceCorrectStyles(card) {

    card
        .querySelectorAll("[data-ai-choice-row]")
        .forEach((row) => {

            const isCorrect =
                row.querySelector("[data-ai-choice-correct]")?.checked ?? false;

            row.classList.toggle("border-emerald-300", isCorrect);
            row.classList.toggle("bg-emerald-50", isCorrect);
            row.classList.toggle("border-gray-200", !isCorrect);

            const textInput = row.querySelector("[data-ai-choice-text]");

            textInput?.classList.toggle("font-semibold", isCorrect);
            textInput?.classList.toggle("text-emerald-700", isCorrect);
            textInput?.classList.toggle("text-gray-600", !isCorrect);

        });

}

function collectAiChoices(card) {

    return Array.from(card.querySelectorAll("[data-ai-choice-row]")).map((row) => ({
        text: row.querySelector("[data-ai-choice-text]")?.value ?? "",
        is_correct: row.querySelector("[data-ai-choice-correct]")?.checked ?? false,
    }));

}

/*
|--------------------------------------------------------------------------
| Tray actions (delegated on the list container)
|--------------------------------------------------------------------------
*/

function bindTrayActions() {

    const list = document.querySelector("[data-ai-tray-list]");

    list?.addEventListener("input", (event) => {

        const card = event.target.closest("[data-ai-question-card]");

        if (!card) {
            return;
        }

        // Short Answer answer key
        const changedAnswers = handleAcceptedAnswersEvent(event);

        if (changedAnswers) {

            queueSave(card.dataset.aiQuestionId, { accepted_answers: collectAcceptedAnswers(changedAnswers) });

            return;

        }

        if (event.target.matches("[data-ai-text-input]")) {

            queueSave(card.dataset.aiQuestionId, { question_text: event.target.value });

        } else if (event.target.matches("[data-ai-points-input]")) {

            queueSave(card.dataset.aiQuestionId, { points: Number(event.target.value || 0) });

        } else if (event.target.matches("[data-ai-choice-text]")) {

            queueSave(card.dataset.aiQuestionId, { choices: collectAiChoices(card) });

        }

    });

    list?.addEventListener("change", (event) => {

        const choiceRadio = event.target.closest("[data-ai-choice-correct]");

        if (!choiceRadio) {
            return;
        }

        const card = event.target.closest("[data-ai-question-card]");

        if (!card) {
            return;
        }

        applyAiChoiceCorrectStyles(card);

        queueSave(card.dataset.aiQuestionId, { choices: collectAiChoices(card) });

    });

    list?.addEventListener("click", async (event) => {

        const card = event.target.closest("[data-ai-question-card]");

        if (!card) {
            return;
        }

        const questionId = card.dataset.aiQuestionId;

        // Short Answer answer key: add / remove an accepted answer.
        const changedAnswers = handleAcceptedAnswersEvent(event);

        if (changedAnswers) {

            queueSave(questionId, { accepted_answers: collectAcceptedAnswers(changedAnswers) });

            return;

        }

        if (event.target.closest("[data-ai-accept-question]")) {

            await flushPendingSave(questionId);

            try {

                const result = await acceptGeneratedQuestion(state.offeringId, questionId);

                card.remove();

                if (result.success && result.question) {
                    insertLiveQuestionCard(result.question);
                }

                afterQuestionResolved();

            } catch (error) {

                console.error(error);

            }

        } else if (event.target.closest("[data-ai-discard-question]")) {

            try {

                await discardGeneratedQuestion(state.offeringId, questionId);

                card.remove();

                afterQuestionResolved();

            } catch (error) {

                console.error(error);

            }

        } else if (event.target.closest("[data-ai-add-choice]")) {

            card
                .querySelector("[data-ai-choices-list]")
                ?.insertAdjacentHTML(
                    "beforeend",
                    createAiChoiceRowHtml(questionId, "", false, card.dataset.aiAllowMultipleAnswers === "1")
                );

            queueSave(questionId, { choices: collectAiChoices(card) });

        } else if (event.target.closest("[data-ai-remove-choice]")) {

            event.target.closest("[data-ai-choice-row]")?.remove();

            queueSave(questionId, { choices: collectAiChoices(card) });

        } else if (event.target.closest("[data-ai-open-settings]")) {

            openAiSettingsModal(card);

        }

    });

    document
        .querySelector("[data-ai-tray-accept-all]")
        ?.addEventListener("click", async () => {

            if (!activeBatchId) {
                return;
            }

            try {

                const result = await acceptAllGeneratedQuestions(state.offeringId, activeBatchId);

                hideTray();

                if (result.success) {

                    (result.questions || []).forEach((question) => {
                        insertLiveQuestionCard(question);
                    });

                }

            } catch (error) {

                console.error(error);

            }

        });

    document
        .querySelector("[data-ai-tray-dismiss]")
        ?.addEventListener("click", () => {
            hideTray();
        });

}

function afterQuestionResolved() {

    if (document.querySelectorAll("[data-ai-question-card]").length === 0) {
        activeBatchId = null;
    }

}

function queueSave(questionId, partialPayload, delay = 600) {

    const existing = pendingPayloads.get(questionId) || {};

    pendingPayloads.set(questionId, { ...existing, ...partialPayload });

    clearTimeout(saveTimers.get(questionId));

    const timer = setTimeout(() => flushPendingSave(questionId), delay);

    saveTimers.set(questionId, timer);

}

async function flushPendingSave(questionId) {

    clearTimeout(saveTimers.get(questionId));

    const payload = pendingPayloads.get(questionId);

    if (!payload) {
        return;
    }

    pendingPayloads.delete(questionId);

    try {

        await updateGeneratedQuestion(state.offeringId, questionId, payload);

    } catch (error) {

        console.error(error);

    }

}

/*
|--------------------------------------------------------------------------
| Settings modal (Required / Allow Multiple Answers) — a separate modal
| instance from the live canvas's #question-settings-modal (see
| quiz-builder-card.php / questions.js) so the two don't fight over one
| shared <form>'s submit listener.
|--------------------------------------------------------------------------
*/

function openAiSettingsModal(card) {

    document.querySelector("[data-ai-question-settings-id]").value =
        card.dataset.aiQuestionId;

    document.querySelector("[data-ai-question-settings-required]").checked =
        card.dataset.aiIsRequired === "1";

    const multipleSection =
        document.querySelector("[data-ai-question-settings-multiple-section]");

    const isMultipleChoiceQuestion =
        card.dataset.aiQuestionType === "Multiple Choice";

    multipleSection?.classList.toggle("hidden", !isMultipleChoiceQuestion);
    multipleSection?.classList.toggle("flex", isMultipleChoiceQuestion);

    const allowMultiple =
        card.dataset.aiAllowMultipleAnswers === "1";

    const allowMultipleCheckbox =
        document.querySelector("[data-ai-question-settings-allow-multiple]");

    if (allowMultipleCheckbox) {
        allowMultipleCheckbox.checked = allowMultiple;
    }

    document.querySelector("[data-ai-question-settings-answers-count]").value =
        (Number(card.dataset.aiAnswersCount) >= 2 ? card.dataset.aiAnswersCount : "2");

    toggleAiAnswersCountWrapper(allowMultiple);

    // Short Answer: case sensitivity of the answer key (off by default).
    const shortAnswerSection =
        document.querySelector("[data-ai-question-settings-short-answer-section]");

    const isShortAnswer =
        card.dataset.aiQuestionType === "Short Answer";

    shortAnswerSection?.classList.toggle("hidden", !isShortAnswer);
    shortAnswerSection?.classList.toggle("flex", isShortAnswer);

    const caseSensitiveCheckbox =
        document.querySelector("[data-ai-question-settings-case-sensitive]");

    if (caseSensitiveCheckbox) {
        caseSensitiveCheckbox.checked = card.dataset.aiCaseSensitive === "1";
    }

    settingsModal?.open();

}

function toggleAiAnswersCountWrapper(isVisible) {

    const wrapper =
        document.querySelector("[data-ai-question-settings-answers-count-wrapper]");

    wrapper?.classList.toggle("hidden", !isVisible);
    wrapper?.classList.toggle("flex", isVisible);

}

function bindAiSettingsModal() {

    settingsModal = bindModal({
        modal: "#ai-question-settings-modal",
        closeButtons: "[data-close-ai-question-settings-modal]",
    });

    document
        .querySelector("[data-ai-question-settings-allow-multiple]")
        ?.addEventListener("change", (event) => {
            toggleAiAnswersCountWrapper(event.target.checked);
        });

    const form = document.querySelector("#ai-question-settings-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const questionId =
            document.querySelector("[data-ai-question-settings-id]")?.value;

        if (!questionId) {
            return;
        }

        const card =
            document.querySelector(`[data-ai-question-card][data-ai-question-id="${questionId}"]`);

        const isRequired =
            document.querySelector("[data-ai-question-settings-required]")?.checked ?? false;

        const allowMultipleAnswers =
            document.querySelector("[data-ai-question-settings-allow-multiple]")?.checked ?? false;

        const answersCount =
            Number(document.querySelector("[data-ai-question-settings-answers-count]")?.value || 2);

        const isShortAnswer =
            card?.dataset.aiQuestionType === "Short Answer";

        const caseSensitive =
            document.querySelector("[data-ai-question-settings-case-sensitive]")?.checked ?? false;

        try {

            // Flush any pending debounced text/points/choices edit first so
            // it can't land after — and clobber — this settings save.
            await flushPendingSave(questionId);

            await updateGeneratedQuestion(state.offeringId, questionId, {
                is_required: isRequired,
                allow_multiple_answers: allowMultipleAnswers,
                answers_count: answersCount,
                ...(isShortAnswer ? { case_sensitive: caseSensitive } : {}),
            });

            if (card) {

                if (isShortAnswer) {

                    card.dataset.aiCaseSensitive = caseSensitive ? "1" : "0";

                    setCaseSensitiveBadge(card.querySelector("[data-accepted-answers]"), caseSensitive);

                }

                card.dataset.aiIsRequired = isRequired ? "1" : "0";
                card.dataset.aiAllowMultipleAnswers = allowMultipleAnswers ? "1" : "0";
                card.dataset.aiAnswersCount = String(answersCount);

                updateAiMultipleBadge(card, allowMultipleAnswers, answersCount);
                rebuildAiChoicesForMultiple(card, allowMultipleAnswers);

            }

            settingsModal?.close();

        } catch (error) {

            console.error(error);

        }

    });

}

function updateAiMultipleBadge(card, allowMultiple, answersCount) {

    const badge = card.querySelector("[data-ai-multiple-badge]");

    if (!badge) {
        return;
    }

    badge.textContent = `Select ${answersCount}`;
    badge.classList.toggle("hidden", !allowMultiple);

}

function rebuildAiChoicesForMultiple(card, isMultiple) {

    const list = card.querySelector("[data-ai-choices-list]");

    if (!list) {
        return;
    }

    let choices = collectAiChoices(card);

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

    list.innerHTML = choices
        .map((choice) => createAiChoiceRowHtml(card.dataset.aiQuestionId, choice.text, choice.is_correct, isMultiple))
        .join("");

    queueSave(card.dataset.aiQuestionId, { choices });

}
