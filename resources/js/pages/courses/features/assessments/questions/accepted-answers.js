/*
|--------------------------------------------------------------------------
| Short Answer answer key editor
|--------------------------------------------------------------------------
|
| "Accepted answers" list shown on Short Answer questions — in the Quiz
| Builder and in the AI review tray. The first answer is the main one; the
| rest are equivalent forms (acronym / full term / alternate spelling). A
| student's answer is auto-graded against these (ShortAnswerGrader on the
| server). The server-rendered twin of this markup is
| app/Views/pages/courses/features/assessments/partials/accepted-answers-editor.php.
|
| Callers delegate clicks/inputs to handleAcceptedAnswersEvent() and save
| collectAcceptedAnswers(editor) however they persist the question.
|
*/

export function renderAcceptedAnswersEditor(answers = [], caseSensitive = false) {

    const rows = (answers.length ? answers : [""])
        .map((answer, index) => renderRow(answer, index))
        .join("");

    return `
        <div class="flex flex-col gap-2" data-accepted-answers>

            <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] font-bold uppercase tracking-wide text-emerald-700">Accepted answers</span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600 ${caseSensitive ? "" : "hidden"}" data-case-sensitive-badge>Case sensitive</span>
            </div>

            <div class="flex flex-col gap-1.5" data-accepted-answers-list>${rows}</div>

            <button type="button" data-add-accepted-answer class="self-start text-xs font-semibold text-[#0040A6] transition hover:underline">+ Add alternative</button>

            <p class="rounded-lg border border-[#FFE3A3] bg-[#FFF8E6] px-3 py-2 text-xs text-[#946200] ${answers.length ? "hidden" : ""}" data-accepted-answers-empty>
                Add the correct answer so this question can be auto-graded.
            </p>

        </div>`;

}

function renderRow(answer, index) {

    return `
        <div class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/60 px-2.5 py-1.5" data-accepted-answer-row>
            <input type="text" data-accepted-answer value="${escapeAttribute(answer)}" placeholder="${index === 0 ? "Correct answer" : "Alternative answer"}" class="min-w-0 flex-1 border-0 bg-transparent px-1 py-0.5 text-sm font-semibold text-emerald-700 outline-none placeholder:font-normal placeholder:text-emerald-700/40">
            <button type="button" data-remove-accepted-answer title="Remove" class="shrink-0 rounded-lg p-1 text-emerald-700/40 transition hover:bg-red-50 hover:text-red-500">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>`;

}

/**
 * Non-blank answers, trimmed and de-duplicated, in order.
 */
export function collectAcceptedAnswers(editor) {

    const answers = [];

    editor
        ?.querySelectorAll("[data-accepted-answer]")
        .forEach((input) => {

            const value = input.value.trim().replace(/\s+/g, " ");

            if (value && !answers.includes(value)) {
                answers.push(value);
            }

        });

    return answers;

}

export function setCaseSensitiveBadge(editor, caseSensitive) {

    editor
        ?.querySelector("[data-case-sensitive-badge]")
        ?.classList.toggle("hidden", !caseSensitive);

}

/**
 * Handles add / remove / typing inside an editor. Returns the editor when
 * the answer list changed (so the caller should save), otherwise null.
 */
export function handleAcceptedAnswersEvent(event) {

    const editor = event.target.closest("[data-accepted-answers]");

    if (!editor) {
        return null;
    }

    if (event.type === "click" && event.target.closest("[data-add-accepted-answer]")) {

        const list = editor.querySelector("[data-accepted-answers-list]");

        list.insertAdjacentHTML("beforeend", renderRow("", list.children.length));

        list.lastElementChild?.querySelector("input")?.focus();

        return null; // an empty row changes nothing until typed in

    }

    if (event.type === "click" && event.target.closest("[data-remove-accepted-answer]")) {

        const rows = editor.querySelectorAll("[data-accepted-answer-row]");

        const row = event.target.closest("[data-accepted-answer-row]");

        // Keep one row so there's always somewhere to type the answer.
        if (rows.length > 1) {
            row.remove();
        } else {
            row.querySelector("input").value = "";
        }

        refreshPlaceholders(editor);

        refreshEmptyHint(editor);

        return editor;

    }

    if (event.type === "input" && event.target.closest("[data-accepted-answer]")) {

        refreshEmptyHint(editor);

        return editor;

    }

    return null;

}

function refreshPlaceholders(editor) {

    editor
        .querySelectorAll("[data-accepted-answer]")
        .forEach((input, index) => {
            input.placeholder = index === 0 ? "Correct answer" : "Alternative answer";
        });

}

function refreshEmptyHint(editor) {

    editor
        .querySelector("[data-accepted-answers-empty]")
        ?.classList.toggle("hidden", collectAcceptedAnswers(editor).length > 0);

}

function escapeAttribute(value) {

    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll('"', "&quot;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;");

}
