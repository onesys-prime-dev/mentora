/*
|--------------------------------------------------------------------------
| Suggestions while typing students into an "add students" box
|--------------------------------------------------------------------------
| <textarea data-student-autocomplete="/institution/enrolments/suggest?offering=5">
|
| The box takes several entries (student numbers or emails, one per line or
| separated by commas, semicolons or spaces). While someone types, the entry
| the caret is in is looked up on the server, which answers only with students
| of the administrator's own institution who could still be added, and a short
| list drops under the box: name, student number and email.
|
|   - nothing is asked for under two characters; a short pause after the last
|     key keeps it to one request per burst of typing
|   - an answer for text that has since changed is dropped, so a slow reply
|     never lands on top of a newer one
|   - choosing a row (click, or Down/Up and Enter) puts the student's email in
|     place of what was typed and starts a new line for the next one
|   - Escape closes the list and nothing else (the dialog around the box stays);
|     leaving the box closes it too
|   - a failed request just closes the list; the box keeps working as a plain
|     textarea, and the server checks every entry again when the form is sent
|
| Names and emails are written as text, never as markup.
*/

const MIN = 2;
const PAUSE = 250;
const BLUR_DELAY = 150;
const SEPARATOR = /[\s,;]/;

const wired = new WeakSet();
let counter = 0;

/**
 * The entry the caret is in: from the separator before it to the separator after it.
 *
 * @returns {{start: number, end: number, query: string}}
 */
export function currentPiece(text, caret) {
    let start = caret;
    let end = caret;

    while (start > 0 && !SEPARATOR.test(text[start - 1])) {
        start--;
    }

    while (end < text.length && !SEPARATOR.test(text[end])) {
        end++;
    }

    return { start, end, query: text.slice(start, end) };
}

/**
 * The text with the entry replaced by `value`, followed by a new line unless a separator already follows, and where
 * the caret goes (right after what was put in).
 *
 * @returns {{text: string, caret: number}}
 */
export function replacePiece(text, piece, value) {
    const before = text.slice(0, piece.start);
    const after = text.slice(piece.end);
    const insert = value + (after === "" || !SEPARATOR.test(after[0]) ? "\n" : "");

    return { text: before + insert + after, caret: before.length + insert.length };
}

