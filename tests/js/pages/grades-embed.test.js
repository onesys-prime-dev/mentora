import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { fetchGradebookRoster, setGradesApiBase } from "../../../resources/js/pages/courses/features/grades/api.js";
import { flushPendingScores, loadGradebookRoster } from "../../../resources/js/pages/courses/features/grades/roster/roster.js";

const GRID = `
    <div data-gradebook-roster>
        <table><tbody>
            <tr data-roster-row data-student-id="7">
                <td><input data-manual-score-input data-item-id="3" value=""></td>
            </tr>
        </tbody></table>
    </div>`;

const reply = (body) => Promise.resolve({ ok: true, json: () => Promise.resolve(body) });

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/1" };
    document.body.innerHTML = `<div id="course-content"></div><div id="elsewhere"></div>`;
});

afterEach(() => {
    setGradesApiBase(null);
    vi.useRealTimers();
});

describe("where the gradebook calls go", () => {
    it("follows setGradesApiBase and falls back to coursesConfig", async () => {
        const fetchMock = vi.fn(() => reply({ success: true, html: "" }));
        vi.stubGlobal("fetch", fetchMock);

        await fetchGradebookRoster();
        expect(fetchMock.mock.calls[0][0]).toBe("/instructor/api/courses/1/grades/roster");

        setGradesApiBase("/instructor/api/courses/9");
        await fetchGradebookRoster();
        expect(fetchMock.mock.calls[1][0]).toBe("/instructor/api/courses/9/grades/roster");

        setGradesApiBase(null);
        await fetchGradebookRoster("embedded=1");
        expect(fetchMock.mock.calls[2][0]).toBe("/instructor/api/courses/1/grades/roster?embedded=1");
    });
});

describe("the embedded grid", () => {
    it("renders into the given container with the query", async () => {
        const fetchMock = vi.fn(() => reply({ success: true, html: GRID }));
        vi.stubGlobal("fetch", fetchMock);

        await loadGradebookRoster(null, { container: "#elsewhere", query: "embedded=1&readonly=1" });

        expect(fetchMock.mock.calls[0][0]).toBe("/instructor/api/courses/1/grades/roster?embedded=1&readonly=1");
        expect(document.querySelector("#elsewhere [data-gradebook-roster]")).not.toBeNull();
        expect(document.querySelector("#course-content").innerHTML).toBe("");
    });

    it("flushPendingScores saves a score typed just before switching", async () => {
        const calls = [];

        vi.stubGlobal("fetch", vi.fn((url, options = {}) => {
            calls.push([url, options]);

            return reply(options.method === "PATCH" ? { success: true, percentage: 80 } : { success: true, html: GRID });
        }));

        await loadGradebookRoster(null, { container: document.querySelector("#elsewhere") });

        vi.useFakeTimers();

        const input = document.querySelector("[data-manual-score-input]");
        input.value = "80";
        input.dispatchEvent(new Event("input", { bubbles: true }));

        await flushPendingScores();

        const patches = calls.filter(([, options]) => options.method === "PATCH");

        expect(patches).toHaveLength(1);
        expect(patches[0][0]).toBe("/instructor/api/courses/1/grades/roster/items/3/students/7");
        expect(JSON.parse(patches[0][1].body)).toEqual({ score: 80, excused: false });

        // Nothing is left to fire later.
        await vi.runAllTimersAsync();
        expect(calls.filter(([, options]) => options.method === "PATCH")).toHaveLength(1);
    });
});
