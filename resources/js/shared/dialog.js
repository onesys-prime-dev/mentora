/**
 * The modal dialog (components/dialog.php) and the confirm dialog
 * (components/confirm.php).
 *
 * Markup contract:
 *   <div data-dialog id="x" hidden role="dialog" aria-modal="true" aria-labelledby="x-title"> ... </div>
 *   <button data-dialog-open="x">   opens it
 *   data-dialog-autoopen              on the dialog: opens it when the page loads
 *   <button data-dialog-close>      closes the dialog it sits in
 *   <form data-dialog-form>         submitted without leaving the page (below)
 *   <input data-confirm-match="Ann Lee"> inside a dialog: its
 *   [data-confirm-submit] button stays disabled until the text is typed exactly
 *
 * What a dialog does: moves focus in, keeps Tab inside, closes on Escape or a
 * click on the backdrop, puts focus back where it was, and stops the page
 * behind it from scrolling.
 *
 * A [data-dialog-form] is posted with fetch and the server answers JSON:
 *   200  { redirect?: string, message?: string }   go there / show a toast
 *   422  { errors: { field: "message" }, message?: string }   shown in place
 * Anything else shows a general message and keeps what was typed. The page
 * works without JavaScript too: the form simply posts normally.
 */

import { showToast } from "./toast.js";
import { startPageProgress } from "./spa/page-progress.js";

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** @param {ParentNode} root */
export function focusableIn(root) {
    return Array.from(root.querySelectorAll(FOCUSABLE)).filter((el) => !el.hasAttribute("hidden") && !el.closest("[hidden]"));
}

/**
 * Where Tab or Shift+Tab should go so focus never leaves `root`. Null means
 * the browser's own move is fine.
 *
 * @param {HTMLElement} root
 * @param {Element | null} active
 * @param {boolean} shift
 * @returns {HTMLElement | null}
 */
export function nextFocus(root, active, shift) {
    const items = focusableIn(root);

    if (items.length === 0) {
        return root;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (!root.contains(active)) {
        return shift ? last : first;
    }

    if (shift && active === first) {
        return last;
    }

    if (!shift && active === last) {
        return first;
    }

    return null;
}

/**
 * Shows server-side field errors next to their fields.
 *
 * Fields are wrapped as <div data-field="name"> with a <p data-field-error>;
 * a name with no such field goes to the form's [data-form-error].
 *
 * @param {HTMLFormElement} form
 * @param {Record<string, string>} errors
 * @param {string} [general] shown in [data-form-error] when given
 */
export function applyFieldErrors(form, errors, general = "") {
    for (const el of form.querySelectorAll("[data-field-error]")) {
        el.textContent = "";
        el.hidden = true;
    }

    for (const el of form.querySelectorAll('[aria-invalid="true"]')) {
        el.removeAttribute("aria-invalid");
    }

    const leftOver = [];

    for (const [name, message] of Object.entries(errors)) {
        const field = Array.from(form.querySelectorAll("[data-field]")).find((el) => el.dataset.field === name);
        const slot = field?.querySelector("[data-field-error]");

        if (!field || !slot) {
            leftOver.push(message);

            continue;
        }

        slot.textContent = message;
        slot.hidden = false;
        field.querySelector("input, select, textarea")?.setAttribute("aria-invalid", "true");
    }

    const box = form.querySelector("[data-form-error]");

    if (box) {
        const text = [general, ...leftOver].filter(Boolean).join(" ");

        box.textContent = text;
        box.hidden = text === "";
    }

    form.querySelector('[aria-invalid="true"]')?.focus();
}

/**
 * @param {HTMLElement} el the [data-dialog] element
 */
export function createDialog(el) {
    let opener = null;
    let open = false;

    const onKeydown = (event) => {
        if (event.key === "Escape") {
            event.stopPropagation();
            close();

            return;
        }

        if (event.key === "Tab") {
            const target = nextFocus(el, document.activeElement, event.shiftKey);

            if (target) {
                event.preventDefault();
                target.focus();
            }
        }
    };

    const onClick = (event) => {
        if (event.target === el || event.target.closest("[data-dialog-close]")) {
            close();
        }
    };

    function openDialog(trigger = null) {
        if (open) {
            return;
        }

        open = true;
        opener = trigger ?? document.activeElement;
        el.hidden = false;
        el.classList.remove("hidden");
        el.classList.add("flex");
        document.body.classList.add("overflow-hidden");
        el.addEventListener("keydown", onKeydown);
        el.addEventListener("click", onClick);

        const wanted = el.querySelector("[autofocus]") ?? focusableIn(el)[0] ?? el;

        wanted.focus();
    }

    function close() {
        if (!open) {
            return;
        }

        open = false;
        el.hidden = true;
        el.classList.add("hidden");
        el.classList.remove("flex");
        document.body.classList.remove("overflow-hidden");
        el.removeEventListener("keydown", onKeydown);
        el.removeEventListener("click", onClick);

        if (opener && typeof opener.focus === "function") {
            opener.focus();
        }

        opener = null;
        el.dispatchEvent(new CustomEvent("dialog:close"));
    }

    return { open: openDialog, close, isOpen: () => open };
}

const REAUTH_ID = "reauth-dialog";

/** Dialogs created by bindDialogs(), by id, so a script can open one. */
const registry = new Map();

/**
 * Asks for the person's password in the page's confirm dialog
 * (layouts/partials/reauth-dialog.php) and resolves true once it was
 * confirmed, false if they closed it or there is no such dialog. This is how
 * a guarded action resumes without losing what was typed.
 *
 * @returns {Promise<boolean>}
 */
export function askForPassword() {
    const el = document.getElementById(REAUTH_ID);
    const dialog = registry.get(REAUTH_ID);

    if (!el || !dialog) {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        el.dataset.confirmed = "";

        el.addEventListener(
            "dialog:close",
            () => {
                const confirmed = el.dataset.confirmed === "1";
                el.dataset.confirmed = "";
                resolve(confirmed);
            },
            { once: true },
        );

        dialog.open();
    });
}

