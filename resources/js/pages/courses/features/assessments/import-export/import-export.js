/*
|--------------------------------------------------------------------------
| Question Export / Import (docx round-trip)
|--------------------------------------------------------------------------
*/

import { bindModal } from "../modals/index.js";
import { bindQuestionPicker } from "./question-picker.js";

import {
    exportQuestionsUrl,
    importSampleUrl,
    importTemplateUrl,
    previewImport,
    applyImport,
    undoImport,
} from "../api.js";


let state = {
    offeringId: null,
    assessmentId: null,
    refresh: null,
};

let modal = null;

// The preview the server is holding for this import: its token, and what it found.
let preview = null;

// The token of an applied import, while it can still be undone.
let undoToken = null;

// "file", "text" or "quiz": where the questions to import come from.
let importSource = "file";

let picker = null;

export function initializeImportExport(offeringId, assessmentId, refresh) {

    state = { offeringId, assessmentId, refresh };

    modal = bindModal({
        modal: "#import-questions-modal",
        closeButtons: "[data-close-import-questions-modal]",
    });

    picker = bindQuestionPicker({
        ids: () => ({ offeringId: state.offeringId, assessmentId: state.assessmentId }),
        onAdded: showQuestionsAdded,
    });

    bindExportButton();
    bindImportModal();

}

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

function bindExportButton() {

    const toggleButton = document.querySelector("[data-toggle-export-options]");
    const panel = document.querySelector("[data-export-options-panel]");

    function closePanel() {
        panel?.classList.add("hidden");
        panel?.classList.remove("flex");
    }

    toggleButton?.addEventListener("click", (event) => {

        event.stopPropagation();

        panel?.classList.toggle("hidden");
        panel?.classList.toggle("flex");

    });

    document.addEventListener("click", (event) => {

        if (!panel || panel.classList.contains("hidden")) {
            return;
        }

        if (!panel.contains(event.target) && event.target !== toggleButton) {
            closePanel();
        }

    });

    // The print settings only apply to the printable test.
    function syncPrintOptions() {

        const format = document.querySelector("[data-export-format-option]:checked")?.value || "docx";
        const options = document.querySelector("[data-print-options]");
        const versions = Number(document.querySelector("[data-print-versions]")?.value || 1);

        options?.classList.toggle("hidden", !format.startsWith("test-"));
        options?.classList.toggle("flex", format.startsWith("test-"));
        document.querySelector("[data-print-hint]")?.classList.toggle("hidden", versions < 2);

    }

    document.querySelectorAll("[data-export-format-option], [data-print-versions]").forEach((control) => {
        control.addEventListener("change", syncPrintOptions);
    });

    syncPrintOptions();

    document
        .querySelector("[data-export-questions]")
        ?.addEventListener("click", () => {

            const format =
                document.querySelector("[data-export-format-option]:checked")?.value
                || "docx";

            window.location.href = exportQuestionsUrl(state.offeringId, state.assessmentId, format, printSettings());

            closePanel();

        });

}

function printSettings() {

    return {
        versions: Number(document.querySelector("[data-print-versions]")?.value || 1),
        paper: document.querySelector("[data-print-paper]")?.value || "A4",
        shuffleQuestions: !!document.querySelector("[data-print-shuffle-questions]")?.checked,
        shuffleChoices: !!document.querySelector("[data-print-shuffle-choices]")?.checked,
        keyOnly: !!document.querySelector("[data-print-key-only]")?.checked,
    };

}

/*
|--------------------------------------------------------------------------
| Import
|--------------------------------------------------------------------------
*/

/**
 * The sentence under "Review changes": what will happen with the ticked items.
 *
 * @param {{added: number, changed: number, removed: number, skipped: number}} counts
 * @returns {string}
 */
export function importSummary(counts) {

    const parts = [];

    if (counts.added > 0) {
        parts.push(`${counts.added} ${counts.added === 1 ? "question" : "questions"} will be added`);
    }

    if (counts.changed > 0) {
        parts.push(`${counts.changed} will be updated`);
    }

    if (counts.removed > 0) {
        parts.push(`${counts.removed} will be deleted`);
    }

    if (parts.length === 0) {
        return "Nothing to import.";
    }

    return parts.join(", ") + (counts.skipped > 0 ? `, ${counts.skipped} skipped.` : ".");

}

