/*
|--------------------------------------------------------------------------
| Add questions from another quiz
|--------------------------------------------------------------------------
|
| The third way into the import dialog: pick one of the teacher's other
| quizzes, tick questions, add them. The server decides which quizzes may be
| taken from and does the copy; this file only shows and collects.
*/

import {
    fetchQuestionSources,
    fetchSourceQuestions,
    copyQuestions,
} from "../api.js";

/**
 * Questions whose text contains every word typed, in any order.
 *
 * @param {{question_text: string}[]} questions
 * @param {string} term
 */
export function filterQuestions(questions, term) {

    const words = term.toLowerCase().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return questions;
    }

    return questions.filter((question) => {

        const text = question.question_text.toLowerCase();

        return words.every((word) => text.includes(word));

    });

}

/**
 * The label of the add button for a number of ticked questions.
 *
 * @param {number} count
 * @returns {string}
 */
export function addLabel(count) {

    if (count === 0) {
        return "Add questions";
    }

    return `Add ${count} ${count === 1 ? "question" : "questions"}`;

}

/**
 * One line that says what a question is.
 *
 * @param {{question_type: string, points: number}} question
 */
export function questionMeta(question) {

    const points = Number(question.points);

    return `${question.question_type}, ${points} ${points === 1 ? "point" : "points"}`;

}

/**
 * @param {{
 *   ids: () => {offeringId: number, assessmentId: number},
 *   onAdded: (message: string) => Promise<void>|void,
 * }} options
 */
export function bindQuestionPicker({ ids, onAdded }) {

    const panel = document.querySelector("[data-import-source-panel=quiz]");

    if (!panel) {
        return { load() {}, reset() {} };
    }

    const sourceSelect = panel.querySelector("[data-picker-source]");
    const list = panel.querySelector("[data-picker-list]");
    const search = panel.querySelector("[data-picker-search]");
    const selectAll = panel.querySelector("[data-picker-select-all]");
    const addButton = panel.querySelector("[data-picker-add]");
    const message = panel.querySelector("[data-picker-message]");
    const tools = panel.querySelector("[data-picker-tools]");

    let loaded = false;
    let questions = [];
    const ticked = new Set();

    function say(text, tone = "muted") {

        message.textContent = text;
        message.classList.toggle("hidden", text === "");
        message.classList.toggle("text-red-600", tone === "error");
        message.classList.toggle("text-[#767676]", tone !== "error");

    }

    function visible() {
        return filterQuestions(questions, search.value);
    }

    function refreshControls() {

        addButton.textContent = addLabel(ticked.size);
        addButton.disabled = ticked.size === 0;

        const shown = visible();

        selectAll.textContent = shown.length > 0 && shown.every((q) => ticked.has(q.id))
            ? "Clear selection"
            : "Select all";

    }

    function render() {

        list.replaceChildren();

        const shown = visible();

        tools.classList.toggle("hidden", questions.length === 0);

        if (questions.length > 0 && shown.length === 0) {
            say("No question matches that search.");
        } else if (message.dataset.keep !== "1") {
            say("");
        }

        shown.forEach((question) => {

            const row = document.createElement("label");

            row.className = "flex items-start gap-3 rounded-lg border border-gray-200 px-3 py-2 text-xs";

            const box = document.createElement("input");

            box.type = "checkbox";
            box.checked = ticked.has(question.id);
            box.className = "mt-0.5 h-4 w-4 shrink-0 accent-brand";
            box.addEventListener("change", () => {

                if (box.checked) {
                    ticked.add(question.id);
                } else {
                    ticked.delete(question.id);
                }

                refreshControls();

            });

            const body = document.createElement("span");

            body.className = "flex min-w-0 flex-1 flex-col gap-0.5";

            const title = document.createElement("span");

            title.className = "font-semibold";
            title.textContent = question.question_text;

            const meta = document.createElement("span");

            meta.className = "text-[#555555]";
            meta.textContent = questionMeta(question);

            body.append(title, meta);

            if (question.already_in_quiz) {

                const chip = document.createElement("span");

                chip.className = "w-fit rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-800";
                chip.textContent = "Already in this quiz";

                body.appendChild(chip);

            }

            row.append(box, body);
            list.appendChild(row);

        });

        refreshControls();

    }

    async function showSource(sourceId) {

        questions = [];
        ticked.clear();
        search.value = "";
        message.dataset.keep = "0";
        render();

        if (!sourceId) {
            return;
        }

        say("Loading questions...");
        message.dataset.keep = "1";

        try {

            const { offeringId, assessmentId } = ids();
            const result = await fetchSourceQuestions(offeringId, assessmentId, sourceId);

            if (!result.success) {
                throw new Error(result.message || "Those questions could not be loaded.");
            }

            questions = result.questions;

            message.dataset.keep = "0";
            render();

        } catch (error) {

            console.error(error);

            message.dataset.keep = "1";
            say("Those questions could not be loaded. Try again.", "error");

        }

    }

    async function load() {

        if (loaded) {
            return;
        }

        say("Loading your quizzes...");
        message.dataset.keep = "1";

        try {

            const { offeringId, assessmentId } = ids();
            const result = await fetchQuestionSources(offeringId, assessmentId);

            if (!result.success) {
                throw new Error(result.message || "Your quizzes could not be loaded.");
            }

            loaded = true;

            sourceSelect.replaceChildren();

            const first = document.createElement("option");

            first.value = "";
            first.textContent = result.sources.length > 0 ? "Choose a quiz" : "No other quiz has questions yet";

            sourceSelect.appendChild(first);

            result.sources.forEach((source) => {

                const option = document.createElement("option");

                option.value = String(source.id);
                option.textContent = `${source.title} — ${source.course}, ${source.section}, ${source.term} (${source.questions})`;

                sourceSelect.appendChild(option);

            });

            sourceSelect.disabled = result.sources.length === 0;

            message.dataset.keep = "0";
            say(result.sources.length === 0
                ? "Quizzes from the sections you teach appear here once they have questions."
                : "");

        } catch (error) {

            console.error(error);

            message.dataset.keep = "1";
            say("Your quizzes could not be loaded. Close this and try again.", "error");

        }

    }

    function reset() {

        loaded = false;
        questions = [];
        ticked.clear();

        sourceSelect.replaceChildren();
        sourceSelect.disabled = true;
        search.value = "";
        message.dataset.keep = "0";

        render();
        say("");

    }

    sourceSelect.addEventListener("change", () => showSource(Number(sourceSelect.value)));

    search.addEventListener("input", render);

    selectAll.addEventListener("click", () => {

        const shown = visible();
        const all = shown.length > 0 && shown.every((q) => ticked.has(q.id));

        shown.forEach((q) => (all ? ticked.delete(q.id) : ticked.add(q.id)));

        render();

    });

    addButton.addEventListener("click", async () => {

        if (ticked.size === 0) {
            return;
        }

        addButton.disabled = true;
        addButton.textContent = "Adding...";

        try {

            const { offeringId, assessmentId } = ids();
            const result = await copyQuestions(
                offeringId,
                assessmentId,
                Number(sourceSelect.value),
                Array.from(ticked)
            );

            if (!result.success) {

                message.dataset.keep = "1";
                say(result.message || "Those questions could not be added.", "error");

                return;

            }

            await onAdded(result.message || "Questions were added.");

        } catch (error) {

            console.error(error);

            message.dataset.keep = "1";
            say("Those questions could not be added. Nothing was changed.", "error");

        } finally {

            refreshControls();

        }

    });

    return { load, reset };

}
