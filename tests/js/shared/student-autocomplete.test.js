import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const URL_BASE = "/institution/enrolments/suggest?offering=5";

const ANN = { name: "Ann Lee", student_number: "2026-001", email: "ann.lee@school.test" };
const BEN = { name: "Ben Ng", student_number: "2026-002", email: "ben.ng@school.test" };

const load = async () => {
    vi.resetModules();

    return import("../../../resources/js/shared/student-autocomplete.js");
};

const mount = () => {
    document.body.innerHTML = `
        <div data-dialog id="d">
            <div data-field="students">
                <textarea id="t" name="students" data-student-autocomplete="${URL_BASE}"></textarea>
            </div>
            <textarea id="plain" name="other"></textarea>
        </div>`;

    return document.querySelector("#t");
};

/** Types text into the textarea with the caret at the end, the way a person does. */
const type = (textarea, text, caret = text.length) => {
    textarea.value = text;
    textarea.setSelectionRange(caret, caret);
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
};

const answer = (students, { ok = true } = {}) => ({ ok, status: ok ? 200 : 500, json: async () => ({ students }) });

const options = () => [...document.querySelectorAll('[role="option"]')];

const key = (textarea, k) => {
    const event = new KeyboardEvent("keydown", { key: k, bubbles: true, cancelable: true });
    textarea.dispatchEvent(event);

    return event;
};

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
});

describe("which piece of the box is searched", () => {
    it("is the entry the caret is in, whatever separates the entries", async () => {
        const { currentPiece } = await load();

        expect(currentPiece("ann@x.test\nbe", 13)).toEqual({ start: 11, end: 13, query: "be" });
        expect(currentPiece("ann@x.test, ben", 15)).toEqual({ start: 12, end: 15, query: "ben" });
        expect(currentPiece("a;b c", 3)).toEqual({ start: 2, end: 3, query: "b" });
        expect(currentPiece("ann@x.test\nben@x.test", 4)).toEqual({ start: 0, end: 10, query: "ann@x.test" }, 'the first entry when the caret is in it, though more follow');
    });

    it("is nothing right after a separator", async () => {
        const { currentPiece } = await load();

        expect(currentPiece("ann@x.test\n", 11)).toEqual({ start: 11, end: 11, query: "" });
        expect(currentPiece("", 0)).toEqual({ start: 0, end: 0, query: "" });
    });
});

describe("what picking puts in the box", () => {
    it("replaces the piece with the email and starts a new line", async () => {
        const { replacePiece } = await load();

        expect(replacePiece("be", { start: 0, end: 2 }, "ben@x.test")).toEqual({ text: "ben@x.test\n", caret: 11 });
        expect(replacePiece("ann@x.test\nbe", { start: 11, end: 13 }, "ben@x.test")).toEqual({ text: "ann@x.test\nben@x.test\n", caret: 22 });
    });

    it("leaves the entries after it alone and adds no blank line when one already follows", async () => {
        const { replacePiece } = await load();

        expect(replacePiece("an\nben@x.test", { start: 0, end: 2 }, "ann@x.test")).toEqual({ text: "ann@x.test\nben@x.test", caret: 10 });
        expect(replacePiece("an, ben", { start: 0, end: 2 }, "ann@x.test")).toEqual({ text: "ann@x.test, ben", caret: 10 });
    });
});

