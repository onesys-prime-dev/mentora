import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { bindPasswordGate } from "../../../resources/js/pages/courses/features/assessments/quiz-taking/password-gate.js";
import { fetchQuizAttempt } from "../../../resources/js/pages/courses/features/assessments/api.js";

const FORM = `
    <form data-quiz-password-form data-assessment-id="9" novalidate>
        <input type="password" data-quiz-password-input>
        <p data-quiz-password-error role="alert"></p>
        <button type="submit" data-quiz-password-submit>Start the quiz</button>
    </form>`;

const $ = (selector) => document.querySelector(selector);

const submit = () => $("[data-quiz-password-form]").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));

beforeEach(() => {
    document.body.innerHTML = FORM;
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("the password prompt", () => {
    it("sends what was typed, without sending the page anywhere", async () => {
        const send = vi.fn().mockResolvedValue(undefined);
        bindPasswordGate({ submit: send });
        $("[data-quiz-password-input]").value = "Red-Kite-77";

        const event = new Event("submit", { bubbles: true, cancelable: true });
        $("[data-quiz-password-form]").dispatchEvent(event);
        await Promise.resolve();

        expect(event.defaultPrevented).toBe(true);
        expect(send).toHaveBeenCalledWith("Red-Kite-77");
    });

    it("asks for a password rather than sending nothing", () => {
        const send = vi.fn();
        bindPasswordGate({ submit: send });
        $("[data-quiz-password-input]").value = "   ";

        submit();

        expect(send).not.toHaveBeenCalled();
        expect($("[data-quiz-password-error]").textContent).toMatch(/type the password/i);
        expect($("[data-quiz-password-input]").getAttribute("aria-invalid")).toBe("true");
    });

    it("does not send twice while the first is on its way, and can be used again afterwards", async () => {
        let finish;
        const send = vi.fn().mockImplementation(() => new Promise((resolve) => (finish = resolve)));
        bindPasswordGate({ submit: send });
        $("[data-quiz-password-input]").value = "abc";

        submit();
        submit();

        expect(send).toHaveBeenCalledTimes(1);
        expect($("[data-quiz-password-submit]").disabled).toBe(true);

        finish();
        await vi.waitFor(() => expect($("[data-quiz-password-submit]").disabled).toBe(false));
    });

    it("says so, and can be tried again, when the request fails", async () => {
        const send = vi.fn().mockRejectedValue(new Error("offline"));
        bindPasswordGate({ submit: send });
        $("[data-quiz-password-input]").value = "abc";

        submit();
        await vi.waitFor(() => expect($("[data-quiz-password-error]").textContent).not.toBe(""));

        expect($("[data-quiz-password-submit]").disabled).toBe(false);
    });

    it("does nothing on a page without the prompt", () => {
        document.body.innerHTML = "";

        expect(bindPasswordGate({ submit: vi.fn() })).toBe(false);
    });
});

describe("opening the quiz page", () => {
    beforeEach(() => {
        window.coursesConfig = { apiBaseUrl: "/api/courses/1" };
    });

    afterEach(() => {
        delete window.coursesConfig;
    });

    it("is a plain GET when there is no password to give", async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ html: "<p>x</p>" }) });
        vi.stubGlobal("fetch", fetchMock);

        await fetchQuizAttempt(1, 9);

        expect(fetchMock.mock.calls[0][0]).toBe("/api/courses/1/assessments/9/take");
        expect(fetchMock.mock.calls[0][1].method ?? "GET").toBe("GET");
    });

    it("posts the password as JSON to the same address when one is given", async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ html: "<p>x</p>" }) });
        vi.stubGlobal("fetch", fetchMock);

        await fetchQuizAttempt(1, 9, "Red-Kite-77");

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe("/api/courses/1/assessments/9/take");
        expect(init.method).toBe("POST");
        expect(init.headers["Content-Type"]).toBe("application/json");
        expect(JSON.parse(init.body)).toEqual({ password: "Red-Kite-77" });
    });
});
