// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { applyFieldErrors, askForPassword, bindDialogs, createDialog, fillDialog, focusableIn, nextFocus } from "../../../resources/js/shared/dialog.js";

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

    it("opens on load when the server marked it, and leaves the others closed", () => {
        page(`${DIALOG}<div id="prepared" data-dialog data-dialog-autoopen hidden class="hidden"><button id="inside" data-dialog-close>Close</button></div>`);
        bindDialogs();

        expect(document.getElementById("prepared").hidden).toBe(false);
        expect(document.getElementById("d").hidden).toBe(true);
        expect(document.activeElement.id).toBe("inside");
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

describe("a guarded action", () => {
    const PAGE = `
        <form id="f" action="/guarded" method="post" data-dialog-form>
            <div data-field="name"><input name="name" value="kept"><p data-field-error hidden></p></div>
            <p data-form-error hidden></p>
            <button type="submit" id="go">Save</button>
        </form>
        <div id="reauth-dialog" data-dialog hidden class="hidden">
            <form id="rf" action="/reauth" method="post" data-reauth-form>
                <div data-field="password"><input name="password" type="password" value="secret"><p data-field-error hidden></p></div>
                <p data-form-error hidden></p>
                <button type="button" data-dialog-close id="cancel">Cancel</button>
                <button type="submit" id="confirm">Confirm</button>
            </form>
        </div>`;

    const reply = (status, body) => ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
    const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

    const submit = async (id) => {
        document.getElementById(id).dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await tick();
        await tick();
    };

    it("asks for the password, then repeats the action once it is confirmed", async () => {
        page(PAGE);
        bindDialogs();
        const assign = vi.fn();
        vi.stubGlobal("location", { assign, reload: vi.fn() });

        const calls = [];
        vi.stubGlobal("fetch", vi.fn(async (url) => {
            calls.push(url);

            if (url.includes("/guarded")) {
                // Locked the first time, open after the password.
                return calls.filter((u) => u.includes("/guarded")).length === 1
                    ? reply(403, { error: "reauth_required" })
                    : reply(200, { redirect: "/people", message: "Saved." });
            }

            return reply(200, { ok: true });
        }));

        await submit("f");

        expect(document.getElementById("reauth-dialog").hidden).toBe(false);
        expect(document.querySelector('#f input[name="name"]').value).toBe("kept");

        await submit("rf");
        await tick();

        expect(calls.filter((u) => u.includes("/guarded"))).toHaveLength(2);
        expect(document.getElementById("reauth-dialog").hidden).toBe(true);
        expect(assign).toHaveBeenCalledWith("/people");
    });

    it("gives focus back to the button that was pressed when the password dialog is left", async () => {
        page(PAGE);
        bindDialogs();
        vi.stubGlobal("location", { assign: vi.fn(), reload: vi.fn() });
        vi.stubGlobal("fetch", vi.fn(async () => reply(403, { error: "reauth_required" })));

        const go = document.getElementById("go");
        go.focus();

        await submit("f");

        expect(document.getElementById("reauth-dialog").hidden).toBe(false);

        document.getElementById("cancel").click();
        await tick();

        expect(document.getElementById("reauth-dialog").hidden).toBe(true);
        expect(go.disabled).toBe(false);
        expect(document.activeElement).toBe(go);
    });

    it("keeps the dialog open and shows the error for a wrong password", async () => {
        page(PAGE);
        bindDialogs();
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(reply(422, { errors: { password: "That password is not correct." } })));

        const asked = askForPassword();
        await submit("rf");

        expect(document.getElementById("reauth-dialog").hidden).toBe(false);
        expect(document.querySelector("#rf [data-field-error]").textContent).toBe("That password is not correct.");

        document.getElementById("cancel").click();
        await expect(asked).resolves.toBe(false);
    });

    it("does not repeat the action when the dialog is closed, and says nothing was saved", async () => {
        page(PAGE);
        bindDialogs();
        const fetchMock = vi.fn().mockResolvedValue(reply(403, { error: "reauth_required" }));
        vi.stubGlobal("fetch", fetchMock);

        const pending = submit("f");
        await tick();
        document.getElementById("cancel").click();
        await pending;
        await tick();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelector("#f [data-form-error]").textContent).toContain("Nothing was saved");
    });

    it("sends the person to sign in when the session was ended", async () => {
        page(PAGE);
        bindDialogs();
        const assign = vi.fn();
        vi.stubGlobal("location", { assign, reload: vi.fn() });
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(reply(401, { error: "signed_out" })));

        askForPassword();
        await submit("rf");

        expect(assign).toHaveBeenCalledWith("/login");
    });

    it("resolves false when the page has no confirm dialog", async () => {
        page(`<form id="f"></form>`);
        bindDialogs();

        await expect(askForPassword()).resolves.toBe(false);
    });
});

