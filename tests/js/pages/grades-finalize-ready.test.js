import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { bindFinalizeReady, resultSentence } from "../../../resources/js/pages/grades/finalize-ready.js";

function page() {
    document.body.innerHTML = `
        <div id="finalize-ready-dialog" data-dialog>
            <form method="post" action="/instructor/grades/finalize-ready" class="contents">
                <input type="hidden" name="term" value="4">
                <div><p data-dialog-text>Finalize the grades of IT101-A, IT101-B?</p></div>
                <div>
                    <button type="button" data-dialog-close>Cancel</button>
                    <button type="submit" data-confirm-submit>Finalize</button>
                </div>
            </form>
        </div>`;
}

const respond = (status, body) => Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

const submit = async () => {
    document.querySelector("form").dispatchEvent(new Event("submit", { cancelable: true }));
    await new Promise((resolve) => setTimeout(resolve, 0));
};

beforeEach(page);

afterEach(() => vi.unstubAllGlobals());

describe("resultSentence", () => {
    it("says what was finalized and what was skipped, with why", () => {
        expect(resultSentence({
            done: [{ offering_id: 1, label: "IT101-A" }, { offering_id: 2, label: "IT102-A" }],
            skipped: [{ label: "IT101-B", reason: "1 piece of handed-in work is still waiting to be graded." }],
        })).toBe("Finalized 2 sections. Skipped 1: IT101-B — 1 piece of handed-in work is still waiting to be graded.");

        expect(resultSentence({ done: [{ offering_id: 1, label: "IT101-A" }], skipped: [] })).toBe("Finalized 1 section.");
        expect(resultSentence({
            done: [],
            skipped: [{ label: "A-1", reason: "This section is closed." }, { label: "B-1", reason: "Stale." }],
        })).toBe("Finalized 0 sections. Skipped 2: A-1 — This section is closed; B-1 — Stale.");
    });
});

describe("bindFinalizeReady", () => {
    it("posts the term as JSON-wanting, shows the result in the dialog and reloads when it closes", async () => {
        const fetchMock = vi.fn(() => respond(200, { success: true, done: [{ offering_id: 1, label: "IT101-A" }], skipped: [] }));
        vi.stubGlobal("fetch", fetchMock);
        const reload = vi.fn();

        bindFinalizeReady(document, reload);
        await submit();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toContain("/instructor/grades/finalize-ready");
        expect(init.method).toBe("POST");
        expect(init.headers.Accept).toBe("application/json");
        expect(init.body.get("term")).toBe("4");

        const text = document.querySelector("[data-dialog-text]");
        expect(text.textContent).toBe("Finalized 1 section.");
        expect(text.getAttribute("role")).toBe("status");
        expect(document.querySelector("[data-confirm-submit]").hidden).toBe(true);
        expect(document.querySelector("[data-dialog-close]").textContent).toBe("Done");
        expect(reload).not.toHaveBeenCalled();

        document.getElementById("finalize-ready-dialog").dispatchEvent(new CustomEvent("dialog:close"));
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it("shows a refusal and changes nothing else", async () => {
        vi.stubGlobal("fetch", vi.fn(() => respond(403, { error: "impersonation_view_only", message: "You are viewing as another person, which is view-only." })));
        const reload = vi.fn();

        bindFinalizeReady(document, reload);
        await submit();

        expect(document.querySelector("[data-finalize-ready-error]").textContent).toBe("You are viewing as another person, which is view-only.");
        expect(document.querySelector("[data-dialog-text]").textContent).toBe("Finalize the grades of IT101-A, IT101-B?");
        expect(document.querySelector("[data-confirm-submit]").disabled).toBe(false);

        document.getElementById("finalize-ready-dialog").dispatchEvent(new CustomEvent("dialog:close"));
        expect(reload).not.toHaveBeenCalled();
    });

    it("says so when the server cannot be reached", async () => {
        vi.stubGlobal("fetch", vi.fn(() => Promise.reject(new Error("offline"))));

        bindFinalizeReady(document, vi.fn());
        await submit();

        expect(document.querySelector("[data-finalize-ready-error]").textContent).toContain("could not reach the server");
    });

    it("does nothing on a page without the dialog", () => {
        document.body.innerHTML = "";

        expect(() => bindFinalizeReady(document, vi.fn())).not.toThrow();
    });
});