describe("the suggestions", () => {
    it("are asked for after a pause, from the address on the box, with what was typed", async () => {
        const fetchMock = vi.fn().mockResolvedValue(answer([ANN, BEN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        type(box, "an");
        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const url = new URL(fetchMock.mock.calls[0][0], "http://localhost");
        expect(url.pathname).toBe("/institution/enrolments/suggest");
        expect(url.searchParams.get("offering")).toBe("5");
        expect(url.searchParams.get("q")).toBe("an");
        expect(fetchMock.mock.calls[0][1].headers.Accept).toBe("application/json");
    });

    it("show each student's name, number and email, and mark the box as a combobox that is open", async () => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(answer([ANN, BEN])));
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        expect(box.getAttribute("role")).toBe("combobox");
        expect(box.getAttribute("aria-expanded")).toBe("false");

        type(box, "an");
        await vi.advanceTimersByTimeAsync(300);

        expect(options()).toHaveLength(2);
        expect(options()[0].textContent).toContain("Ann Lee");
        expect(options()[0].textContent).toContain("2026-001");
        expect(options()[0].textContent).toContain("ann.lee@school.test");
        expect(box.getAttribute("aria-expanded")).toBe("true");
        expect(document.getElementById(box.getAttribute("aria-controls"))).not.toBeNull();
    });

    it("open right under the box, never over the text being typed", async () => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(answer([ANN])));
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        // Where the box sits inside its (positioned) field, which is what the list is placed against.
        Object.defineProperty(box, "offsetTop", { value: 30 });
        Object.defineProperty(box, "offsetHeight", { value: 80 });

        type(box, "an");
        await vi.advanceTimersByTimeAsync(300);

        expect(document.getElementById(box.getAttribute("aria-controls")).parentElement.style.top).toBe("114px");
        expect(box.parentElement.classList.contains("relative")).toBe(true);
    });

    it("are not asked for with fewer than two characters, and an open list closes", async () => {
        const fetchMock = vi.fn().mockResolvedValue(answer([ANN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        type(box, "a");
        await vi.advanceTimersByTimeAsync(300);
        expect(fetchMock).not.toHaveBeenCalled();

        type(box, "an");
        await vi.advanceTimersByTimeAsync(300);
        expect(options()).toHaveLength(1);

        type(box, "a");
        await vi.advanceTimersByTimeAsync(300);
        expect(options()).toHaveLength(0);
        expect(box.getAttribute("aria-expanded")).toBe("false");
    });

    it("are not asked for once the form is sent, so the save's message is not used up", async () => {
        const fetchMock = vi.fn().mockResolvedValue(answer([ANN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        document.body.innerHTML = `<form id="f"><textarea name="students" data-student-autocomplete="${URL_BASE}"></textarea></form>`;
        const box = document.querySelector("textarea");
        document.querySelector("#f").addEventListener("submit", (event) => event.preventDefault());
        initStudentAutocomplete();

        type(box, "ann@x.test");
        box.form.dispatchEvent(new Event("submit", { cancelable: true }));
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("are asked for once while someone types, not once per key", async () => {
        const fetchMock = vi.fn().mockResolvedValue(answer([ANN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        for (const text of ["an", "ann", "ann.", "ann.l"]) {
            type(box, text);
            await vi.advanceTimersByTimeAsync(80);
        }

        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(new URL(fetchMock.mock.calls[0][0], "http://localhost").searchParams.get("q")).toBe("ann.l");
    });

    it("search only the entry being typed, not the ones already in the box", async () => {
        const fetchMock = vi.fn().mockResolvedValue(answer([BEN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        type(box, "ann.lee@school.test\nbe");
        await vi.advanceTimersByTimeAsync(300);

        expect(new URL(fetchMock.mock.calls[0][0], "http://localhost").searchParams.get("q")).toBe("be");
    });

    it("drop an answer that arrives after a newer question", async () => {
        let resolveFirst;
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
            .mockResolvedValueOnce(answer([BEN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        type(box, "an");
        await vi.advanceTimersByTimeAsync(300);
        type(box, "be");
        await vi.advanceTimersByTimeAsync(300);

        expect(options().map((o) => o.textContent)).toEqual([expect.stringContaining("Ben Ng")]);

        resolveFirst(answer([ANN]));
        await vi.advanceTimersByTimeAsync(0);

        expect(options().map((o) => o.textContent)).toEqual([expect.stringContaining("Ben Ng")]);
    });

    it("say so when nobody matches, and close quietly when the request fails", async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(answer([])).mockRejectedValueOnce(new Error("offline"));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        type(box, "zz");
        await vi.advanceTimersByTimeAsync(300);
        expect(document.body.textContent).toContain("No matching students");
        expect(options()).toHaveLength(0);

        type(box, "zzz");
        await vi.advanceTimersByTimeAsync(300);
        expect(document.body.textContent).not.toContain("No matching students");
        expect(box.getAttribute("aria-expanded")).toBe("false");
    });

    it("show names and emails as plain text, never as markup", async () => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(answer([{ name: "<img src=x onerror=alert(1)>", student_number: "", email: "x@school.test" }])));
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();

        type(box, "xx");
        await vi.advanceTimersByTimeAsync(300);

        expect(document.querySelector("img")).toBeNull();
        expect(options()[0].textContent).toContain("<img src=x onerror=alert(1)>");
    });

    it("leave a box that is not marked alone", async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        mount();
        initStudentAutocomplete();

        const plain = document.querySelector("#plain");
        type(plain, "anything");
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(plain.getAttribute("role")).toBeNull();
    });

    it("are wired up only once, even if the page asks twice", async () => {
        const fetchMock = vi.fn().mockResolvedValue(answer([ANN]));
        vi.stubGlobal("fetch", fetchMock);
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();
        initStudentAutocomplete();

        type(box, "an");
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe("picking one", () => {
    const open = async (text = "an") => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(answer([ANN, BEN])));
        const { initStudentAutocomplete } = await load();
        const box = mount();
        initStudentAutocomplete();
        type(box, text);
        await vi.advanceTimersByTimeAsync(300);

        return box;
    };

    it("with the mouse fills the email into the box in place of what was typed, and closes the list", async () => {
        const box = await open("ann.lee@school.test\nbe");

        options()[1].dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));

        expect(box.value).toBe("ann.lee@school.test\nben.ng@school.test\n");
        expect(box.selectionStart).toBe(box.value.length);
        expect(options()).toHaveLength(0);
        expect(box.getAttribute("aria-expanded")).toBe("false");
    });

    it("keeps the mouse press from taking focus out of the box", async () => {
        const box = await open();

        const press = new MouseEvent("mousedown", { bubbles: true, cancelable: true });
        options()[0].dispatchEvent(press);

        expect(press.defaultPrevented).toBe(true);
        expect(box.value).toBe("ann.lee@school.test\n");
    });

    it("with the keyboard: Down moves through the rows, Enter picks the highlighted one", async () => {
        const box = await open();

        expect(key(box, "ArrowDown").defaultPrevented).toBe(true);
        expect(box.getAttribute("aria-activedescendant")).toBe(options()[0].id);
        expect(options()[0].getAttribute("aria-selected")).toBe("true");

        key(box, "ArrowDown");
        expect(box.getAttribute("aria-activedescendant")).toBe(options()[1].id);

        key(box, "ArrowDown");
        expect(box.getAttribute("aria-activedescendant")).toBe(options()[0].id);

        key(box, "ArrowUp");
        expect(box.getAttribute("aria-activedescendant")).toBe(options()[1].id);

        const enter = key(box, "Enter");
        expect(enter.defaultPrevented).toBe(true);
        expect(box.value).toBe("ben.ng@school.test\n");
        expect(options()).toHaveLength(0);
    });

    it("leaves Enter alone (a new line) when no row is highlighted", async () => {
        const box = await open();

        const enter = key(box, "Enter");

        expect(enter.defaultPrevented).toBe(false);
        expect(options()).toHaveLength(2);
    });

    it("closes the list on Escape without closing the dialog around it", async () => {
        const box = await open();
        const dialogHeard = vi.fn();
        document.querySelector("#d").addEventListener("keydown", dialogHeard);

        key(box, "Escape");

        expect(options()).toHaveLength(0);
        expect(dialogHeard).not.toHaveBeenCalled();

        key(box, "Escape");
        expect(dialogHeard).toHaveBeenCalledTimes(1);
    });

    it("closes the list when the box loses focus", async () => {
        const box = await open();

        box.dispatchEvent(new FocusEvent("blur"));
        await vi.advanceTimersByTimeAsync(200);

        expect(options()).toHaveLength(0);
    });
});