describe("a dialog shared by many rows", () => {
    const SHARED = `
        <button id="edit1" data-dialog-open="d" data-dialog-title="Edit Ann" data-dialog-label="Save"
            data-dialog-action="/people/1" data-dialog-values='{"name":"Ann Lee","active":1,"note":null}'>Edit Ann</button>
        <button id="add" data-dialog-open="d" data-dialog-title="Add a person" data-dialog-action="/people">Add</button>
        <button id="ask" data-dialog-open="d" data-dialog-title="Deactivate Ann?" data-dialog-text="She is signed out." data-dialog-action="/people/1/off">Ask</button>
        <div id="d" data-dialog hidden class="hidden">
            <h2 data-dialog-title>Person</h2>
            <p data-dialog-text>Default text.</p>
            <form id="f" method="post" action="" data-dialog-form>
                <div data-field="name"><input name="name"><p data-field-error hidden></p></div>
                <input name="active" type="checkbox" value="1">
                <input name="note">
                <p data-form-error hidden></p>
                <button type="submit" data-dialog-label>Create</button>
            </form>
        </div>`;

    it("fills the form, title, label and address from the button that opened it", () => {
        page(SHARED);
        bindDialogs();

        document.getElementById("edit1").click();

        const form = document.getElementById("f");
        expect(document.querySelector("#d [data-dialog-title]").textContent).toBe("Edit Ann");
        expect(document.querySelector("#d [data-dialog-label]").textContent).toBe("Save");
        expect(form.getAttribute("action")).toBe("/people/1");
        expect(form.elements.namedItem("name").value).toBe("Ann Lee");
        expect(form.elements.namedItem("active").checked).toBe(true);
        expect(form.elements.namedItem("note").value).toBe("");
        expect(document.getElementById("d").hidden).toBe(false);
    });

    it("empties the form when the next button has no values, and clears old errors", () => {
        page(SHARED);
        bindDialogs();
        document.getElementById("edit1").click();
        applyFieldErrors(document.getElementById("f"), { name: "Enter a name." }, "Nothing was saved.");

        document.getElementById("d").click(); // close on the backdrop
        document.getElementById("add").click();

        const form = document.getElementById("f");
        expect(form.elements.namedItem("name").value).toBe("");
        expect(form.elements.namedItem("active").checked).toBe(false);
        expect(form.getAttribute("action")).toBe("/people");
        expect(form.querySelector("[data-field-error]").hidden).toBe(true);
        expect(form.querySelector("[data-form-error]").hidden).toBe(true);
    });

    it("sets the sentence of a confirm", () => {
        page(SHARED);
        bindDialogs();

        document.getElementById("ask").click();

        expect(document.querySelector("#d [data-dialog-text]").textContent).toBe("She is signed out.");
        expect(document.getElementById("f").getAttribute("action")).toBe("/people/1/off");
    });

    it("ignores values that are not JSON instead of failing", () => {
        page(`
            <button id="b" data-dialog-open="d" data-dialog-values="{broken">Open</button>
            <div id="d" data-dialog hidden><form data-dialog-form><input name="name"></form></div>`);
        bindDialogs();
        document.querySelector("#d input").value = "typed earlier";

        expect(() => document.getElementById("b").click()).not.toThrow();
        expect(document.querySelector("#d input").value).toBe("");
    });

    it("does nothing without a trigger", () => {
        page(SHARED);

        expect(() => fillDialog(document.getElementById("d"), null)).not.toThrow();
    });
});

describe("fields an outside system decides", () => {
    const PAGE = `
        <button id="managed" data-dialog-open="d" data-dialog-values='{"code":"IT101","title":"Intro","note":"x"}' data-dialog-locked='["code","title"]'>Managed</button>
        <button id="own" data-dialog-open="d" data-dialog-values='{"code":"IT102","title":"Ours","note":""}'>Own</button>
        <div id="d" data-dialog hidden class="hidden">
            <p data-dialog-lock hidden>Managed elsewhere.</p>
            <form data-dialog-form method="post" action="">
                <input name="code"><input name="title"><input name="note">
            </form>
        </div>`;

    it("makes the locked fields read-only and shows the note", () => {
        page(PAGE);
        bindDialogs();

        document.getElementById("managed").click();

        const form = document.querySelector("#d form");
        expect(form.elements.namedItem("code").readOnly).toBe(true);
        expect(form.elements.namedItem("title").readOnly).toBe(true);
        expect(form.elements.namedItem("note").readOnly).toBe(false);
        expect(form.elements.namedItem("code").value).toBe("IT101");
        expect(document.querySelector("#d [data-dialog-lock]").hidden).toBe(false);
    });

    it("unlocks them again for a row that is not managed, and hides the note", () => {
        page(PAGE);
        bindDialogs();
        document.getElementById("managed").click();
        document.getElementById("d").click(); // close

        document.getElementById("own").click();

        const form = document.querySelector("#d form");
        expect(form.elements.namedItem("code").readOnly).toBe(false);
        expect(form.elements.namedItem("title").readOnly).toBe(false);
        expect(document.querySelector("#d [data-dialog-lock]").hidden).toBe(true);
    });

    it("ignores a locked list that is not JSON", () => {
        page(`<button id="b" data-dialog-open="d" data-dialog-locked="{nope">Open</button><div id="d" data-dialog hidden><form data-dialog-form><input name="code"></form></div>`);
        bindDialogs();

        expect(() => document.getElementById("b").click()).not.toThrow();
        expect(document.querySelector("#d input").readOnly).toBe(false);
    });
});
