import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
    copyContent,
    fetchTransferModules,
    fetchTransferOfferings,
    fetchTransferTree,
    fetchTransferUnits,
    moveContent,
} from "../../../resources/js/pages/courses/features/modules/api.js";

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/api/courses/1" };
});

afterEach(() => {
    delete window.coursesConfig;
    vi.unstubAllGlobals();
});

const answer = (body, ok = true) => vi.fn().mockResolvedValue({ ok, status: ok ? 200 : 500, json: async () => body });

describe("the choosing steps", () => {
    it("read the courses, modules, units and tree from the content-transfer addresses", async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, json: async () => ({ offerings: [{ id: 2 }] }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ modules: [{ id: 3 }] }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ units: [{ id: 4 }] }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true, units: [] }) });
        vi.stubGlobal("fetch", fetchMock);

        expect(await fetchTransferOfferings()).toEqual([{ id: 2 }]);
        expect(await fetchTransferModules(2)).toEqual([{ id: 3 }]);
        expect(await fetchTransferUnits(3)).toEqual([{ id: 4 }]);
        expect((await fetchTransferTree("module", 9)).success).toBe(true);

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            "/api/courses/1/content-transfer/offerings",
            "/api/courses/1/content-transfer/offerings/2/modules",
            "/api/courses/1/content-transfer/modules/3/units",
            "/api/courses/1/content-transfer/module/9/tree",
        ]);
    });

    it("throw when a request fails, so the dialog says so", async () => {
        vi.stubGlobal("fetch", answer({}, false));

        await expect(fetchTransferOfferings()).rejects.toThrow();
    });
});

describe("copy and move", () => {
    it.each([
        ["copy", copyContent, "/api/courses/1/content-transfer/copy"],
        ["move", moveContent, "/api/courses/1/content-transfer/move"],
    ])("%s posts the choice as JSON", async (_name, send, url) => {
        const fetchMock = answer({ success: true, message: "Done." });
        vi.stubGlobal("fetch", fetchMock);

        const result = await send({ kind: "item", id: 9, target: { offering_id: 2 } });

        const [called, init] = fetchMock.mock.calls[0];
        expect(called).toBe(url);
        expect(init.method).toBe("POST");
        expect(init.headers["Content-Type"]).toBe("application/json");
        expect(JSON.parse(init.body)).toEqual({ kind: "item", id: 9, target: { offering_id: 2 } });
        expect(result).toEqual({ success: true, message: "Done." });
    });

    it("hand back the server's refusal as an answer, not an error", async () => {
        vi.stubGlobal("fetch", answer({ success: false, message: "Choose a course you teach." }));

        expect(await copyContent({})).toEqual({ success: false, message: "Choose a course you teach." });
    });
});