/**
 * One line that says what a question is, so a row can be read at a glance.
 *
 * @param {{question_type: string, points: number|string, choices?: {text: string, is_correct: boolean}[]}} item
 * @returns {string}
 */
export function describeItem(item) {

    const points = Number(item.points);
    const label = `${item.question_type}, ${points} ${points === 1 ? "point" : "points"}`;

    if (!item.choices || item.choices.length === 0) {
        return label;
    }

    const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    const choices = item.choices
        .map((choice, index) => `${letters[index] || index + 1}. ${choice.text}${choice.is_correct ? " ✓" : ""}`)
        .join("  ");

    return `${label} — ${choices}`;

}

function bindImportModal() {

    document
        .querySelector("[data-open-import-questions]")
        ?.addEventListener("click", () => {

            resetImportModal();

            modal?.open();

        });

    document
        .querySelector("[data-import-preview-button]")
        ?.addEventListener("click", handlePreview);

    document.querySelectorAll("[data-import-source-tab]").forEach((tab) => {
        tab.addEventListener("click", () => setImportSource(tab.dataset.importSourceTab));
    });

    const template = document.querySelector("[data-import-template-link]");

    if (template) {
        template.href = importTemplateUrl(state.offeringId, state.assessmentId);
    }

    const sample = document.querySelector("[data-import-sample-link]");

    if (sample) {
        sample.href = importSampleUrl(state.offeringId, state.assessmentId);
    }

    document
        .querySelector("[data-import-apply-button]")
        ?.addEventListener("click", handleApply);

    document
        .querySelector("[data-import-undo-button]")
        ?.addEventListener("click", handleUndo);

    document
        .querySelector("[data-import-remove-all]")
        ?.addEventListener("click", () => {

            document.querySelectorAll("[data-import-removed-list] input[type=checkbox]").forEach((box) => {
                box.checked = true;
            });

            refreshReview();

        });

    document
        .querySelector("[data-import-acknowledge]")
        ?.addEventListener("change", refreshReview);

}

/**
 * Questions copied from another quiz: no review step (the teacher already
 * ticked them), so go straight to the result, with nothing to undo.
 */
async function showQuestionsAdded(message) {

    undoToken = null;

    renderResult({ message, skipped: [] });

    showStep("done");

    await state.refresh();

}

function setImportSource(source) {

    importSource = source;

    document.querySelectorAll("[data-import-source-tab]").forEach((tab) => {

        const active = tab.dataset.importSourceTab === source;

        tab.classList.toggle("bg-white", active);
        tab.classList.toggle("shadow", active);
        tab.classList.toggle("text-brand", active);
        tab.classList.toggle("text-gray-500", !active);
        tab.setAttribute("aria-selected", active ? "true" : "false");

    });

    document.querySelectorAll("[data-import-source-panel]").forEach((panel) => {

        const active = panel.dataset.importSourcePanel === source;

        panel.classList.toggle("hidden", !active);
        panel.classList.toggle("flex", active);

    });

    // The picker has its own add button and layout guide does not apply to it.
    document.querySelector("[data-import-preview-button]")?.classList.toggle("hidden", source === "quiz");
    document.querySelector("[data-import-layout-guide]")?.classList.toggle("hidden", source === "quiz");

    if (source === "quiz") {
        picker?.load();
    }

}

function resetImportModal() {

    preview = null;
    undoToken = null;

    document.querySelector("[data-import-file-input]").value = "";
    document.querySelector("[data-import-text-input]").value = "";

    picker?.reset();

    setImportSource("file");

    document.querySelector("[data-import-error]")?.classList.add("hidden");

    document.querySelector("[data-import-status]").textContent = "";

    showStep("upload");

}

function showStep(step) {

    document.querySelectorAll("[data-import-step]").forEach((el) => {

        const isActive = el.dataset.importStep === step;

        el.classList.toggle("hidden", !isActive);
        el.classList.toggle("flex", isActive);

    });

    document
        .querySelector("[data-import-apply-button]")
        ?.classList.toggle("hidden", step !== "review");

}

