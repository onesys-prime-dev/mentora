// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { applyFieldErrors, bindDialogs, createDialog, focusableIn, nextFocus } from "../../../resources/js/shared/dialog.js";

function page(html) {
    document.body.innerHTML = html;
}

const DIALOG = `
    <button id="opener" data-dialog-open="d">Open</button>
    <button id="outside">Outside</button>
    <div id="d" data-dialog hidden class="hidden">
        <button id="first" data-dialog-close>Close</button>
        <input id="middle" type="text">
        <button id="last" type="submit">Save</button>
    </div>`;

// The dialog as it is while open: the helpers only see visible controls.
const OPEN = DIALOG.replace(" hidden class=\"hidden\"", "");

beforeEach(() => {
    document.body.className = "";
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.restoreAllMocks();
});

describe("focus helpers", () => {
    it("lists focusable controls and skips disabled and hidden ones", () => {
        page(`<div id="r"><button>a</button><button disabled>b</button><input type="hidden"><div hidden><button>c</button></div><a href="#">d</a></div>`);

        expect(focusableIn(document.getElementById("r"))).toHaveLength(2);
    });

    it("wraps Tab from the last control to the first and Shift+Tab the other way", () => {
        page(OPEN);
        const root = document.getElementById("d");

        expect(nextFocus(root, document.getElementById("last"), false).id).toBe("first");
        expect(nextFocus(root, document.getElementById("first"), true).id).toBe("last");
        expect(nextFocus(root, document.getElementById("middle"), false)).toBeNull();
    });

    it("brings focus back in when it is outside", () => {
        page(OPEN);
        const root = document.getElementById("d");

        expect(nextFocus(root, document.getElementById("outside"), false).id).toBe("first");
        expect(nextFocus(root, document.getElementById("outside"), true).id).toBe("last");
    });

    it("keeps focus on the dialog when it has nothing focusable", () => {
        page(`<div id="d" data-dialog hidden></div>`);
        const root = document.getElementById("d");

        expect(nextFocus(root, null, false)).toBe(root);
    });
});

describe("a dialog", () => {
    it("opens from its trigger, focuses inside and stops the page scrolling", () => {
        page(DIALOG);
        bindDialogs();

        document.getElementById("opener").click();

        const dialog = document.getElementById("d");
        expect(dialog.hidden).toBe(false);
        expect(dialog.classList.contains("flex")).toBe(true);
        expect(document.body.classList.contains("overflow-hidden")).toBe(true);
        expect(document.activeElement.id).toBe("first");
    });

    it("closes on its close button and returns focus to the trigger", () => {
        page(DIALOG);
        bindDialogs();
        const opener = document.getElementById("opener");
        opener.focus();
        opener.click();

        document.getElementById("first").click();

        expect(document.getElementById("d").hidden).toBe(true);
        expect(document.body.classList.contains("overflow-hidden")).toBe(false);
        expect(document.activeElement).toBe(opener);
    });

    it("closes on Escape", () => {
        page(DIALOG);
        bindDialogs();
        document.getElementById("opener").click();

        document.getElementById("middle").dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(document.getElementById("d").hidden).toBe(true);
    });

    it("closes on a click on the backdrop but not on its content", () => {
        page(DIALOG);
        bindDialogs();
        document.getElementById("opener").click();

        document.getElementById("middle").click();
        expect(document.getElementById("d").hidden).toBe(false);

        document.getElementById("d").click();
        expect(document.getElementById("d").hidden).toBe(true);
    });

    it("keeps Tab inside", () => {
        page(DIALOG);
        bindDialogs();
        document.getElementById("opener").click();
        document.getElementById("last").focus();

        const event = new KeyboardEvent("keydown", { key: "Tab", bubbles: true, cancelable: true });
        document.getElementById("last").dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement.id).toBe("first");
    });

    it("can be opened twice without stacking listeners", () => {
        page(DIALOG);
        const dialog = createDialog(document.getElementById("d"));

        dialog.open();
        dialog.open();
        expect(dialog.isOpen()).toBe(true);

        dialog.close();
        dialog.close();
        expect(dialog.isOpen()).toBe(false);
    });
});

