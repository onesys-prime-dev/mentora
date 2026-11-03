import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * A parent's "From the instructor" tab: it loads the server's fragment like the other tabs, and its one
 * control, "Message <instructor>", starts (or reopens) the parent's thread with that instructor about the
 * child and then opens it in Messages.
 */

const api = "/parent/children/2/api/courses/1";

const fragment = `
<div data-marker="parent-instructor">
  <button type="button" data-parent-message data-instructor-id="7" data-child-id="2">Message Tina Tan</button>
  <button type="button" data-parent-message data-instructor-id="8" data-child-id="2">Message Sam Reyes</button>
  <p data-parent-message-note role="status" hidden></p>
</div>`;

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const ok = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });

let go;

async function load(responses = {}) {
    document.body.innerHTML = '<div id="course-content" aria-busy="true"></div>';

    window.coursesConfig = {
        offeringId: 1,
        slug: "intro",
        role: "parent",
        apiBaseUrl: api,
        browserBaseUrl: "/parent/children/2/courses/1/intro",
        activeFeature: "instructor",
    };

    const fetch = vi.spyOn(window, "fetch").mockImplementation(async (url) => {
        if (String(url).includes("/messages/api/conversations")) {
            return responses.start ?? ok({ conversation: { id: 9 } });
        }

        return responses.tab ?? ok({ html: fragment });
    });

    vi.resetModules();

    const module = await import("../../../resources/js/pages/courses/features/instructor/index.js");

    go = vi.fn();
    module.navigation.go = go;

    await module.initialize();
    await tick();

    return fetch;
}

const buttons = () => [...document.querySelectorAll("[data-parent-message]")];
const note = () => document.querySelector("[data-parent-message-note]");
const startCalls = (fetch) => fetch.mock.calls.filter((call) => String(call[0]).includes("/messages/api/conversations"));

beforeEach(() => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    window.history.replaceState({}, "", "/parent/children/2/courses/1/intro");
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = "";
    delete window.coursesConfig;
});

describe("the parent's instructor tab", { timeout: 30000 }, () => {
    it("asks the parent's address and shows the fragment, not the error screen", async () => {
        const fetch = await load();

        expect(String(fetch.mock.calls[0][0])).toContain(`${api}/instructor`);
        expect(document.querySelector("[data-marker=parent-instructor]")).not.toBeNull();
        expect(document.querySelector("#course-content").textContent).not.toContain("We couldn't load");
    });

    it("shows the error screen when the tab cannot be fetched", async () => {
        await load({ tab: ok({}, 500) });

        expect(document.querySelector("#course-content").textContent).toContain("We couldn't load");
    });

    it("starts the thread about the child with that instructor, then opens it in Messages", async () => {
        const fetch = await load();

        buttons()[1].click();
        await tick();

        const [url, init] = startCalls(fetch)[0];

        expect(String(url)).toBe("/messages/api/conversations");
        expect(init.method).toBe("POST");
        expect(JSON.parse(init.body)).toEqual({ recipient_id: 8, subject_student_id: 2 });
        expect(go).toHaveBeenCalledWith("/messages/9");
    });

    it("says so and stays put when the server refuses", async () => {
        await load({ start: ok({ error: "not_found" }, 404) });

        buttons()[0].click();
        await tick();

        expect(go).not.toHaveBeenCalled();
        expect(note().hidden).toBe(false);
        expect(note().textContent).toBe("You can't message them right now");
        expect(buttons().every((b) => !b.disabled)).toBe(true);
    });

    it("sends nothing more while one request is in flight", async () => {
        let release;
        const fetch = await load();

        fetch.mockImplementation((url) => (String(url).includes("/messages/api/conversations")
            ? new Promise((resolve) => { release = () => resolve(ok({ conversation: { id: 9 } })); })
            : Promise.resolve(ok({ html: fragment }))));

        buttons()[0].click();
        buttons()[0].click();
        buttons()[1].click();
        await tick();

        expect(startCalls(fetch)).toHaveLength(1);
        expect(buttons().every((b) => b.disabled)).toBe(true);

        release();
        await tick();

        expect(go).toHaveBeenCalledTimes(1);
    });
});
