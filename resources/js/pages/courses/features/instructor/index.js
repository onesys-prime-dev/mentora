import { beginLoad } from "../../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../../shared/spa/error-state.js";
import { renderInstructorSkeleton } from "./skeleton.js";

/*
|--------------------------------------------------------------------------
| From the instructor (a parent's tab)
|--------------------------------------------------------------------------
| The server draws the read-only list. The one control is "Message <name>":
| it starts (or reopens) the parent's thread with that instructor about the
| child, then opens it in Messages. The server decides who may; a refusal
| only shows a note.
|--------------------------------------------------------------------------
*/

// Replaced in tests: jsdom cannot navigate.
export const navigation = { go: (url) => window.location.assign(url) };

/** The site's root, taken from this page's own API address, so a sub-folder install still works. */
function siteBase() {

    const base = window.coursesConfig.apiBaseUrl;
    const at = base.indexOf("/parent/children/");

    return at === -1 ? "" : base.slice(0, at);

}

export async function initialize()
{
    const container =
        document.querySelector("#course-content");

    const load = beginLoad(container, renderInstructorSkeleton());

    try {

        const response = await fetch(

            `${window.coursesConfig.apiBaseUrl}/instructor`,

            {

                headers: {

                    "X-Requested-With": "XMLHttpRequest",

                    "Accept": "application/json",

                },

            }

        );

        if (!response.ok) {

            throw new Error("Unable to load From the instructor.");

        }

        const data = await response.json();

        if (!load.current()) {
            return;
        }

        load.finish();

        container.innerHTML =
            data.html;

        bindMessageButtons(container);

    }

    catch (error) {

        console.error(error);

        if (!load.current()) {
            return;
        }

        load.finish();

        showLoadError(container, "From the instructor", initialize);

    }
}

function bindMessageButtons(container) {

    const buttons = () => [...container.querySelectorAll("[data-parent-message]")];
    const note = container.querySelector("[data-parent-message-note]");
    let busy = false;

    const say = (text) => {

        if (note) {
            note.textContent = text;
            note.hidden = text === "";
        }

    };

    container.addEventListener("click", async (event) => {

        const button = event.target.closest("[data-parent-message]");

        if (!button || busy) {
            return;
        }

        busy = true;
        say("");
        buttons().forEach((b) => { b.disabled = true; });

        const id = await startThread(button.dataset.instructorId, button.dataset.childId);

        if (id === null) {

            say("You can't message them right now");
            buttons().forEach((b) => { b.disabled = false; });
            busy = false;

            return;

        }

        // Left disabled: the page is about to change.
        navigation.go(`${siteBase()}/messages/${id}`);

    });

}

/** The id of the conversation the server started or reopened, or null when it refused or could not be reached. */
async function startThread(instructorId, childId) {

    try {

        const response = await fetch(`${siteBase()}/messages/api/conversations`, {

            method: "POST",

            headers: { "Accept": "application/json", "Content-Type": "application/json" },

            credentials: "same-origin",

            body: JSON.stringify({

                recipient_id: Number(instructorId),

                subject_student_id: Number(childId),

            }),

        });

        const data = await response.json().catch(() => null);

        return response.ok ? data?.conversation?.id ?? null : null;

    } catch {

        return null;

    }

}
