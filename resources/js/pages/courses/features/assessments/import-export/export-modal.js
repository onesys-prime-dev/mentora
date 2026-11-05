/*
|--------------------------------------------------------------------------
| The Export modal of the quiz builder
|--------------------------------------------------------------------------
| Opened by the export icon (partials/modals/export-questions.php). The teacher
| chooses what they need:
|
|   - an editable file: the formats the question builder can write and read
|     back (Word, Rich Text, Excel, CSV, GIFT, Aiken), with no print settings;
|   - a printable test: PDF, Word or Rich Text, and under that picker the
|     print options (versions, paper, shuffling, answer key only).
|
| Only the choice that is showing is read: print settings left over from the
| printable test never reach an editable export. Exporting downloads the file,
| closes the modal and remembers the choice in this browser only; the next time
| the modal opens on the last choice.
*/

import { bindModal } from "../modals/index.js";
import { exportQuestionsUrl } from "../api.js";
import { normalizeExportPrefs, recallExportPrefs, rememberExportPrefs } from "./import-ui.js";

const KINDS = ["editable", "printable"];

/**
 * @param {object} options
 * @param {() => {offeringId: number|string, assessmentId: number|string}} options.ids the quiz the builder is on
 * @param {(url: string) => void} [options.navigate] how the file is fetched (a test replaces it)
 * @param {Storage} [options.storage] where the last choice is kept (a test replaces it)
 */
export function bindExportModal({ ids, navigate = (url) => { window.location.href = url; }, storage } = {}) {

    const modal = bindModal({
        modal: "#export-questions-modal",
        openButton: "[data-open-export-questions]",
        closeButtons: "[data-close-export-questions-modal]",
    });

    if (!modal) {
        return null;
    }

    const root = modal.element;
    const panel = (kind) => root.querySelector(`[data-export-formats="${kind}"]`);

    function kind() {
        return root.querySelector("[data-export-kind]:checked")?.value ?? "printable";
    }

    function format() {
        return panel(kind())?.querySelector("[data-export-format-option]:checked")?.value;
    }

    function printSettings() {

        return {
            versions: Number(root.querySelector("[data-print-versions]")?.value || 1),
            paper: root.querySelector("[data-print-paper]")?.value || "A4",
            shuffleQuestions: !!root.querySelector("[data-print-shuffle-questions]")?.checked,
            shuffleChoices: !!root.querySelector("[data-print-shuffle-choices]")?.checked,
            keyOnly: !!root.querySelector("[data-print-key-only]")?.checked,
        };

    }

    /** Shows the picker of the chosen kind (the print options come with the printable one), and hides the other. */
    function sync() {

        const current = kind();

        for (const name of KINDS) {

            const el = panel(name);

            el?.classList.toggle("hidden", name !== current);
            el?.classList.toggle("flex", name === current);

        }

        const versions = Number(root.querySelector("[data-print-versions]")?.value || 1);

        root.querySelector("[data-print-hint]")?.classList.toggle("hidden", versions < 2);

    }

    /** Puts the modal back the way this teacher last left it. */
    function restore() {

        const prefs = normalizeExportPrefs(recallExportPrefs(storage));

        if (prefs.kind) {
            const choice = root.querySelector(`[data-export-kind][value="${prefs.kind}"]`);

            if (choice) {
                choice.checked = true;
            }
        }

        if (prefs.format) {
            // Only a format the modal still offers; anything else leaves the default.
            const option = Array.from(root.querySelectorAll("[data-export-format-option]")).find((o) => o.value === prefs.format);

            if (option) {
                option.checked = true;
            }
        }

        const print = prefs.print || {};
        const set = (selector, value, key = "value") => {

            const el = root.querySelector(selector);

            if (el && value !== undefined) {
                el[key] = value;
            }

        };

        set("[data-print-versions]", print.versions === undefined ? undefined : String(print.versions));
        set("[data-print-paper]", print.paper);
        set("[data-print-shuffle-questions]", print.shuffleQuestions, "checked");
        set("[data-print-shuffle-choices]", print.shuffleChoices, "checked");
        set("[data-print-key-only]", print.keyOnly, "checked");

    }

    root.querySelectorAll("[data-export-kind], [data-print-versions]").forEach((control) => {
        control.addEventListener("change", sync);
    });

    root.querySelector("[data-export-questions]")?.addEventListener("click", () => {

        const chosen = kind();
        const picked = format();

        if (!picked) {
            return;
        }

        const print = printSettings();

        rememberExportPrefs({ kind: chosen, format: picked, print }, storage);

        const { offeringId, assessmentId } = ids();

        navigate(exportQuestionsUrl(offeringId, assessmentId, picked, chosen === "printable" ? print : {}));

        modal.close();

    });

    restore();
    sync();

    return modal;

}