function showError(message) {

    const errorBox = document.querySelector("[data-import-error]");

    errorBox.textContent = message;
    errorBox.classList.remove("hidden");

}

async function handlePreview() {

    const fileInput = document.querySelector("[data-import-file-input]");

    document.querySelector("[data-import-error]").classList.add("hidden");

    const pasted = document.querySelector("[data-import-text-input]").value;

    if (importSource === "text" ? pasted.trim() === "" : !fileInput.files[0]) {

        showError(importSource === "text"
            ? "Paste your questions first."
            : "Choose a Word, Rich Text, Excel, CSV or text file first.");

        return;

    }

    const button = document.querySelector("[data-import-preview-button]");

    button.disabled = true;
    button.textContent = "Reading...";

    const formData = new FormData();

    if (importSource === "text") {
        formData.set("text", pasted);
    } else {
        formData.set("file", fileInput.files[0]);
    }

    try {

        const result = await previewImport(state.offeringId, state.assessmentId, formData);

        if (!result.success) {
            throw new Error(result.message || "This file could not be read.");
        }

        preview = result;

        renderReview();

        showStep("review");

    } catch (error) {

        console.error(error);

        showError(error.message || "Something went wrong reading that file.");

    } finally {

        button.disabled = false;
        button.textContent = "Review questions";

    }

}

function itemRow(item, tone) {

    const row = document.createElement("label");

    row.className = `flex items-start gap-3 rounded-lg border px-3 py-2 text-xs ${tone}`;
    row.dataset.importKey = item.key;

    const box = document.createElement("input");

    box.type = "checkbox";
    // A question read from an ordinary document that cannot be imported as it stands
    // (no correct answer yet) can still be ticked once the teacher corrects it below.
    const correctable = preview.source === "plain" && !item.id;

    box.checked = item.importable;
    box.disabled = !item.importable && !correctable;
    box.className = "mt-0.5 h-4 w-4 shrink-0 accent-brand";
    box.addEventListener("change", refreshReview);

    const body = document.createElement("span");

    body.className = "flex min-w-0 flex-1 flex-col gap-0.5";

    const title = document.createElement("span");

    title.className = "font-semibold";
    title.textContent = item.previous_text && item.previous_text !== item.question_text
        ? `${item.previous_text} → ${item.question_text}`
        : (item.question_text || "(no text)");

    const detail = document.createElement("span");

    detail.className = "text-[#555555]";
    detail.textContent = describeItem(item);

    body.append(title, detail);

    item.problems.forEach((problem) => {

        const note = document.createElement("span");

        note.className = problem.severity === "error" ? "font-medium text-red-700" : "text-amber-800";
        note.textContent = (problem.severity === "error" ? "Can't import: " : "Check: ") + problem.message;

        body.appendChild(note);

    });

    row.append(box, body);

    // A question read from an ordinary document can be corrected here before
    // it goes in: its type, its points and which choice is right.
    if (preview.source === "plain" && !item.id) {
        body.appendChild(correctionControls(item));
    }

    return row;

}

function correctionControls(item) {

    const wrap = document.createElement("span");

    wrap.className = "mt-1 flex flex-wrap items-center gap-3";
    wrap.dataset.importCorrection = "";

    // The controls sit inside the row's label: clicks on them must not tick or untick the row.
    wrap.addEventListener("click", (event) => event.stopPropagation());

    const type = document.createElement("select");

    type.className = "rounded-md border border-gray-300 bg-white px-2 py-1 text-xs";
    type.dataset.importType = "";
    type.setAttribute("aria-label", "Question type");

    ["Multiple Choice", "Short Answer", "Essay", "File Upload"].forEach((name) => {

        const option = document.createElement("option");

        option.value = name;
        option.textContent = name;
        option.selected = name === item.question_type;

        type.appendChild(option);

    });

    const points = document.createElement("input");

    points.type = "number";
    points.min = "0";
    points.step = "0.5";
    points.value = String(item.points);
    points.dataset.importPoints = "";
    points.className = "w-16 rounded-md border border-gray-300 px-2 py-1 text-xs";
    points.setAttribute("aria-label", "Points");

    const choices = document.createElement("span");

    choices.className = "flex flex-wrap items-center gap-2";
    choices.dataset.importChoices = "";

    const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    (item.choices || []).forEach((choice, index) => {

        const label = document.createElement("label");

        label.className = "inline-flex items-center gap-1 text-xs";

        const box = document.createElement("input");

        box.type = "checkbox";
        box.checked = choice.is_correct;
        box.dataset.importCorrect = String(index);
        box.className = "h-3.5 w-3.5 accent-brand";

        label.append(box, document.createTextNode(`${letters[index] || index + 1} correct`));
        choices.appendChild(label);

    });

    const pointsLabel = document.createElement("label");

    pointsLabel.className = "inline-flex items-center gap-1 text-xs text-gray-700";
    pointsLabel.append(document.createTextNode("Points"), points);

    wrap.append(type, pointsLabel, choices);

    return wrap;

}

