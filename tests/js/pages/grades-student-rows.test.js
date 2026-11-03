import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { bindStudentRows } from "../../../resources/js/pages/grades/student-rows.js";

const FRAGMENT = `
    <div data-grades-breakdown-root>
        <table><tbody><tr><td>85.0%</td></tr></tbody></table>
        <button type="button" data-categories-toggle aria-expanded="false">Show categories</button>
        <div data-categories hidden>Quizzes</div>
    </div>`;

const row = (id, expandable) => `
    <li data-grades-row data-offering-id="${id}" data-expandable="${expandable ? 1 : 0}">
        ${expandable ? `<button type="button" data-disclosure aria-expanded="false" aria-controls="grades-breakdown-${id}">Open</button>` : ""}
        <div id="grades-breakdown-${id}" data-breakdown-slot hidden></div>
    </li>`;

const ok = (html = FRAGMENT) => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, html }) });

let root;

beforeEach(() => {
    document.body.innerHTML = `<ul data-grades-rows data-breakdown-base="/student/api/grades/courses">${row(7, true)}${row(8, false)}</ul>`;
    root = document.querySelector("[data-grades-rows]");
    bindStudentRows(root);
});

beforeEach(() => {
    vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

const button = () => root.querySelector("[data-disclosure]");
const slot = () => root.querySelector("[data-breakdown-slot]");

describe("bindStudentRows", () => {
    it("the first expand fetches once and shows a skeleton meanwhile", async () => {
        let release;
        const fetchMock = vi.fn(() => new Promise((resolve) => {
            release = () => resolve({ ok: true, json: () => Promise.resolve({ success: true, html: FRAGMENT }) });
        }));
        vi.stubGlobal("fetch", fetchMock);

        button().click();

        expect(button().getAttribute("aria-expanded")).toBe("true");
        expect(slot().hidden).toBe(false);
        expect(slot().querySelector("[data-skeleton]")).not.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe("/student/api/grades/courses/7");
        expect(fetchMock.mock.calls[0][1].headers["X-Requested-With"]).toBe("XMLHttpRequest");
        expect(fetchMock.mock.calls[0][1].headers.Accept).toBe("application/json");

        release();
        await vi.waitFor(() => expect(slot().querySelector("[data-skeleton]")).toBeNull());

        expect(slot().textContent).toContain("85.0%");
    });

    it("collapsing and expanding again does not fetch again", async () => {
        const fetchMock = vi.fn(() => ok());
        vi.stubGlobal("fetch", fetchMock);

        button().click();
        await vi.waitFor(() => expect(slot().textContent).toContain("85.0%"));

        button().click();
        expect(button().getAttribute("aria-expanded")).toBe("false");
        expect(slot().hidden).toBe(true);

        button().click();
        expect(button().getAttribute("aria-expanded")).toBe("true");
        expect(slot().hidden).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it("a failed fetch shows the error with Try again, which retries", async () => {
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => Promise.reject(new Error("offline")))
            .mockImplementation(() => ok());
        vi.stubGlobal("fetch", fetchMock);

        button().click();
        await vi.waitFor(() => expect(slot().querySelector("[data-retry]")).not.toBeNull());

        expect(slot().textContent).toContain("We couldn't load");

        slot().querySelector("[data-retry]").click();
        await vi.waitFor(() => expect(slot().textContent).toContain("85.0%"));

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(slot().querySelector("[data-retry]")).toBeNull();
    });

    it("a refused course (404) is an error, not an empty slot", async () => {
        vi.stubGlobal("fetch", vi.fn(() => Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({ success: false }) })));

        button().click();
        await vi.waitFor(() => expect(slot().querySelector("[data-retry]")).not.toBeNull());
    });

    it("the categories toggle flips aria-expanded and the hidden block and its label", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok()));

        button().click();
        await vi.waitFor(() => expect(slot().querySelector("[data-categories-toggle]")).not.toBeNull());

        const toggle = slot().querySelector("[data-categories-toggle]");
        const block = slot().querySelector("[data-categories]");

        expect(block.id).toBe("grades-categories-7");
        expect(toggle.getAttribute("aria-controls")).toBe("grades-categories-7");

        toggle.click();
        expect(toggle.getAttribute("aria-expanded")).toBe("true");
        expect(block.hidden).toBe(false);
        expect(toggle.textContent).toBe("Hide categories");

        toggle.click();
        expect(toggle.getAttribute("aria-expanded")).toBe("false");
        expect(block.hidden).toBe(true);
        expect(toggle.textContent).toBe("Show categories");
    });

    it("a fragment without categories is fine", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok("<p>Nothing is graded in this course yet.</p>")));

        button().click();
        await vi.waitFor(() => expect(slot().textContent).toContain("Nothing is graded"));
    });

    it("a row with data-expandable=\"0\" has nothing to click", () => {
        const fetchMock = vi.fn(() => ok());
        vi.stubGlobal("fetch", fetchMock);

        const plain = root.querySelector("[data-offering-id=\"8\"]");

        expect(plain.querySelector("[data-disclosure]")).toBeNull();

        plain.click();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(plain.querySelector("[data-breakdown-slot]").hidden).toBe(true);
    });

    it("the disclosure is a native button, so Enter and Space work", () => {
        expect(button().tagName).toBe("BUTTON");
        expect(button().type).toBe("button");
    });
});
