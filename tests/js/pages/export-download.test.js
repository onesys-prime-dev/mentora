import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const showToast = vi.fn();

vi.mock("../../../resources/js/shared/toast.js", () => ({
    showToast: (...args) => showToast(...args),
}));

import { downloadExport } from "../../../resources/js/pages/courses/features/assessments/import-export/export-modal.js";

let clicked;

beforeEach(() => {
    showToast.mockReset();
    clicked = [];

    URL.createObjectURL = vi.fn(() => "blob:fake");
    URL.revokeObjectURL = vi.fn();

    // A click on the temporary link is the browser saving the file.
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function () {
        clicked.push({ href: this.href, download: this.download });
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
});

const respond = (init) => vi.stubGlobal("fetch", vi.fn().mockResolvedValue(init));

describe("downloading an export", () => {
    it("saves the file under the name the server gave it, and says the download started", async () => {
        respond({
            ok: true,
            blob: async () => new Blob(["x"]),
            headers: { get: () => 'attachment; filename="Quiz 1 questions.docx"' },
        });

        await downloadExport("/api/courses/1/assessments/9/export?format=docx");

        expect(clicked).toHaveLength(1);
        expect(clicked[0].download).toBe("Quiz 1 questions.docx");
        expect(showToast).toHaveBeenCalledWith("Export ready. Your download has started.", { tone: "success" });
        expect(document.querySelector("a")).toBeNull();
    });

    it("asks with the header that marks it as the page's own request", async () => {
        respond({ ok: true, blob: async () => new Blob(["x"]), headers: { get: () => "" } });

        await downloadExport("/export?format=csv");

        expect(fetch.mock.calls[0][1].headers["X-Requested-With"]).toBe("XMLHttpRequest");
        expect(clicked[0].download).toBe("questions");
    });

    it("shows the server's reason as a toast, and saves nothing, when it refuses", async () => {
        respond({ ok: false, json: async () => ({ message: "This quiz has no questions to export." }) });

        await downloadExport("/export?format=docx");

        expect(showToast).toHaveBeenCalledWith("This quiz has no questions to export.", { tone: "danger" });
        expect(clicked).toHaveLength(0);
    });

    it("says something plain when the refusal is not JSON", async () => {
        respond({ ok: false, json: async () => { throw new Error("not json"); } });

        await downloadExport("/export?format=docx");

        expect(showToast).toHaveBeenCalledWith("The questions could not be exported. Try again.", { tone: "danger" });
    });

    it("says so when the request fails", async () => {
        vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));
        vi.spyOn(console, "error").mockImplementation(() => {});

        await downloadExport("/export?format=docx");

        expect(showToast).toHaveBeenCalledWith("The questions could not be exported. Check your connection and try again.", { tone: "danger" });
        expect(clicked).toHaveLength(0);
    });
});