/** @param {HTMLFormElement} form the password form inside the confirm dialog */
function bindReauthForm(form) {
    form.addEventListener("submit", async (event) => {
        if (typeof fetch !== "function") {
            return;
        }

        event.preventDefault();

        const button = form.querySelector('button[type="submit"]');

        if (button) {
            button.disabled = true;
        }

        try {
            const response = await fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });

            const body = await response.json().catch(() => ({}));

            if (response.status === 401) {
                startPageProgress();
                window.location.assign("/login");

                return;
            }

            if (response.ok) {
                const el = form.closest("[data-dialog]");
                form.reset();
                applyFieldErrors(form, {});

                if (el) {
                    el.dataset.confirmed = "1";
                    registry.get(el.id)?.close();
                }

                return;
            }

            applyFieldErrors(form, body.errors ?? {}, body.message ?? "");
        } catch {
            applyFieldErrors(form, {}, "We could not reach the server. Check your connection and try again.");
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    });
}

/** @param {HTMLFormElement} form */
function bindDialogForm(form) {
    form.addEventListener("submit", async (event) => {
        if (typeof fetch !== "function") {
            return;
        }

        event.preventDefault();

        const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));

        // Where focus returns to when the password dialog closes: the button that was pressed.
        const returnTo = event.submitter ?? (document.activeElement instanceof HTMLElement ? document.activeElement : null);

        buttons.forEach((b) => (b.disabled = true));
        form.setAttribute("aria-busy", "true");

        const send = async () => {
            const response = await fetch(form.action, {
                method: (form.method || "post").toUpperCase(),
                body: new FormData(form),
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });

            return { response, body: await response.json().catch(() => ({})) };
        };

        try {
            let { response, body } = await send();

            // A guarded action: ask for the password, then do the same thing again, once.
            if (response.status === 403 && body.error === "reauth_required") {
                // A disabled button cannot hold focus, so give it back before the dialog opens: that is
                // where focus returns to when the dialog closes, confirmed or not.
                buttons.forEach((b) => (b.disabled = false));
                returnTo?.focus();

                const confirmed = await askForPassword();

                if (confirmed) {
                    buttons.forEach((b) => (b.disabled = true));
                    ({ response, body } = await send());
                }
            }

            if (response.status === 403 && body.error === "reauth_required") {
                applyFieldErrors(form, {}, "Confirm your password to continue. Nothing was saved.");

                return;
            }

            if (response.ok) {
                if (body.message) {
                    showToast(body.message, { tone: "success" });
                }

                if (body.redirect) {
                    startPageProgress();
                    window.location.assign(body.redirect);
                } else {
                    startPageProgress();
                    window.location.reload();
                }

                return;
            }

            if (response.status === 422 && body.errors) {
                applyFieldErrors(form, body.errors, body.message ?? "");

                return;
            }

            applyFieldErrors(form, {}, body.message ?? "That did not work. Nothing was saved. Please try again.");
        } catch {
            applyFieldErrors(form, {}, "We could not reach the server. Nothing was saved. Check your connection and try again.");
        } finally {
            buttons.forEach((b) => (b.disabled = false));
            form.removeAttribute("aria-busy");
        }
    });
}

