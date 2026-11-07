import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { fetchAssignees, saveAssignees } from "../../../resources/js/pages/courses/features/assessments/api.js";

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/api/courses/1" };
});

afterEach(() => {
    delete window.coursesConfig;
    vi.unstubAllGlobals();
});

describe("fetchAssignees", () => {
    it("gets the section's students from the assessment's assignees address", async () => {
        const students = [{ id: 1, name: "Ada", email: "", selected: true }];
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, students }) });
        vi.stubGlobal("fetch", fetchMock);

        expect(await fetchAssignees(1, 9)).toEqual(students);
        expect(fetchMock.mock.calls[0][0]).toBe("/api/courses/1/assessments/9/assignees");
    });

    it("throws when the list cannot be had, so the modal says so", async () => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 500, json: async () => ({}) }));

        await expect(fetchAssignees(1, 9)).rejects.toThrow();
    });
});

describe("saveAssignees", () => {
    it("puts the ids as JSON and returns the server's answer", async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, count: 2 }) });
        vi.stubGlobal("fetch", fetchMock);

        const result = await saveAssignees(1, 9, [4, 5]);

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe("/api/courses/1/assessments/9/assignees");
        expect(init.method).toBe("PUT");
        expect(init.headers["Content-Type"]).toBe("application/json");
        expect(JSON.parse(init.body)).toEqual({ student_ids: [4, 5] });
        expect(result).toEqual({ success: true, count: 2 });
    });

    it("throws on a failed request", async () => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 500, json: async () => ({}) }));

        await expect(saveAssignees(1, 9, [])).rejects.toThrow();
    });
});
