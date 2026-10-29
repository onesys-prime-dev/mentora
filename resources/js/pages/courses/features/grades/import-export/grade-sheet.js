/*
|--------------------------------------------------------------------------
| Offline grade sheet: export (XLSX / CSV) and import (preview → apply)
|--------------------------------------------------------------------------
|
| Same flow as the quiz builder's question import
| (assessments/import-export/import-export.js): nothing is saved until the
| instructor reviews the preview and confirms. Only valid changes are sent
| back, with the template signature — the server re-checks all of it.
|
*/

import { gradeSheetExportUrl, previewGradeSheet, applyGradeSheet } from "../api.js";
import { bindModal } from "../../assessments/modals/index.js";

let modal = null;
let preview = null;

export function initializeGradeSheet(refresh)
{
    const toggle = document.querySelector("[data-toggle-grade-sheet-menu]");
    const menu = document.querySelector("[data-grade-sheet-menu]");

    toggle?.addEventListener("click", (event) => {
        event.stopPropagation();
        menu?.classList.toggle("hidden");
        menu?.classList.toggle("flex");
    });

    document.addEventListener("click", (event) => {
        if (menu && !menu.contains(event.target) && event.target !== toggle) {
            menu.classList.add("hidden");
            menu.classList.remove("flex");
        }
    });

    document.querySelectorAll("[data-grade-sheet-export]").forEach((link) => {
        link.addEventListener("click", () => {
            window.location.href = gradeSheetExportUrl(link.dataset.gradeSheetExport);
        });
    });

    modal = bindModal({
        modal: "#grade-sheet-import-modal",
        closeButtons: "[data-close-grade-sheet-import-modal]",
    });

    document.querySelector("[data-open-grade-sheet-import]")
        ?.addEventListener("click", () => {
            menu?.classList.add("hidden");
            reset();
            modal?.open();
        });

    document.querySelector("[data-grade-sheet-preview]")
        ?.addEventListener("click", runPreview);

    document.querySelector("[data-grade-sheet-apply]")
        ?.addEventListener("click", () => runApply(refresh));
}

/*
|--------------------------------------------------------------------------
| Steps
|--------------------------------------------------------------------------
*/

function step(name)
{
    document.querySelectorAll("[data-grade-sheet-step]").forEach((element) => {
        const active = element.dataset.gradeSheetStep === name;
        element.classList.toggle("hidden", !active);
        element.classList.toggle("flex", active);
    });

    document.querySelector("[data-grade-sheet-apply]")?.classList.toggle("hidden", name !== "preview");
}

function reset()
{
    preview = null;

    const input = document.querySelector("[data-grade-sheet-file]");

    if (input) {
        input.value = "";
    }

    showError("");
    setStatus("");
    step("upload");
}

function showError(message)
{
    const box = document.querySelector("[data-grade-sheet-error]");

    if (box) {
        box.textContent = message || "";
        box.classList.toggle("hidden", !message);
    }
}

function setStatus(message)
{
    const status = document.querySelector("[data-grade-sheet-status]");

    if (status) {
        status.textContent = message || "";
    }
}

async function runPreview()
{
    const input = document.querySelector("[data-grade-sheet-file]");
    const button = document.querySelector("[data-grade-sheet-preview]");

    const file = input?.files?.[0];

    if (!file) {
        showError("Choose the .xlsx or .csv grade sheet first.");
        return;
    }

    const formData = new FormData();
    formData.append("file", file);

    button.disabled = true;
    showError("");

    try {

        const result = await previewGradeSheet(formData);

        if (!result.success) {
            showError(result.message);
            return;
        }

        // Wrong template / columns changed: refused outright, nothing parsed.
        if (!result.templateValid) {
            showError(result.templateError);
            return;
        }

        preview = result;

        renderPreview(result);

        step("preview");

    } catch (error) {

        console.error(error);
        showError("The grade sheet couldn't be read.");

    } finally {

        button.disabled = false;

    }
}

