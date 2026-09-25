/*
|--------------------------------------------------------------------------
| PDF Learning Item Editor
|--------------------------------------------------------------------------
|
| Upload happens as soon as a file is picked or dropped (replacing the
| current one); the "Allow students to download" toggle saves on change.
| Both go to POST …/modules/items/{id}/pdf.
|
*/

import { updatePdfItem } from "../api.js";

import { initializePdfViewer } from "../../../../../shared/pdf/viewer.js";

const MAX_PDF_BYTES = 25 * 1024 * 1024;

export function initializePdfEditor() {

    const editor = document.querySelector("[data-pdf-editor]");

    if (!editor) {
        return;
    }

    const learningItemId = Number(editor.dataset.learningItemId);

    const input = editor.querySelector("[data-pdf-input]");
    const dropzone = editor.querySelector("[data-pdf-dropzone]");
    const status = editor.querySelector("[data-pdf-status]");
    const errorLabel = editor.querySelector("[data-pdf-error]");
    const allowDownload = editor.querySelector("[data-pdf-allow-download]");
    const current = editor.querySelector("[data-pdf-current]");
    const preview = editor.querySelector("[data-pdf-preview]");
    const viewer = editor.querySelector("#pdf-viewer");

    const idleStatus = status.textContent.trim();

    const showError = (message) => {

        errorLabel.textContent = message ?? "";

        errorLabel.classList.toggle("hidden", !message);

    };

    const save = async (formData) => {

        showError("");

        try {

            const result = await updatePdfItem(learningItemId, formData);

            if (!result.success) {

                showError(result.message);

                return null;

            }

            return result;

        }

        catch (error) {

            console.error(error);

            showError("Couldn't save. Please try again.");

            return null;

        }

    };

    const upload = async (file) => {

        if (!file) {
            return;
        }

        if (file.type && file.type !== "application/pdf") {

            showError("Only PDF files can be uploaded here.");

            return;

        }

        if (file.size > MAX_PDF_BYTES) {

            showError("The PDF is larger than the 25 MB limit.");

            return;

        }

        const formData = new FormData();

        formData.append("pdf", file);

        formData.append("allow_download", allowDownload.checked ? "1" : "0");

        status.textContent = `Uploading ${file.name}…`;

        dropzone.classList.add("pointer-events-none", "opacity-60");

        const result = await save(formData);

        dropzone.classList.remove("pointer-events-none", "opacity-60");

        input.value = "";

        if (!result?.file) {

            status.textContent = idleStatus;

            return;

        }

        status.textContent = "Drop a new PDF here to replace it";

        showFile(result.file);

    };

    const showFile = (file) => {

        current.classList.remove("hidden");
        current.classList.add("flex");

        current.querySelector("[data-pdf-current-name]").textContent = file.name;
        current.querySelector("[data-pdf-current-size]").textContent = formatSize(file.size);
        current.querySelector("[data-pdf-current-link]").href = file.url;

        preview.classList.remove("hidden");

        viewer.dataset.url = file.url;

        viewer.dataset.downloadUrl = `${file.url}?download=1`;

        initializePdfViewer();

    };

    input.addEventListener("change", () => upload(input.files?.[0]));

    dropzone.addEventListener("dragover", (event) => {

        event.preventDefault();

        dropzone.dataset.dragging = "";

    });

    dropzone.addEventListener("dragleave", () => {

        delete dropzone.dataset.dragging;

    });

    dropzone.addEventListener("drop", (event) => {

        event.preventDefault();

        delete dropzone.dataset.dragging;

        upload(event.dataTransfer?.files?.[0]);

    });

    allowDownload.addEventListener("change", async () => {

        const formData = new FormData();

        formData.append("allow_download", allowDownload.checked ? "1" : "0");

        const result = await save(formData);

        if (!result) {

            allowDownload.checked = !allowDownload.checked;

            return;

        }

        viewer.dataset.allowDownload = result.allowDownload ? "true" : "false";

        if (viewer.dataset.url) {
            initializePdfViewer();
        }

    });

}

function formatSize(bytes) {

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

}
