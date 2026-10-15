/*
|--------------------------------------------------------------------------
| Import / export dialog helpers
|--------------------------------------------------------------------------
|
| Small pure functions the dialog uses, kept apart so they can be tested
| without a page: which files are accepted, how a review row is labelled,
| what the import button says, and what the export panel remembers.
*/

export const ACCEPTED_EXTENSIONS = ["docx", "rtf", "xlsx", "csv", "txt", "pdf", "html", "htm"];

export const STEPS = ["upload", "review", "done"];

/**
 * @param {string} name
 * @returns {boolean}
 */
export function isAcceptedFile(name) {

    const dot = name.lastIndexOf(".");

    return dot > 0 && ACCEPTED_EXTENSIONS.includes(name.slice(dot + 1).toLowerCase());

}

/**
 * "quiz.docx, 24 KB" for the line under the drop area.
 *
 * @param {{name: string, size: number}} file
 * @returns {string}
 */
export function fileSummary(file) {

    const kb = Math.max(1, Math.round(file.size / 1024));
    const size = kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${kb} KB`;

    return `${file.name}, ${size}`;

}

/**
 * Where a step sits in the Choose, Review, Done stepper.
 *
 * @param {string} step
 * @returns {"done"|"current"|"upcoming"}
 */
export function stepState(step, current) {

    const a = STEPS.indexOf(step);
    const b = STEPS.indexOf(current);

    if (a < b) {
        return "done";
    }

    return a === b ? "current" : "upcoming";

}

/**
 * The chip on a review row: what will happen to this question, in a word.
 *
 * @param {{id?: number, importable: boolean, problems: {severity: string, message: string}[]}} item
 * @returns {{label: string, tone: "new"|"changed"|"duplicate"|"problem"}}
 */
export function statusChip(item) {

    if (!item.importable && item.problems.some((p) => p.severity === "error")) {
        return { label: "Problem", tone: "problem" };
    }

    if (item.problems.some((p) => p.message.startsWith("This looks like a question already in the quiz"))) {
        return { label: "Possible duplicate", tone: "duplicate" };
    }

    return item.id ? { label: "Changed", tone: "changed" } : { label: "New", tone: "new" };

}

/**
 * The import button, naming how many questions go in.
 *
 * @param {{added: number, changed: number, removed: number}} counts
 * @returns {string}
 */
export function importLabel(counts) {

    const total = counts.added + counts.changed;

    if (counts.removed > 0) {
        return `Import ${total} and delete ${counts.removed}`;
    }

    if (total === 0) {
        return "Import";
    }

    return `Import ${total} ${total === 1 ? "question" : "questions"}`;

}

const PREFS_KEY = "mentora.quizExport";

/**
 * The export panel's last choice, remembered in this browser only.
 *
 * @param {Storage|undefined} storage
 */
export function recallExportPrefs(storage = safeStorage()) {

    try {

        const parsed = JSON.parse(storage?.getItem(PREFS_KEY) || "null");

        return parsed && typeof parsed === "object" ? parsed : {};

    } catch {

        return {};

    }

}

/**
 * @param {object} prefs
 * @param {Storage|undefined} storage
 */
export function rememberExportPrefs(prefs, storage = safeStorage()) {

    try {
        storage?.setItem(PREFS_KEY, JSON.stringify(prefs));
    } catch {
        // Storage can be blocked; the panel simply starts from its defaults.
    }

}

function safeStorage() {

    try {
        return window.localStorage;
    } catch {
        return undefined;
    }

}
