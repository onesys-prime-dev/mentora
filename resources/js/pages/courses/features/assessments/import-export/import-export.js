/*
|--------------------------------------------------------------------------
| Question Export / Import (docx round-trip)
|--------------------------------------------------------------------------
*/

import { bindModal } from "../modals/index.js";

import {
    exportQuestionsUrl,
    previewImport,
    applyImport,
} from "../api.js";

import { generateFromExtractedText } from "../ai-generation/ai-generation.js";

let state = {
    offeringId: null,
    assessmentId: null,
    refresh: null,
};

let modal = null;

let currentDiff = null;

export function initializeImportExport(offeringId, assessmentId, refresh) {

    state = { offeringId, assessmentId, refresh };

    modal = bindModal({
        modal: "#import-questions-modal",
        closeButtons: "[data-close-import-questions-modal]",
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

    document
        .querySelector("[data-export-questions]")
        ?.addEventListener("click", () => {

            const format =
                document.querySelector("[data-export-format-option]:checked")?.value
                || "docx";

            window.location.href = exportQuestionsUrl(state.offeringId, state.assessmentId, format);

            closePanel();

        });

}

/*
|--------------------------------------------------------------------------
| Import
|--------------------------------------------------------------------------
*/

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

    document
        .querySelector("[data-import-apply-button]")
        ?.addEventListener("click", handleApply);

}

function resetImportModal() {

    currentDiff = null;

    document.querySelector("[data-import-file-input]").value = "";

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
        ?.classList.toggle("hidden", step !== "diff");

}

async function handlePreview() {

    const fileInput = document.querySelector("[data-import-file-input]");

    const errorBox = document.querySelector("[data-import-error]");

    errorBox.classList.add("hidden");

    if (!fileInput.files[0]) {

        errorBox.textContent = "Choose a .docx file first.";
        errorBox.classList.remove("hidden");

        return;

    }

    const button = document.querySelector("[data-import-preview-button]");

    button.disabled = true;
    button.textContent = "Reading document...";

    const formData = new FormData();

    formData.set("file", fileInput.files[0]);

    try {

        const result = await previewImport(state.offeringId, state.assessmentId, formData);

        if (!result.success) {
            throw new Error(result.message || "Unable to read this file.");
        }

        if (result.needs_ai_fallback) {

            modal?.close();

            await generateFromExtractedText(result.extracted_text);

            return;

        }

        currentDiff = result.diff;

        renderDiff(result.diff);

        showStep("diff");

    } catch (error) {

        console.error(error);

        errorBox.textContent = error.message || "Something went wrong reading that file.";
        errorBox.classList.remove("hidden");

    } finally {

        button.disabled = false;
        button.textContent = "Preview Changes";

    }

}

function renderDiff(diff) {

    document.querySelector("[data-import-added-count]").textContent = diff.added.length;
    document.querySelector("[data-import-changed-count]").textContent = diff.changed.length;
    document.querySelector("[data-import-removed-count]").textContent = diff.removed.length;

    const list = document.querySelector("[data-import-diff-list]");

    list.innerHTML = "";

    diff.added.forEach((item) => {

        list.appendChild(
            createDiffRow("added", "bg-emerald-50 border-emerald-200 text-emerald-700", item.question_text)
        );

    });

    diff.changed.forEach((item) => {

        list.appendChild(
            createDiffRow(
                "changed",
                "bg-amber-50 border-amber-200 text-amber-700",
                `${item.previous_text} → ${item.question_text}`
            )
        );

    });

    diff.removed.forEach((item) => {

        const row = createDiffRow(
            "removed",
            "bg-red-50 border-red-200 text-red-700",
            item.question_text,
            true
        );

        row.dataset.removeQuestionId = item.id;

        list.appendChild(row);

    });

    if (diff.added.length === 0 && diff.changed.length === 0 && diff.removed.length === 0) {

        const row = document.createElement("div");

        row.className = "rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-[#767676]";
        row.textContent = "No differences found — this matches the current quiz exactly.";

        list.appendChild(row);

    }

}

function createDiffRow(kind, classes, label, withCheckbox = false) {

    const row = document.createElement("div");

    row.className = `flex items-center gap-2 rounded-lg border px-3 py-2 text-xs ${classes}`;

    if (withCheckbox) {

        const checkbox = document.createElement("input");

        checkbox.type = "checkbox";

        checkbox.dataset.importRemoveCheckbox = "";

        checkbox.className = "h-4 w-4 shrink-0 accent-red-500";

        row.appendChild(checkbox);

    }

    const text = document.createElement("span");

    text.className = "min-w-0 flex-1 truncate";

    text.textContent = label;

    row.appendChild(text);

    return row;

}

async function handleApply() {

    if (!currentDiff) {
        return;
    }

    const removeIds = Array.from(
        document.querySelectorAll("[data-import-diff-list] [data-remove-question-id]")
    )
        .filter((row) => row.querySelector("[data-import-remove-checkbox]")?.checked)
        .map((row) => Number(row.dataset.removeQuestionId));

    const button = document.querySelector("[data-import-apply-button]");

    button.disabled = true;
    button.textContent = "Applying...";

    const statusLabel = document.querySelector("[data-import-status]");

    try {

        const result = await applyImport(state.offeringId, state.assessmentId, {
            added: currentDiff.added,
            changed: currentDiff.changed,
            remove_ids: removeIds,
        });

        statusLabel.textContent = result.message || "Import applied.";

        modal?.close();

        await state.refresh();

    } catch (error) {

        console.error(error);

        statusLabel.textContent = "Something went wrong applying this import.";

    } finally {

        button.disabled = false;
        button.textContent = "Apply Import";

    }

}
