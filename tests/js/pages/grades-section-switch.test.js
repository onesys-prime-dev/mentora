import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const order = [];

vi.mock("../../../resources/js/pages/courses/features/grades/roster/roster.js", async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        flushPendingScores: vi.fn(async () => {
            order.push("flush");
            await actual.flushPendingScores();
        }),
    };
});

import { setGradesApiBase } from "../../../resources/js/pages/courses/features/grades/api.js";
import { bindDownloadLinks, loadInto, sectionHref, switchSection } from "../../../resources/js/pages/grades/section-switch.js";

const ROSTER = (name) => `<div data-gradebook-roster>${name}</div>`;

// A grid with two hand-scored cells for one student.
const GRID = `
    <div data-gradebook-roster>
        <table><tbody>
            <tr data-roster-row data-student-id="7">
                <td><input data-manual-score-input data-item-id="3" value=""></td>
                <td><input data-manual-score-input data-item-id="4" value=""></td>
            </tr>
        </tbody></table>
    </div>`;

const card = (id, slug, extra = "") => `<a href="/instructor/grades?term=4&tab=gradebook&section=${id}" data-section-card="${id}" data-slug="${slug}" data-state="ready" data-offering-status="active" data-note="Note ${id}" data-label="C-${id}" ${extra}>C-${id}</a>`;

function page() {
    document.body.innerHTML = `
        <nav data-grades-tabs><a data-grades-tab="gradebook" href="/instructor/grades?term=4&section=1">Gradebook</a><a data-grades-sub="gradebook" href="/chairperson/grades?term=4&section=1">Gradebook</a><a data-grades-tab="to-grade" href="/instructor/grades?term=4&tab=to-grade">To grade</a></nav>
        <nav>${card(1, "alpha", "aria-current=\"true\" data-has-scheme=\"1\"")}${card(2, "beta", "data-has-scheme=\"1\"")}${card(3, "gamma").replace("data-state=\"ready\"", "data-state=\"finalized\"")}${card(4, "delta").replace("data-offering-status=\"active\"", "data-offering-status=\"completed\"")}${card(5, "epsilon", "data-has-scheme=\"0\"").replace("data-state=\"ready\"", "data-state=\"not_published\"")}</nav>
        <div data-section-actions data-section-id="1">
            <span data-action-label>C-1</span> · <span data-action-note>Note 1</span>
            <a data-download-link="class" href="/instructor/api/courses/1/grades/report/class?format=xlsx"></a>
            <a data-download-link="summary" href="/instructor/api/courses/1/grades/report/summary?format=pdf"></a>
            <button data-open-finalize>Finalize</button>
            <a data-open-in-course href="/instructor/courses/1/alpha/grades"></a>
        </div>
        <div data-grades-grid data-api-base="/instructor/api/courses/1" data-section-id="1" data-has-scheme="1"></div>
        <template data-no-scheme-template><div data-no-scheme><span>Grading isn't set up for this section yet.</span><a data-no-scheme-open href="#">Open in course →</a></div></template>`;
}

const ok = (body) => Promise.resolve({ ok: true, json: () => Promise.resolve(body) });

beforeEach(() => {
    order.length = 0;
    window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/1" };
    window.history.replaceState(null, "", "/instructor/grades?term=4&tab=gradebook&section=1");
    page();
});

afterEach(() => {
    setGradesApiBase(null);
    vi.unstubAllGlobals();
});

describe("sectionHref", () => {
    it("keeps term and tab", () => {
        expect(sectionHref("/instructor/grades?term=4&tab=gradebook&section=1", 2)).toBe("/instructor/grades?term=4&tab=gradebook&section=2");
        expect(sectionHref("/instructor/grades?term=4", 9)).toBe("/instructor/grades?term=4&section=9");
    });
});