async function runApply(refresh)
{
    if (!preview) {
        return;
    }

    const changes = preview.rows
        .filter((row) => row.rowError === null)
        .flatMap((row) => row.changes
            .filter((change) => change.valid)
            .map((change) => ({
                student_id: row.studentId,
                grade_item_id: change.gradeItemId,
                value: change.newValue,
                excused: change.newExcused === true,
            })));

    const button = document.querySelector("[data-grade-sheet-apply]");

    button.disabled = true;

    try {

        const result = await applyGradeSheet({ signature: preview.signature, changes });

        if (!result.success) {
            setStatus(result.message);
            return;
        }

        modal?.close();

        await refresh?.();

    } catch (error) {

        console.error(error);
        setStatus("The scores couldn't be saved.");

    } finally {

        button.disabled = false;

    }
}

/*
|--------------------------------------------------------------------------
| Preview rendering (text via textContent — names come from the file/DB)
|--------------------------------------------------------------------------
*/

const format = (value) => (value === null || value === undefined ? "blank" : String(Math.round(value * 100) / 100));

function renderPreview(result)
{
    document.querySelector("[data-grade-sheet-valid-count]").textContent = result.validCount;
    document.querySelector("[data-grade-sheet-flagged-count]").textContent = result.flaggedCount;

    const notes = document.querySelector("[data-grade-sheet-notes]");

    if (result.missingStudents.length) {
        notes.textContent = `Not in the file (left unchanged): ${result.missingStudents.join(", ")}`;
        notes.classList.remove("hidden");
    } else {
        notes.classList.add("hidden");
    }

    const list = document.querySelector("[data-grade-sheet-rows]");

    list.replaceChildren();

    const rows = result.rows.filter((row) => row.rowError !== null || row.changes.length > 0);

    if (!rows.length) {
        const empty = document.createElement("p");
        empty.className = "rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500";
        empty.textContent = "No score changes found in this file.";
        list.append(empty);
    }

    rows.forEach((row) => list.append(renderRow(row)));

    const apply = document.querySelector("[data-grade-sheet-apply]");

    apply.disabled = result.validCount === 0;

    setStatus(result.validCount === 0 ? "Nothing to import." : `${result.validCount} score(s) will be saved.`);
}

/** How a score reads in the preview: a number, "Excused", or the empty mark. */
export function scoreText(value, excused)
{
    if (excused) {
        return "Excused";
    }

    return value === null || value === undefined ? "—" : String(Math.round(value * 100) / 100);
}

function renderRow(row)
{
    const flagged = row.rowError !== null;

    const card = document.createElement("div");
    card.className = `flex flex-col gap-2 rounded-xl border px-4 py-3 ${flagged ? "border-red-200 bg-red-50" : "border-gray-200"}`;

    const header = document.createElement("div");
    header.className = "flex items-center justify-between gap-3";

    const name = document.createElement("span");
    name.className = "text-sm font-semibold text-[#646464]";
    name.textContent = row.studentName || `Row ${row.rowNumber}`;
    header.append(name);

    if (!flagged && row.finalAfter !== undefined && (row.finalBefore !== null || row.finalAfter !== null)) {
        const final = document.createElement("span");
        final.className = "text-xs text-gray-500";
        final.textContent = `Final ${row.finalBefore === null ? "—" : format(row.finalBefore) + "%"} → ${row.finalAfter === null ? "—" : format(row.finalAfter) + "%"}`;
        header.append(final);
    }

    card.append(header);

    if (flagged) {
        const error = document.createElement("span");
        error.className = "text-xs font-semibold text-red-600";
        error.textContent = `${row.rowError} This row will be skipped.`;
        card.append(error);
        return card;
    }

    row.changes.forEach((change) => {
        const line = document.createElement("span");
        line.className = `text-xs ${change.valid ? "text-gray-600" : "font-semibold text-red-600"}`;
        line.textContent = change.valid
            ? `${change.itemName}: ${scoreText(change.oldValue, change.oldExcused)} → ${scoreText(change.newValue, change.newExcused)}`
            : `${change.itemName}: ${change.error} Skipped.`;
        card.append(line);
    });

    return card;
}
