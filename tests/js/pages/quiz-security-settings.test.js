import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
    bindQuizSecurity,
    generatePassword,
} from "../../../resources/js/pages/courses/features/assessments/inspector/quiz-security.js";

/** A trimmed copy of the password section of partials/editor-inspector-panel.php and of modals/quiz-password.php. */
const markup = (hasPassword, requirePassword) => `
    <div data-quiz-password data-has-password="${hasPassword ? "1" : "0"}">
        <input type="checkbox" data-inspector-field="require_password" data-password-toggle ${requirePassword ? "checked" : ""}>
        <div data-password-state class="hidden">
            <button type="button" data-password-create>Create password</button>
            <div data-password-set><span data-password-mask>******</span><button type="button" data-password-edit aria-label="Edit password">edit</button></div>
        </div>
    </div>

    <div id="quiz-password-modal" class="hidden" role="dialog" aria-modal="true">
        <button type="button" data-close-password-modal aria-label="Close">x</button>
        <input type="text" data-password-input>
        <p data-password-error></p>
        <button type="button" data-password-generate>Generate another</button>
        <button type="button" data-close-password-modal>Cancel</button>
        <button type="button" data-password-save>Save</button>
    </div>`;

const $ = (selector) => document.querySelector(selector);
const open = () => !$("#quiz-password-modal").classList.contains("hidden");
const shown = (selector) => $(selector).closest(".hidden") === null;
const click = (selector) => $(selector).dispatchEvent(new MouseEvent("click", { bubbles: true }));
const toggle = (on) => {
    $("[data-password-toggle]").checked = on;
    $("[data-password-toggle]").dispatchEvent(new Event("change", { bubbles: true }));
};

let savePassword;
let notify;

const bind = (hasPassword = false, requirePassword = false, save = vi.fn().mockResolvedValue({ success: true, message: "Password saved." })) => {
    document.body.innerHTML = markup(hasPassword, requirePassword);
    savePassword = save;
    notify = vi.fn();

    return bindQuizSecurity({ savePassword, notify });
};