describe("switchSection", () => {
    it("clicking a card flushes pending scores before loading the next grid", async () => {
        vi.stubGlobal("fetch", vi.fn((url) => {
            order.push(url);
            return ok({ success: true, html: ROSTER("two") });
        }));

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        expect(order).toEqual(["flush", "/instructor/api/courses/2/grades/roster?embedded=1"]);
        expect(document.querySelector("[data-grades-grid]").textContent).toContain("two");
        expect(document.querySelector("[data-grades-grid]").dataset.apiBase).toBe("/instructor/api/courses/2");
    });

    it("the Gradebook sub-pill keeps the chosen section too", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok({ success: true, html: ROSTER("two") })));

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        expect(document.querySelector("[data-grades-sub=\"gradebook\"]").getAttribute("href")).toBe("/chairperson/grades?term=4&section=2");
    });

    it("the selected card gets aria-current and the address changes with replaceState", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok({ success: true, html: ROSTER("two") })));
        const push = vi.spyOn(window.history, "pushState");

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        expect(document.querySelector("[data-section-card=\"2\"]").getAttribute("aria-current")).toBe("true");
        expect(document.querySelector("[data-section-card=\"1\"]").hasAttribute("aria-current")).toBe(false);
        expect(window.location.search).toBe("?term=4&tab=gradebook&section=2");
        expect(push).not.toHaveBeenCalled();
    });

    it("rewrites the action bar for the new section", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok({ success: true, html: ROSTER("two") })));

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        expect(document.querySelector("[data-action-label]").textContent).toBe("C-2");
        expect(document.querySelector("[data-action-note]").textContent).toBe("Note 2");
        expect(document.querySelector("[data-download-link=\"class\"]").getAttribute("href")).toBe("/instructor/api/courses/2/grades/report/class?format=xlsx");
        expect(document.querySelector("[data-download-link=\"summary\"]").getAttribute("href")).toBe("/instructor/api/courses/2/grades/report/summary?format=pdf");
        expect(document.querySelector("[data-open-in-course]").getAttribute("href")).toBe("/instructor/courses/2/beta/grades");
        expect(document.querySelector("[data-open-finalize]").hidden).toBe(false);
    });

    it("hides Finalize for a finalized section and for one whose offering is not active", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok({ success: true, html: ROSTER("x") })));
        const finalize = document.querySelector("[data-open-finalize]");

        await switchSection(document.querySelector("[data-section-card=\"3\"]"));
        expect(finalize.hidden).toBe(true);

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));
        expect(finalize.hidden).toBe(false);

        await switchSection(document.querySelector("[data-section-card=\"4\"]"));
        expect(finalize.hidden).toBe(true);
    });

    it("a failed load shows the error state with Try again", async () => {
        vi.stubGlobal("fetch", vi.fn(() => Promise.reject(new Error("offline"))));
        vi.spyOn(console, "error").mockImplementation(() => {});

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        const grid = document.querySelector("[data-grades-grid]");
        expect(grid.textContent).toContain("We couldn't load the gradebook");
        expect(grid.querySelector("[data-retry]").textContent).toBe("Try again");
    });

    it("two rapid switches where the first response resolves last show the second section", async () => {
        const pending = {};
        vi.stubGlobal("fetch", vi.fn((url) => new Promise((resolve) => {
            pending[url.match(/courses\/(\d+)/)[1]] = () => resolve({ ok: true, json: () => Promise.resolve({ success: true, html: ROSTER(`section ${url.match(/courses\/(\d+)/)[1]}`) }) });
        })));

        const first = switchSection(document.querySelector("[data-section-card=\"2\"]"));

        await vi.waitFor(() => expect(Object.keys(pending)).toContain("2"));

        const second = switchSection(document.querySelector("[data-section-card=\"3\"]"));

        await vi.waitFor(() => expect(Object.keys(pending)).toContain("3"));

        pending[3]();
        await second;
        pending[2]();
        await first;

        const grid = document.querySelector("[data-grades-grid]");
        expect(grid.textContent).toContain("section 3");
        expect(grid.textContent).not.toContain("section 2");
        expect(document.querySelector("[data-section-card=\"3\"]").getAttribute("aria-current")).toBe("true");
        expect(document.querySelector("[data-section-card=\"2\"]").hasAttribute("aria-current")).toBe(false);
    });

    it("a switch made while the first grid is still loading keeps the new section shown", async () => {
        const pending = {};
        vi.stubGlobal("fetch", vi.fn((url) => new Promise((resolve) => {
            const id = url.match(/courses\/(\d+)/)[1];
            pending[id] = () => resolve({ ok: true, json: () => Promise.resolve({ success: true, html: ROSTER(`section ${id}`) }) });
        })));

        const grid = document.querySelector("[data-grades-grid]");
        const initial = loadInto(grid);

        await vi.waitFor(() => expect(Object.keys(pending)).toContain("1"));

        const switched = switchSection(document.querySelector("[data-section-card=\"2\"]"));

        await vi.waitFor(() => expect(Object.keys(pending)).toContain("2"));

        pending[2]();
        await switched;
        pending[1]();
        await initial;

        expect(grid.textContent).toContain("section 2");
        expect(grid.textContent).not.toContain("section 1");
    });

    it("a score typed while the switch is still saving goes to the old section, never the new one", async () => {
        const patches = [];
        let release = null;

        vi.stubGlobal("fetch", vi.fn((url, options = {}) => {
            if (options.method === "PATCH") {
                patches.push(url);

                // The first save (the one the switch flushes) is held, so the old grid stays up meanwhile.
                if (release === null) {
                    return new Promise((resolve) => {
                        release = () => resolve({ ok: true, json: () => Promise.resolve({ success: true, percentage: 80 }) });
                    });
                }

                return ok({ success: true, percentage: 70 });
            }

            return ok({ success: true, html: url.includes("/courses/1/") ? GRID : ROSTER("two") });
        }));

        const grid = document.querySelector("[data-grades-grid]");
        await loadInto(grid);

        const [first, second] = grid.querySelectorAll("[data-manual-score-input]");

        first.value = "80";
        first.dispatchEvent(new Event("input"));

        const switched = switchSection(document.querySelector("[data-section-card=\"2\"]"));

        await vi.waitFor(() => expect(release).toBeTypeOf("function"));

        // Typed into the old grid during the flush: its save starts after the switch has moved on.
        second.value = "70";
        second.dispatchEvent(new Event("input"));

        release();
        await switched;

        expect(grid.textContent).toContain("two");

        await vi.waitFor(() => expect(patches).toHaveLength(2), { timeout: 2000 });

        expect(patches).toEqual([
            "/instructor/api/courses/1/grades/roster/items/3/students/7",
            "/instructor/api/courses/1/grades/roster/items/4/students/7",
        ]);
    });

    it("a section without a grading scheme is not fetched: the grid says so and offers the course", async () => {
        const fetchMock = vi.fn(() => ok({ success: true, html: ROSTER("x") }));
        vi.stubGlobal("fetch", fetchMock);

        await switchSection(document.querySelector("[data-section-card=\"5\"]"));

        const grid = document.querySelector("[data-grades-grid]");

        expect(fetchMock).not.toHaveBeenCalled();
        expect(grid.dataset.hasScheme).toBe("0");
        expect(grid.dataset.sectionId).toBe("5");
        expect(grid.textContent).toContain("Grading isn't set up for this section yet.");
        expect(grid.querySelector("[data-no-scheme-open]").getAttribute("href")).toBe("/instructor/courses/5/epsilon/grades");
        expect(document.querySelector("[data-section-card=\"5\"]").getAttribute("aria-current")).toBe("true");
        expect(document.querySelector("[data-action-label]").textContent).toBe("C-5");
        expect(document.querySelector("[data-open-finalize]").hidden).toBe(true);

        // Back to a section that has one: its grid loads again.
        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(["/instructor/api/courses/2/grades/roster?embedded=1"]);
        expect(grid.dataset.hasScheme).toBe("1");
        expect(grid.textContent).toContain("x");
    });

    it("the Gradebook tab follows the chosen section; the other tabs are left alone", async () => {
        vi.stubGlobal("fetch", vi.fn(() => ok({ success: true, html: ROSTER("two") })));

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        expect(document.querySelector("[data-grades-tab=\"gradebook\"]").getAttribute("href")).toBe("/instructor/grades?term=4&section=2");
        expect(document.querySelector("[data-grades-tab=\"to-grade\"]").getAttribute("href")).toBe("/instructor/grades?term=4&tab=to-grade");
    });
});

describe("the action bar's downloads", () => {
    it("are fetched from the chosen section's address, and a refusal is shown as a message, not a page", async () => {
        const urls = [];

        vi.stubGlobal("fetch", vi.fn((url) => {
            urls.push(url);

            if (url.includes("/report/")) {
                return Promise.resolve({
                    ok: false,
                    status: 404,
                    headers: new Headers({ "Content-Type": "application/json" }),
                    json: () => Promise.resolve({ success: false, message: "This section has no grading yet." }),
                });
            }

            return ok({ success: true, html: ROSTER("two") });
        }));

        bindDownloadLinks();

        await switchSection(document.querySelector("[data-section-card=\"2\"]"));

        const link = document.querySelector("[data-download-link=\"class\"]");
        const event = new MouseEvent("click", { bubbles: true, cancelable: true, button: 0 });

        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        // The plain address stays, for a browser without the script.
        expect(link.getAttribute("href")).toBe("/instructor/api/courses/2/grades/report/class?format=xlsx");

        await vi.waitFor(() => expect(document.body.textContent).toContain("This section has no grading yet."));

        expect(urls.filter((url) => url.includes("/report/"))).toEqual(["/instructor/api/courses/2/grades/report/class?format=xlsx"]);
    });
});
