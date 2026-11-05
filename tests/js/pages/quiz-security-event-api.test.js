import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { recordSecurityEvent } from "../../../resources/js/pages/courses/features/assessments/api.js";

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/api/courses/1" };
});

afterEach(() => {
    delete window.coursesConfig;
    vi.unstubAllGlobals();
});

describe("recordSecurityEvent", () => {
    it("posts the event as JSON to the attempt's security-events address, keeping the request alive if the page closes", async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true }) });
        vi.stubGlobal("fetch", fetchMock);

        const kept = await recordSecurityEvent(5, { kind: "left_page", duration_seconds: 12 });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe("/api/courses/1/submissions/5/security-events");
        expect(init.method).toBe("POST");
        expect(init.keepalive).toBe(true);
        expect(init.headers["Content-Type"]).toBe("application/json");
        expect(JSON.parse(init.body)).toEqual({ kind: "left_page", duration_seconds: 12 });
        expect(kept).toBe(true);
    });

    it("says whether the server kept it", async () => {
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: false, message: "Not recorded for this quiz." }) }));

        expect(await recordSecurityEvent(5, { kind: "left_fullscreen" })).toBe(false);
    });

    it("never throws into the quiz: a failed request or an unreadable answer is just not kept", async () => {
        vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));
        expect(await recordSecurityEvent(5, { kind: "left_fullscreen" })).toBe(false);

        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 500, json: async () => ({}) }));
        expect(await recordSecurityEvent(5, { kind: "left_fullscreen" })).toBe(false);

        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true, json: async () => { throw new Error("not json"); } }));
        expect(await recordSecurityEvent(5, { kind: "left_fullscreen" })).toBe(false);
    });
});