describe("type to confirm", () => {
    it("enables the confirm button only when the text matches exactly", () => {
        page(`
            <div id="c" data-dialog hidden>
                <input id="typed" data-confirm-match="Ann Lee">
                <button id="go" data-confirm-submit>Deactivate</button>
            </div>`);
        bindDialogs();

        const go = document.getElementById("go");
        const typed = document.getElementById("typed");

        expect(go.disabled).toBe(true);

        typed.value = "ann lee";
        typed.dispatchEvent(new Event("input"));
        expect(go.disabled).toBe(true);

        typed.value = " Ann Lee ";
        typed.dispatchEvent(new Event("input"));
        expect(go.disabled).toBe(false);
    });
});

describe("field errors", () => {
    const FORM = `
        <form id="f">
            <div data-field="name"><input name="name"><p data-field-error hidden></p></div>
            <div data-field="email"><input name="email"><p data-field-error hidden></p></div>
            <p data-form-error hidden></p>
        </form>`;

    it("shows each error beside its field and marks it invalid", () => {
        page(FORM);
        const form = document.getElementById("f");

        applyFieldErrors(form, { name: "Enter a name." });

        const slot = form.querySelector('[data-field="name"] [data-field-error]');
        expect(slot.textContent).toBe("Enter a name.");
        expect(slot.hidden).toBe(false);
        expect(form.querySelector('[name="name"]').getAttribute("aria-invalid")).toBe("true");
        expect(document.activeElement.name).toBe("name");
    });

    it("sends an error for an unknown field to the form-level message", () => {
        page(FORM);
        const form = document.getElementById("f");

        applyFieldErrors(form, { nothing: "Something is wrong." }, "Nothing was saved.");

        const box = form.querySelector("[data-form-error]");
        expect(box.hidden).toBe(false);
        expect(box.textContent).toBe("Nothing was saved. Something is wrong.");
    });

    it("clears earlier errors", () => {
        page(FORM);
        const form = document.getElementById("f");
        applyFieldErrors(form, { name: "Enter a name." }, "Nothing was saved.");

        applyFieldErrors(form, {});

        expect(form.querySelector("[data-field-error]").hidden).toBe(true);
        expect(form.querySelector('[aria-invalid="true"]')).toBeNull();
        expect(form.querySelector("[data-form-error]").hidden).toBe(true);
    });
});

describe("a dialog form", () => {
    const FORM = `
        <form id="f" action="/save" method="post" data-dialog-form>
            <div data-field="name"><input name="name" value=""><p data-field-error hidden></p></div>
            <p data-form-error hidden></p>
            <button type="submit" id="go">Save</button>
        </form>`;

    function answer(status, body) {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }));
    }

    const submit = async () => {
        document.getElementById("f").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));
    };

    it("shows a 422 answer in place, keeps what was typed and re-enables the button", async () => {
        page(FORM);
        bindDialogs();
        answer(422, { errors: { name: "Enter a name." }, message: "Nothing was saved." });

        await submit();

        expect(document.querySelector("[data-field-error]").textContent).toBe("Enter a name.");
        expect(document.querySelector("[data-form-error]").textContent).toBe("Nothing was saved.");
        expect(document.getElementById("go").disabled).toBe(false);
        expect(fetch).toHaveBeenCalledWith(expect.stringContaining("/save"), expect.objectContaining({ method: "POST" }));
    });

    it("says nothing was saved when the server fails", async () => {
        page(FORM);
        bindDialogs();
        answer(500, {});

        await submit();

        expect(document.querySelector("[data-form-error]").textContent).toContain("Nothing was saved");
    });

    it("says so when the network is down", async () => {
        page(FORM);
        bindDialogs();
        vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));

        await submit();

        expect(document.querySelector("[data-form-error]").textContent).toContain("could not reach the server");
        expect(document.getElementById("go").disabled).toBe(false);
    });

    it("goes to the address the server gives on success", async () => {
        page(FORM);
        bindDialogs();
        const assign = vi.fn();
        vi.stubGlobal("location", { assign, reload: vi.fn() });
        answer(200, { redirect: "/people", message: "Saved." });

        await submit();

        expect(assign).toHaveBeenCalledWith("/people");
    });
});
