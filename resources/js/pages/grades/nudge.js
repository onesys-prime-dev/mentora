/*
|--------------------------------------------------------------------------
| Nudge an instructor, from the chairperson's Program tab
|--------------------------------------------------------------------------
|
| The Nudge button acts on the section shown below the table. Its data is on the action bar ([data-program-actions],
| drawn by the server and updated by program.js on every row switch), so it works also when that section's row is
| on another page or filtered out. The dialog the server drew (partials/chairperson/nudge-dialog.php) is filled from
| it: the subject and the message say what is wrong (submissions waiting, or not finalized with the term's end), the
| recipients are the section's instructors less the chairperson (one is preselected; none: Send is disabled), the
| reason follows the issue. A finalized section has nothing to nudge about: the button is hidden and nothing opens. Send posts the form to Follow-ups and
| shows the answer as a toast; the chairperson stays on the page. A refusal (422) is shown inside the dialog and
| keeps what was typed.
|
| Everything is found from the document when the button is pressed, so the live search swapping the table does not
| unbind anything.
|
*/

import { applyFieldErrors, createDialog } from "../../shared/dialog.js";
import { showToast } from "../../shared/toast.js";

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

const dialogs = new WeakMap();

// "2026-12-15" as "Dec 15", read from the text so no time zone can move the day.
export function monthDay(ymd) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd ?? "");

    return match ? `${MONTHS[Number(match[2]) - 1]} ${Number(match[3])}` : "";
}

// ", oldest 9 days", ", oldest 1 day", ", oldest today"; nothing when the age is unknown ("" is no age, "0" is today).
function oldest(days) {
    if (days === undefined || days === "" || Number.isNaN(Number(days))) {
        return "";
    }

    const count = Number(days);

    if (count === 0) {
        return ", oldest today";
    }

    return `, oldest ${count} ${count === 1 ? "day" : "days"}`;
}

/**
 * What the nudge says about a section, from its data attributes (the action bar's).
 *
 * @param {HTMLElement} source
 * @returns {{title: string, message: string, reason: string}}
 */
export function nudgeFor(source) {
    const label = source.dataset.label ?? "";
    const waiting = Number(source.dataset.waiting ?? 0);
    const state = source.dataset.state;
    let title = "";
    let reason = "other";

    if (waiting > 0) {
        title = `${label}: ${waiting} ${waiting === 1 ? "submission" : "submissions"} waiting to be graded${oldest(source.dataset.oldestDays)}`;
        reason = "overdue_grading";
    } else if (state !== "finalized") {
        const end = monthDay(source.dataset.termEnd);

        title = `${label}: not finalized${end ? `; the term ends ${end}` : ""}`;
        reason = state === "blocked" ? "blocked_signoff" : "other";
    }

    return { title, reason, message: title === "" ? "" : `${title}. Could you take a look when you can?` };
}

function instructorsOf(source) {
    try {
        const list = JSON.parse(source.dataset.instructors ?? "[]");

        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

function fill(el, source) {
    const form = el.querySelector("[data-nudge-form]");
    const { title, message, reason } = nudgeFor(source);
    const people = instructorsOf(source);
    const select = form.querySelector("[data-nudge-recipient]");

    form.reset();
    applyFieldErrors(form, {});

    form.elements.namedItem("offering_id").value = source.dataset.sectionId ?? "";
    form.querySelector("[data-nudge-title]").value = title;
    form.querySelector("[data-nudge-message]").value = message;
    form.querySelector("[data-nudge-reason]").value = reason;

    select.replaceChildren();

    // Two or more: the chairperson chooses. One: it is the one. None: nothing to send to, so no Send.
    if (people.length !== 1) {
        const first = document.createElement("option");

        first.value = "";
        first.textContent = people.length === 0 ? "No instructor to nudge" : "Choose an instructor";
        select.append(first);
    }

    for (const person of people) {
        const option = document.createElement("option");

        option.value = String(person.id);
        option.textContent = person.name;
        select.append(option);
    }

    select.value = people.length === 1 ? String(people[0].id) : "";

    form.querySelector("[data-nudge-send]").disabled = people.length === 0;
}

async function send(el, form) {
    const button = form.querySelector("[data-nudge-send]");

    button.disabled = true;
    form.setAttribute("aria-busy", "true");

    try {
        const response = await fetch(form.action, {
            method: "POST",
            body: new FormData(form),
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        });
        const body = await response.json().catch(() => ({}));

        if (response.ok) {
            if (body.message) {
                showToast(body.message, { tone: "success" });
            }

            dialogs.get(el)?.close();

            return;
        }

        applyFieldErrors(form, {}, body.message ?? "That did not work. Nothing was sent. Please try again.");
    } catch {
        applyFieldErrors(form, {}, "We could not reach the server. Nothing was sent. Check your connection and try again.");
    } finally {
        button.disabled = false;
        form.removeAttribute("aria-busy");
    }
}

function open(trigger) {
    const el = document.querySelector("[data-nudge-dialog]");
    const source = document.querySelector("[data-program-actions]");

    if (!el || !source || source.dataset.state === "finalized") {
        return;
    }

    if (!dialogs.has(el)) {
        dialogs.set(el, createDialog(el));

        el.querySelector("[data-nudge-form]").addEventListener("submit", (event) => {
            event.preventDefault();
            send(el, event.currentTarget).catch((error) => console.error(error));
        });
    }

    fill(el, source);
    dialogs.get(el).open(trigger);
}

/** @param {HTMLElement} root the page; the button may be swapped in later */
export function bindNudge(root) {
    root.addEventListener("click", (event) => {
        const button = event.target.closest("[data-nudge]");

        if (button) {
            open(button);
        }
    });
}