beforeEach(() => {
    vi.stubGlobal("crypto", { getRandomValues: (array) => crypto_fill(array) });
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

// A deterministic stand-in for the browser's generator, different on every call.
let seed = 7;
function crypto_fill(array) {
    for (let i = 0; i < array.length; i++) {
        seed = (seed * 1103515245 + 12345) & 0x7fffffff;
        array[i] = (seed >> 16) & 0xff;
    }

    return array;
}

describe("generatePassword", () => {
    it("makes ten characters that are easy to read out (no 0, O, 1, l, I)", () => {
        for (let i = 0; i < 20; i++) {
            expect(generatePassword()).toMatch(/^[A-HJ-NP-Za-km-z2-9]{10}$/);
        }
    });

    it("is different each time", () => {
        expect(new Set(Array.from({ length: 20 }, () => generatePassword())).size).toBe(20);
    });
});

describe("what the password section shows", () => {
    it("shows nothing while the password is off", () => {
        bind(false, false);

        expect(shown("[data-password-state]")).toBe(false);
    });

    it("shows a Create password button when it is switched on and none exists yet", () => {
        bind(false, false);

        toggle(true);

        expect(shown("[data-password-state]")).toBe(true);
        expect(shown("[data-password-create]")).toBe(true);
        expect(shown("[data-password-set]")).toBe(false);
    });

    it("shows ****** with an edit button when a password exists", () => {
        bind(true, true);

        expect(shown("[data-password-state]")).toBe(true);
        expect(shown("[data-password-set]")).toBe(true);
        expect($("[data-password-mask]").textContent).toBe("******");
        expect(shown("[data-password-create]")).toBe(false);
    });

    it("hides it all again when switched off", () => {
        bind(true, true);

        toggle(false);

        expect(shown("[data-password-state]")).toBe(false);
    });
});

describe("the password modal", () => {
    it("opens from Create with a generated password in a plain text field", () => {
        bind(false, true);

        click("[data-password-create]");

        expect(open()).toBe(true);
        expect($("[data-password-input]").type).toBe("text");
        expect($("[data-password-input]").value).toMatch(/^[A-HJ-NP-Za-km-z2-9]{10}$/);
    });

    it("opens from the edit button too, with a new generated password (the old one is never shown)", () => {
        bind(true, true);

        click("[data-password-edit]");

        expect(open()).toBe(true);
        expect($("[data-password-input]").value).toMatch(/^[A-HJ-NP-Za-km-z2-9]{10}$/);
    });

    it("lets the teacher erase it and type their own, and Generate another makes a different one", () => {
        bind(false, true);
        click("[data-password-create]");
        const first = $("[data-password-input]").value;

        click("[data-password-generate]");

        expect($("[data-password-input]").value).not.toBe(first);

        $("[data-password-input]").value = "My own phrase";
        expect($("[data-password-input]").value).toBe("My own phrase");
    });

    it("Save sends what is in the field (surrounding spaces dropped), then shows ****** and closes", async () => {
        bind(false, true);
        click("[data-password-create]");
        $("[data-password-input]").value = "  Red-Kite-77 ";

        click("[data-password-save]");
        await vi.waitFor(() => expect(open()).toBe(false));

        expect(savePassword).toHaveBeenCalledWith("Red-Kite-77");
        expect($("[data-quiz-password]").dataset.hasPassword).toBe("1");
        expect(shown("[data-password-set]")).toBe(true);
        expect(shown("[data-password-create]")).toBe(false);
        expect(notify).toHaveBeenCalledWith("Password saved.", "success");
    });

    it("asks for a password rather than saving an empty one", () => {
        bind(false, true);
        click("[data-password-create]");
        $("[data-password-input]").value = "   ";

        click("[data-password-save]");

        expect(savePassword).not.toHaveBeenCalled();
        expect($("[data-password-error]").textContent).toMatch(/type a password/i);
        expect(open()).toBe(true);
    });

    it("keeps the modal open and shows the server's reason when the password is refused", async () => {
        bind(false, true, vi.fn().mockResolvedValue({ success: false, message: "The password must be at least 4 characters." }));
        click("[data-password-create]");
        $("[data-password-input]").value = "ab";

        click("[data-password-save]");
        await vi.waitFor(() => expect($("[data-password-error]").textContent).toContain("at least 4"));

        expect(open()).toBe(true);
        expect($("[data-quiz-password]").dataset.hasPassword).toBe("0");
    });

    it("keeps the modal open with a sentence when the request itself fails", async () => {
        bind(false, true, vi.fn().mockRejectedValue(new Error("offline")));
        click("[data-password-create]");

        click("[data-password-save]");
        await vi.waitFor(() => expect($("[data-password-error]").textContent).not.toBe(""));

        expect(open()).toBe(true);
    });

    it("Cancel, the X and Escape close it and change nothing", () => {
        bind(true, true);

        for (const close of [
            () => document.querySelectorAll("[data-close-password-modal]")[0].click(),
            () => document.querySelectorAll("[data-close-password-modal]")[1].click(),
            () => document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true })),
        ]) {
            click("[data-password-edit]");
            expect(open()).toBe(true);

            close();
            expect(open()).toBe(false);
        }

        expect(savePassword).not.toHaveBeenCalled();
        expect($("[data-quiz-password]").dataset.hasPassword).toBe("1");
    });

    it("does not send twice while the first save is still on its way", async () => {
        let finish;
        bind(false, true, vi.fn().mockImplementation(() => new Promise((resolve) => (finish = resolve))));
        click("[data-password-create]");

        click("[data-password-save]");
        click("[data-password-save]");

        expect(savePassword).toHaveBeenCalledTimes(1);
        expect($("[data-password-save]").disabled).toBe(true);

        finish({ success: true, message: "Password saved." });
        await vi.waitFor(() => expect(open()).toBe(false));
        expect($("[data-password-save]").disabled).toBe(false);
    });
});

describe("saving the settings with the password switched on", () => {
    it("is a problem when no password has been created yet, and says what to do", () => {
        const security = bind(false, true);

        expect(security.passwordProblem()).toMatch(/Create a password/);
    });

    it("is fine with a password, or with the password off", () => {
        expect(bind(true, true).passwordProblem()).toBeNull();
        expect(bind(false, false).passwordProblem()).toBeNull();
    });
});

describe("a page without the section (an assignment)", () => {
    it("does nothing and does not fail", () => {
        document.body.innerHTML = "";

        const security = bindQuizSecurity({ savePassword: vi.fn(), notify: vi.fn() });

        expect(security).not.toBeNull();
        expect(security.passwordProblem()).toBeNull();
    });
});