/**
 * Fills a shared dialog from the button that opens it, so one "edit" dialog
 * serves every row of a list:
 *
 *   data-dialog-action="/x/5"        the form's address
 *   data-dialog-title="Edit person"  the heading
 *   data-dialog-text="..."           the [data-dialog-text] sentence (confirm)
 *   data-dialog-label="Save"         the [data-dialog-label] button's text
 *   data-dialog-values='{"name":..}' the form's fields (no values: the form is emptied)
 *
 * Earlier errors are cleared so a reopened dialog starts clean.
 *
 * @param {HTMLElement} dialogEl
 * @param {HTMLElement | null} trigger
 */
export function fillDialog(dialogEl, trigger) {
    if (!trigger) {
        return;
    }

    const data = trigger.dataset;
    const form = dialogEl.querySelector("form");

    if (data.dialogTitle) {
        const title = dialogEl.querySelector("[data-dialog-title]");

        if (title) {
            title.textContent = data.dialogTitle;
        }
    }

    if (data.dialogText) {
        const text = dialogEl.querySelector("[data-dialog-text]");

        if (text) {
            text.textContent = data.dialogText;
        }
    }

    if (data.dialogLabel) {
        const label = dialogEl.querySelector("[data-dialog-label]");

        if (label) {
            label.textContent = data.dialogLabel;
        }
    }

    if (!form) {
        return;
    }

    if (data.dialogAction) {
        form.setAttribute("action", data.dialogAction);
    }

    if ("dialogValues" in data || form.matches("[data-dialog-form]")) {
        form.reset();

        let values;

        try {
            values = data.dialogValues ? JSON.parse(data.dialogValues) : {};
        } catch {
            values = {};
        }

        for (const [name, value] of Object.entries(values)) {
            const field = form.elements.namedItem(name);

            if (field && "value" in field) {
                if (field.type === "checkbox") {
                    field.checked = Boolean(value);
                } else {
                    field.value = String(value ?? "");
                }
            }
        }

        applyFieldErrors(form, {});
    }

    // Fields a source of truth outside Mentora decides (the SIS): shown, not editable, with the note.
    for (const el of form.elements) {
        if ("readOnly" in el && el.dataset.wasLocked === "1") {
            el.readOnly = false;
            delete el.dataset.wasLocked;
        }
    }

    let locked;

    try {
        locked = data.dialogLocked ? JSON.parse(data.dialogLocked) : [];
    } catch {
        locked = [];
    }

    for (const name of locked) {
        const field = form.elements.namedItem(name);

        if (field && "readOnly" in field) {
            field.readOnly = true;
            field.dataset.wasLocked = "1";
        }
    }

    const note = dialogEl.querySelector("[data-dialog-lock]");

    if (note) {
        note.hidden = locked.length === 0;
    }

    const match = dialogEl.querySelector("[data-confirm-match]");

    if (match) {
        match.value = "";
        match.dispatchEvent(new Event("input"));
    }
}

/** Wires every dialog, opener, confirm box and dialog form under `root`. */
export function bindDialogs(root = document) {
    const dialogs = new Map();

    for (const el of root.querySelectorAll("[data-dialog]")) {
        const dialog = createDialog(el);

        dialogs.set(el.id, dialog);
        registry.set(el.id, dialog);
    }

    for (const trigger of root.querySelectorAll("[data-dialog-open]")) {
        trigger.addEventListener("click", () => {
            const id = trigger.dataset.dialogOpen;
            const el = id ? document.getElementById(id) : null;

            if (el) {
                fillDialog(el, trigger);
            }

            dialogs.get(id)?.open(trigger);
        });
    }

    // A dialog the server prepared for what the address asked for opens on load.
    for (const el of root.querySelectorAll("[data-dialog-autoopen]")) {
        dialogs.get(el.id)?.open(null);
    }

    for (const input of root.querySelectorAll("[data-confirm-match]")) {
        const submit = input.closest("[data-dialog]")?.querySelector("[data-confirm-submit]");

        if (!submit) {
            continue;
        }

        const check = () => {
            submit.disabled = input.value.trim() !== input.dataset.confirmMatch;
        };

        input.addEventListener("input", check);
        check();
    }

    for (const form of root.querySelectorAll("form[data-dialog-form]")) {
        bindDialogForm(form);
    }

    for (const form of root.querySelectorAll("form[data-reauth-form]")) {
        bindReauthForm(form);
    }

    return dialogs;
}

// A page with a form that answers in place (no dialog around it) needs the binding too.
if (typeof document !== "undefined" && document.querySelector("[data-dialog], form[data-dialog-form], form[data-reauth-form]")) {
    bindDialogs();
}
