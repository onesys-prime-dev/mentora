/**
 * The modal dialog (components/dialog.php) and the confirm dialog
 * (components/confirm.php).
 *
 * Markup contract:
 *   <div data-dialog id="x" hidden role="dialog" aria-modal="true" aria-labelledby="x-title"> ... </div>
 *   <button data-dialog-open="x">   opens it
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
    }

    return { open: openDialog, close, isOpen: () => open };
}

/** @param {HTMLFormElement} form */
function bindDialogForm(form) {
    form.addEventListener("submit", async (event) => {
        if (typeof fetch !== "function") {
            return;
        }

        event.preventDefault();

        const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));

        buttons.forEach((b) => (b.disabled = true));
        form.setAttribute("aria-busy", "true");

        try {
            const response = await fetch(form.action, {
                method: (form.method || "post").toUpperCase(),
                body: new FormData(form),
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });

            const body = await response.json().catch(() => ({}));

            if (response.ok) {
                if (body.message) {
                    showToast(body.message, { tone: "success" });
                }

                if (body.redirect) {
                    window.location.assign(body.redirect);
                } else {
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

/** Wires every dialog, opener, confirm box and dialog form under `root`. */
export function bindDialogs(root = document) {
    const dialogs = new Map();

    for (const el of root.querySelectorAll("[data-dialog]")) {
        dialogs.set(el.id, createDialog(el));
    }

    for (const trigger of root.querySelectorAll("[data-dialog-open]")) {
        trigger.addEventListener("click", () => dialogs.get(trigger.dataset.dialogOpen)?.open(trigger));
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

    return dialogs;
}

if (typeof document !== "undefined" && document.querySelector("[data-dialog]")) {
    bindDialogs();
}
