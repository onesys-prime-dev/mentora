/*
|--------------------------------------------------------------------------
| Publish or draft, and schedule a publish, in the Add / Edit modals
|--------------------------------------------------------------------------
| The same three controls on the module, learning unit and learning item
| modals (partials/modals/publish-fields.php):
|
|   - Draft / Publish;
|   - for a draft, a "Schedule publish" switch, and when it is on a date and
|     time picker. A scheduled draft publishes itself at that time;
|   - for a published row under a draft parent, a badge: students will not see
|     it until the parent is published ("Hidden — module is a draft", the same
|     rule as the Hidden chip in the lists).
|
| The form sends `status`, `schedule_publish` and `scheduled_publish_at`; the
| server decides what they mean (ScheduledPublishing::fields).
*/

/** The picker's earliest choice: this minute, in the browser's own time. */
function nowForPicker() {

    const now = new Date();
    const pad = (value) => String(value).padStart(2, "0");

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;

}

/** "2030-03-04 09:30:00" (the database's) as the picker wants it. */
function forPicker(stored) {

    return stored ? String(stored).replace(" ", "T").slice(0, 16) : "";

}

/**
 * @param {HTMLElement|null} root the [data-publish-fields] block
 * @returns {{reset: () => void, fill: (row: {status?: string, scheduledAt?: string}) => void, setHiddenBy: (parent: string|null) => void, lockPublish: (reason: string|null) => void}}
 */
export function bindPublishFields(root) {

    if (!root) {
        return { reset() {}, fill() {}, setHiddenBy() {}, lockPublish() {} };
    }

    const radios = [...root.querySelectorAll("[data-publish-status]")];
    const radio = (value) => radios.find((input) => input.value === value);
    const lock = root.querySelector("[data-publish-lock]");
    const badge = root.querySelector("[data-hidden-badge]");
    const block = root.querySelector("[data-schedule-block]");
    const toggle = root.querySelector("[data-schedule-toggle]");
    const when = root.querySelector("[data-schedule-when]");
    const input = root.querySelector("[data-schedule-input]");

    let hiddenBy = null;

    const published = () => radio("published")?.checked === true;

    function render() {

        const isPublished = published();

        // Nothing to schedule once it is published; the time goes with the switch.
        block?.classList.toggle("hidden", isPublished);

        if (isPublished) {

            if (toggle) {
                toggle.checked = false;
            }

            if (input) {
                input.value = "";
            }

        }

        const scheduling = !isPublished && toggle?.checked === true;

        when?.classList.toggle("hidden", !scheduling);

        if (input) {

            input.required = scheduling;
            input.min = nowForPicker();

            if (!scheduling) {
                input.value = "";
            }

        }

        if (badge) {

            const show = isPublished && hiddenBy !== null;

            badge.classList.toggle("hidden", !show);
            badge.textContent = show ? `Hidden — ${hiddenBy} is a draft` : "";

        }

    }

    radios.forEach((item) => item.addEventListener("change", render));
    toggle?.addEventListener("change", render);

    render();

    return {

        reset() {

            hiddenBy = null;

            if (radio("draft")) {
                radio("draft").checked = true;
            }

            if (toggle) {
                toggle.checked = false;
            }

            if (input) {
                input.value = "";
            }

            this.lockPublish(null);
            render();

        },

        fill({ status = "draft", scheduledAt = "" } = {}) {

            const choice = radio(status) ?? radio("draft");

            if (choice) {
                choice.checked = true;
            }

            if (toggle) {
                toggle.checked = status !== "published" && Boolean(scheduledAt);
            }

            if (input) {
                input.value = forPicker(scheduledAt);
            }

            render();

            // render() empties a time the switch does not hold: put it back for a scheduled draft.
            if (toggle?.checked && input) {
                input.value = forPicker(scheduledAt);
            }

        },

        /** @param {string|null} parent "module" or "unit" when the row would sit under a draft parent */
        setHiddenBy(parent) {

            hiddenBy = parent;
            render();

        },

        /** Keeps the row a draft, with the reason shown; null allows publishing again. */
        lockPublish(reason) {

            const publish = radio("published");

            if (publish) {
                publish.disabled = reason !== null;
            }

            if (reason !== null && radio("draft")) {
                radio("draft").checked = true;
            }

            if (lock) {
                lock.textContent = reason ?? "";
                lock.classList.toggle("hidden", reason === null);
            }

            render();

        },

    };

}