/**
 * What the teacher corrected in the review, by preview key, for the server to
 * apply and check again.
 *
 * @param {ParentNode} root
 * @returns {Record<string, {question_type: string, points: number, correct?: number[]}>}
 */
export function collectOverrides(root = document) {

    const overrides = {};

    root.querySelectorAll("[data-import-diff-list] [data-import-key]").forEach((row) => {

        const controls = row.querySelector("[data-import-correction]");

        if (!controls) {
            return;
        }

        const correct = Array.from(controls.querySelectorAll("[data-import-correct]"))
            .filter((box) => box.checked)
            .map((box) => Number(box.dataset.importCorrect));

        overrides[row.dataset.importKey] = {
            question_type: controls.querySelector("[data-import-type]").value,
            points: Number(controls.querySelector("[data-import-points]").value),
            correct,
        };

    });

    return overrides;

}

function renderReview() {

    const { diff } = preview;

    document.querySelector("[data-import-added-count]").textContent = diff.added.length;
    document.querySelector("[data-import-changed-count]").textContent = diff.changed.length;
    document.querySelector("[data-import-removed-count]").textContent = diff.removed.length;
    document.querySelector("[data-import-problem-count]").textContent = preview.counts.problems;

    const list = document.querySelector("[data-import-diff-list]");

    list.innerHTML = "";

    diff.added.forEach((item) => list.appendChild(itemRow(item, "border-emerald-200 bg-emerald-50")));
    diff.changed.forEach((item) => list.appendChild(itemRow(item, "border-amber-200 bg-amber-50")));

    if (diff.added.length === 0 && diff.changed.length === 0) {

        const empty = document.createElement("div");

        empty.className = "rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-[#767676]";
        empty.textContent = diff.removed.length === 0
            ? "No differences found: this matches the current quiz exactly."
            : "No new or changed questions in the file.";

        list.appendChild(empty);

    }

    const removedSection = document.querySelector("[data-import-removed-section]");
    const removedList = document.querySelector("[data-import-removed-list]");

    removedList.innerHTML = "";

    diff.removed.forEach((item) => {

        const row = document.createElement("label");

        row.className = "flex items-center gap-2 rounded-lg px-2 py-1 text-xs text-gray-700 hover:bg-gray-50";
        row.dataset.removeQuestionId = item.id;

        const box = document.createElement("input");

        box.type = "checkbox";
        box.className = "h-4 w-4 shrink-0 accent-red-500";
        box.addEventListener("change", refreshReview);

        const text = document.createElement("span");

        text.className = "min-w-0 flex-1 truncate";
        text.textContent = item.question_text;

        row.append(box, text);
        removedList.appendChild(row);

    });

    removedSection.classList.toggle("hidden", diff.removed.length === 0);
    removedSection.classList.toggle("flex", diff.removed.length > 0);

    const warning = document.querySelector("[data-import-responses-warning]");

    warning.classList.toggle("hidden", !(preview.responses_affected > 0));
    warning.classList.toggle("flex", preview.responses_affected > 0);
    document.querySelector("[data-import-acknowledge]").checked = false;
    document.querySelector("[data-import-responses-text]").textContent =
        `Students have already answered ${preview.responses_affected} of the questions this import can change or delete. `
        + "Their scores may change. Tick to confirm you understand.";

    const problems = document.querySelector("[data-import-problems]");

    problems.innerHTML = "";

    const errors = preview.problems.filter((p) => p.severity === "error");

    problems.classList.toggle("hidden", errors.length === 0);
    problems.classList.toggle("flex", errors.length > 0);

    errors.forEach((problem) => {

        const line = document.createElement("span");

        line.textContent = `Near paragraph ${problem.line}: ${problem.message}`;

        problems.appendChild(line);

    });

    refreshReview();

}