function wire(box) {
    if (wired.has(box)) {
        return;
    }

    wired.add(box);

    const id = `student-autocomplete-${++counter}`;
    const popup = document.createElement("div");
    const list = document.createElement("ul");
    const none = document.createElement("p");

    popup.hidden = true;
    popup.className = "absolute left-0 right-0 z-20 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg";
    list.id = id;
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", "Matching students");
    // Short enough to stay inside the dialog it opens in; the list scrolls for the rest.
    list.className = "max-h-40 overflow-y-auto py-1";
    none.setAttribute("role", "status");
    none.className = "px-4 py-3 text-sm text-[#646464]";
    popup.append(list, none);

    // The list floats under the box; its field is what it is positioned against.
    box.parentElement?.classList.add("relative");
    box.insertAdjacentElement("afterend", popup);

    box.setAttribute("role", "combobox");
    box.setAttribute("aria-autocomplete", "list");
    box.setAttribute("aria-haspopup", "listbox");
    box.setAttribute("aria-controls", id);
    box.setAttribute("aria-expanded", "false");

    let timer = null;
    let controller = null;
    let latest = 0;
    let results = [];
    let active = -1;

    const rows = () => [...list.children];

    function close() {
        popup.hidden = true;
        list.replaceChildren();
        none.textContent = "";
        results = [];
        active = -1;
        box.setAttribute("aria-expanded", "false");
        box.removeAttribute("aria-activedescendant");
    }

    function highlight(index) {
        active = index;

        rows().forEach((row, i) => row.setAttribute("aria-selected", i === index ? "true" : "false"));

        if (index >= 0) {
            box.setAttribute("aria-activedescendant", rows()[index].id);
            rows()[index].scrollIntoView?.({ block: "nearest" });
        } else {
            box.removeAttribute("aria-activedescendant");
        }
    }

    function pick(index) {
        const student = results[index];

        if (!student) {
            return;
        }

        const piece = currentPiece(box.value, box.selectionStart ?? box.value.length);
        const next = replacePiece(box.value, piece, student.email);

        box.value = next.text;
        box.setSelectionRange(next.caret, next.caret);
        close();
    }

    function show(students) {
        results = students;
        list.replaceChildren(
            ...students.map((student, index) => {
                const row = document.createElement("li");
                const name = document.createElement("span");
                const meta = document.createElement("span");

                row.id = `${id}-option-${index}`;
                row.setAttribute("role", "option");
                row.setAttribute("aria-selected", "false");
                row.className = "cursor-pointer px-4 py-2 hover:bg-brand-tint aria-selected:bg-brand-tint";
                name.className = "block text-sm font-medium text-[#1A1C1E]";
                name.textContent = student.name;
                meta.className = "block text-xs text-[#646464]";
                meta.textContent = [student.student_number, student.email].filter(Boolean).join(" · ");
                row.append(name, meta);

                // Mouse down, not click: the box keeps focus, so the list is not closed by the box losing it.
                row.addEventListener("mousedown", (event) => {
                    event.preventDefault();
                    pick(index);
                });

                return row;
            }),
        );

        none.textContent = students.length === 0 ? "No matching students" : "";
        active = -1;

        // Right under the box (not at the top of its field, where an absolutely placed child would otherwise sit,
        // over the text being typed).
        popup.style.top = `${box.offsetTop + box.offsetHeight + 4}px`;
        popup.hidden = false;
        box.setAttribute("aria-expanded", students.length > 0 ? "true" : "false");
        box.removeAttribute("aria-activedescendant");
    }

    async function ask(query, mine) {
        controller = new AbortController();

        try {
            const url = new URL(box.dataset.studentAutocomplete, window.location.origin);

            if (url.origin !== window.location.origin) {
                return;
            }

            url.searchParams.set("q", query);

            const response = await fetch(url.pathname + url.search, {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
                signal: controller.signal,
            });

            if (mine !== latest) {
                return;
            }

            if (!response.ok) {
                throw new Error(`Suggestions answered ${response.status}`);
            }

            const data = await response.json();

            if (mine === latest) {
                show(Array.isArray(data?.students) ? data.students : []);
            }
        } catch {
            if (mine === latest) {
                close();
            }
        }
    }

    box.addEventListener("input", () => {
        clearTimeout(timer);
        controller?.abort();

        const mine = ++latest;
        const piece = currentPiece(box.value, box.selectionStart ?? box.value.length);

        if ([...piece.query].length < MIN) {
            close();

            return;
        }

        timer = setTimeout(() => ask(piece.query, mine), PAUSE);
    });

    box.addEventListener("keydown", (event) => {
        // Escape closes the list only; it must not reach the dialog, which would close with it.
        if (event.key === "Escape" && !popup.hidden) {
            event.stopPropagation();
            event.preventDefault();
            close();

            return;
        }

        if (results.length === 0) {
            return;
        }

        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();

            const step = event.key === "ArrowDown" ? 1 : -1;
            const next = active === -1 ? (step === 1 ? 0 : results.length - 1) : (active + step + results.length) % results.length;

            highlight(next);
        } else if (event.key === "Enter" && active >= 0) {
            event.preventDefault();
            pick(active);
        }
    });

    box.addEventListener("blur", () => setTimeout(close, BLUR_DELAY));

    // A suggestion asked for after the form is sent would reach the server between the save and the page
    // it leads to, and use up the session's one-time success message on the way.
    box.form?.addEventListener("submit", () => {
        clearTimeout(timer);
        controller?.abort();
        latest++;
        close();
    });
}

/** Wires every marked box under `root`; safe to call again (a box is wired once). */
export function initStudentAutocomplete(root = document) {
    for (const box of root.querySelectorAll("textarea[data-student-autocomplete]")) {
        wire(box);
    }
}

if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => initStudentAutocomplete());
    } else {
        initStudentAutocomplete();
    }

    // Content swapped in by a live search or a page change may bring boxes of its own.
    document.addEventListener("content:swapped", (event) => initStudentAutocomplete(event.detail?.root ?? document));
}
