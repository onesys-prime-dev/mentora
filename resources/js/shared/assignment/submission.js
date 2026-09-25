/*
|--------------------------------------------------------------------------
| Assignment Submission
|--------------------------------------------------------------------------
|
| Shared by the Modules learning item and the Assessments tab — both render
| assessments/partials/assignment-work.php. Tabs are CSS-only (radio +
| group-has); this wires the dropzone and the submit request.
|
*/

const MAX_FILE_BYTES = 20 * 1024 * 1024;

export function initializeAssignmentSubmission({ onSubmitted } = {}) {

    const form =
        document.querySelector("[data-assignment-submission-form]");

    if (!form) {
        return;
    }

    const fileInput =
        form.querySelector("[data-assignment-file-input]");

    const fileName =
        form.querySelector("[data-assignment-file-name]");

    const dropzone =
        form.querySelector("[data-assignment-dropzone]");

    const errorLabel =
        form.querySelector("[data-assignment-submission-error]");

    const submitButton =
        form.querySelector("[data-assignment-submit]");

    const defaultFileLabel =
        fileName?.textContent.trim() ?? "";

    const submitLabel =
        submitButton?.textContent.trim() ?? "Submit";

    const showError = (message) => {

        if (!errorLabel) {
            return;
        }

        errorLabel.textContent = message;

        errorLabel.classList.toggle("hidden", !message);

    };

    const showFileName = () => {

        const file = fileInput?.files?.[0];

        if (fileName) {

            fileName.textContent = file
                ? `${file.name} (${formatSize(file.size)})`
                : defaultFileLabel;

        }

        showError("");

    };

    fileInput?.addEventListener("change", showFileName);

    if (dropzone && fileInput) {

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

            const dropped = event.dataTransfer?.files?.[0];

            if (!dropped) {
                return;
            }

            const transfer = new DataTransfer();

            transfer.items.add(dropped);

            fileInput.files = transfer.files;

            showFileName();

        });

    }

    form.addEventListener("submit", async (event) => {

        event.preventDefault();

        showError("");

        const formData = new FormData(form);

        formData.delete("work-tab");

        const file = fileInput?.files?.[0];

        if (!file) {
            formData.delete("file");
        }

        const text = String(formData.get("submission_text") ?? "").trim();

        const link = String(formData.get("submission_link") ?? "").trim();

        if (!file && !text && !link) {

            showError("Add your work before submitting.");

            return;

        }

        if (file && file.size > MAX_FILE_BYTES) {

            showError("The file is larger than the 20 MB limit.");

            return;

        }

        if (link && !/^https?:\/\//i.test(link)) {

            showError("Enter a full link starting with http:// or https://.");

            return;

        }

        submitButton.disabled = true;

        submitButton.textContent = "Submitting...";

        try {

            const response = await fetch(

                `${window.coursesConfig.apiBaseUrl}/assessments/${form.dataset.assessmentId}/assignment-submissions`,

                {

                    method: "POST",

                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "Accept": "application/json",
                    },

                    body: formData,

                }

            );

            const data = await response.json().catch(() => null);

            if (!response.ok || !data?.success) {

                showError(data?.message ?? "Your work couldn't be submitted. Please try again.");

                return;

            }

            await onSubmitted?.();

        }

        catch (error) {

            console.error(error);

            showError("Your work couldn't be submitted. Check your connection and try again.");

        }

        finally {

            // The form is usually re-rendered by onSubmitted; this only
            // matters when the request failed and the form is still here.
            if (submitButton.isConnected) {

                submitButton.disabled = false;

                submitButton.textContent = submitLabel;

            }

        }

    });

}

function formatSize(bytes) {

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

}