function chosen() {

    const skipKeys = Array.from(document.querySelectorAll("[data-import-diff-list] [data-import-key]"))
        .filter((row) => !row.querySelector("input").checked)
        .map((row) => row.dataset.importKey);

    const removeIds = Array.from(document.querySelectorAll("[data-import-removed-list] [data-remove-question-id]"))
        .filter((row) => row.querySelector("input").checked)
        .map((row) => Number(row.dataset.removeQuestionId));

    return { skipKeys, removeIds };

}

function refreshReview() {

    if (!preview) {
        return;
    }

    const { skipKeys, removeIds } = chosen();
    const skipped = new Set(skipKeys);
    const { added, changed } = preview.diff;

    const take = (items) => items.filter((item) => !skipped.has(item.key) && (item.importable || preview.source === "plain")).length;

    const counts = {
        added: take(added),
        changed: take(changed),
        removed: removeIds.length,
        skipped: added.length + changed.length - take(added) - take(changed),
    };

    const needsAcknowledgement = preview.responses_affected > 0
        && (counts.changed > 0 || counts.removed > 0);

    document.querySelector("[data-import-status]").textContent = importSummary(counts);

    const apply = document.querySelector("[data-import-apply-button]");

    const nothing = counts.added + counts.changed + counts.removed === 0;
    const unconfirmed = needsAcknowledgement && !document.querySelector("[data-import-acknowledge]").checked;

    apply.disabled = nothing || unconfirmed;
    apply.textContent = counts.removed > 0
        ? `Import and delete ${counts.removed}`
        : "Import";

}

async function handleApply() {

    if (!preview) {
        return;
    }

    const { skipKeys, removeIds } = chosen();

    const button = document.querySelector("[data-import-apply-button]");

    button.disabled = true;
    button.textContent = "Importing...";

    const statusLabel = document.querySelector("[data-import-status]");

    try {

        const result = await applyImport(state.offeringId, state.assessmentId, {
            token: preview.token,
            skip_keys: skipKeys,
            remove_ids: removeIds,
            acknowledge_responses: document.querySelector("[data-import-acknowledge]").checked,
            overrides: collectOverrides(),
        });

        if (!result.success) {
            throw new Error(result.message || "Something went wrong applying this import.");
        }

        undoToken = result.undo_token || null;

        renderResult(result);

        showStep("done");

        statusLabel.textContent = "";

        await state.refresh();

    } catch (error) {

        console.error(error);

        statusLabel.textContent = error.message || "Something went wrong applying this import.";

    } finally {

        button.disabled = false;

        refreshReview();

    }

}

function renderResult(result) {

    document.querySelector("[data-import-result-message]").textContent = result.message || "Import applied.";

    const skipped = document.querySelector("[data-import-skipped]");

    skipped.innerHTML = "";

    const list = result.skipped || [];

    skipped.classList.toggle("hidden", list.length === 0);
    skipped.classList.toggle("flex", list.length > 0);

    list.forEach((item) => {

        const line = document.createElement("span");

        line.textContent = `Skipped near paragraph ${item.line}: ${item.reason}`;

        skipped.appendChild(line);

    });

    document.querySelector("[data-import-undo-button]").classList.toggle("hidden", !undoToken);

}

async function handleUndo() {

    if (!undoToken) {
        return;
    }

    const button = document.querySelector("[data-import-undo-button]");

    button.disabled = true;

    try {

        const result = await undoImport(state.offeringId, state.assessmentId, undoToken);

        document.querySelector("[data-import-result-message]").textContent =
            result.message || (result.success ? "The import was undone." : "The import could not be undone.");

        if (result.success) {

            undoToken = null;

            button.classList.add("hidden");

            await state.refresh();

        }

    } catch (error) {

        console.error(error);

        document.querySelector("[data-import-result-message]").textContent = "The import could not be undone.";

    } finally {

        button.disabled = false;

    }

}
