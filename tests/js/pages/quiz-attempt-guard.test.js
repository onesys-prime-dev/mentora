import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { bindAttemptGuard } from "../../../resources/js/pages/courses/features/assessments/quiz-taking/attempt-guard.js";

const form = (flags = {}) => `
    <div data-quiz-guard-notice><button type="button" data-guard-fullscreen>Go fullscreen</button></div>
    <form id="quiz-answer-form" data-submission-id="5" ${flags.detect ? 'data-detect-tab-switch="1"' : ""} ${flags.lock ? 'data-lock-browser="1"' : ""}>
        <textarea id="answer"></textarea>
    </form>`;

let clock;
let record;
let notify;
let guard;

const setVisibility = (state) => {
    Object.defineProperty(document, "visibilityState", { value: state, configurable: true });
    document.dispatchEvent(new Event("visibilitychange"));
};

const setFullscreen = (element) => {
    Object.defineProperty(document, "fullscreenElement", { value: element, configurable: true });
    document.dispatchEvent(new Event("fullscreenchange"));
};

const bind = (flags) => {
    document.body.innerHTML = form(flags);
    record = vi.fn();
    notify = vi.fn();
    guard = bindAttemptGuard({ form: document.querySelector("#quiz-answer-form"), record, notify, now: () => clock });

    return guard;
};

beforeEach(() => {
    clock = 1_000_000;
    Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
    Object.defineProperty(document, "fullscreenElement", { value: null, configurable: true });
    document.documentElement.requestFullscreen = vi.fn().mockResolvedValue(undefined);
});

afterEach(() => {
    guard?.stop();
    document.body.innerHTML = "";
});

describe("with nothing switched on", () => {
    it("watches nothing and blocks nothing", () => {
        bind({});

        setVisibility("hidden");
        clock += 30_000;
        setVisibility("visible");

        const paste = new Event("paste", { bubbles: true, cancelable: true });
        document.querySelector("#answer").dispatchEvent(paste);

        expect(record).not.toHaveBeenCalled();
        expect(paste.defaultPrevented).toBe(false);
        expect(guard.active).toBe(false);
    });
});

describe("recording that the student left the page", () => {
    it("reports how long they were away when they come back", () => {
        bind({ detect: true });

        setVisibility("hidden");
        clock += 7_400;
        setVisibility("visible");

        expect(record).toHaveBeenCalledTimes(1);
        expect(record).toHaveBeenCalledWith({ kind: "left_page", duration_seconds: 7 });
    });

    it("counts one absence once, whether the window lost focus, the tab hid, or both", () => {
        bind({ detect: true });

        window.dispatchEvent(new Event("blur"));
        clock += 3_000; // the tab hides a moment after the window lost focus: the absence began at the first
        setVisibility("hidden");
        clock += 17_000;
        setVisibility("visible");
        window.dispatchEvent(new Event("focus"));

        expect(record).toHaveBeenCalledTimes(1);
        expect(record).toHaveBeenCalledWith({ kind: "left_page", duration_seconds: 20 });
    });

    it("counts a window that loses focus and gets it back, with no tab change", () => {
        bind({ detect: true });

        window.dispatchEvent(new Event("blur"));
        clock += 4_000;
        window.dispatchEvent(new Event("focus"));

        expect(record).toHaveBeenCalledWith({ kind: "left_page", duration_seconds: 4 });
    });

    it("ignores a blink shorter than a second", () => {
        bind({ detect: true });

        window.dispatchEvent(new Event("blur"));
        clock += 400;
        window.dispatchEvent(new Event("focus"));

        expect(record).not.toHaveBeenCalled();
    });

    it("never says where they went (there is nothing to say)", () => {
        bind({ detect: true });

        setVisibility("hidden");
        clock += 5_000;
        setVisibility("visible");

        expect(Object.keys(record.mock.calls[0][0]).sort()).toEqual(["duration_seconds", "kind"]);
    });

    it("is not done when only the lock is on", () => {
        bind({ lock: true });

        setVisibility("hidden");
        clock += 9_000;
        setVisibility("visible");

        expect(record).not.toHaveBeenCalled();
    });
});

describe("the lock", () => {
    it.each(["copy", "cut", "paste", "contextmenu"])("stops %s, records it and tells the student", (kind) => {
        bind({ lock: true });

        const event = new Event(kind, { bubbles: true, cancelable: true });
        document.querySelector("#answer").dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(record).toHaveBeenCalledWith({ kind: "blocked_action", detail: kind });
        expect(notify).toHaveBeenCalled();
    });

    it("stops Ctrl+P and Cmd+P but not the letter p", () => {
        bind({ lock: true });

        const ctrl = new KeyboardEvent("keydown", { key: "p", ctrlKey: true, bubbles: true, cancelable: true });
        const meta = new KeyboardEvent("keydown", { key: "P", metaKey: true, bubbles: true, cancelable: true });
        const plain = new KeyboardEvent("keydown", { key: "p", bubbles: true, cancelable: true });
        document.querySelector("#answer").dispatchEvent(ctrl);
        document.querySelector("#answer").dispatchEvent(meta);
        document.querySelector("#answer").dispatchEvent(plain);

        expect(ctrl.defaultPrevented).toBe(true);
        expect(meta.defaultPrevented).toBe(true);
        expect(plain.defaultPrevented).toBe(false);
        expect(record.mock.calls.filter(([e]) => e.detail === "print")).toHaveLength(2);
    });

    it("records printing started another way (the browser's menu), which it cannot stop", () => {
        bind({ lock: true });

        window.dispatchEvent(new Event("beforeprint"));

        expect(record).toHaveBeenCalledWith({ kind: "blocked_action", detail: "print" });
    });

    it("asks for fullscreen from the button, and says it failed if the browser refuses", async () => {
        bind({ lock: true });

        document.querySelector("[data-guard-fullscreen]").dispatchEvent(new MouseEvent("click", { bubbles: true }));
        expect(document.documentElement.requestFullscreen).toHaveBeenCalledTimes(1);

        document.documentElement.requestFullscreen = vi.fn().mockRejectedValue(new Error("denied"));
        document.querySelector("[data-guard-fullscreen]").dispatchEvent(new MouseEvent("click", { bubbles: true }));
        await vi.waitFor(() => expect(notify).toHaveBeenCalledWith(expect.stringMatching(/fullscreen/i)));
    });

    it("records leaving fullscreen once it has been entered, not entering it", () => {
        bind({ lock: true });

        setFullscreen(document.documentElement);
        expect(record).not.toHaveBeenCalled();

        setFullscreen(null);
        expect(record).toHaveBeenCalledTimes(1);
        expect(record).toHaveBeenCalledWith({ kind: "left_fullscreen" });
        expect(notify).toHaveBeenCalledWith(expect.stringMatching(/fullscreen/i));

        setFullscreen(document.documentElement);
        expect(record).toHaveBeenCalledTimes(1);
    });

    it("does not stop copying when the lock is off", () => {
        bind({ detect: true });

        const event = new Event("copy", { bubbles: true, cancelable: true });
        document.querySelector("#answer").dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });
});

describe("when the quiz is over", () => {
    it("stop() takes everything off", () => {
        bind({ detect: true, lock: true });
        guard.stop();

        setVisibility("hidden");
        clock += 9_000;
        setVisibility("visible");
        const paste = new Event("paste", { bubbles: true, cancelable: true });
        document.querySelector("#answer").dispatchEvent(paste);

        expect(record).not.toHaveBeenCalled();
        expect(paste.defaultPrevented).toBe(false);
    });

    it("does nothing once the form has left the page (the quiz was handed in)", () => {
        bind({ detect: true, lock: true });
        document.body.innerHTML = "";

        const paste = new Event("paste", { bubbles: true, cancelable: true });
        document.body.dispatchEvent(paste);
        window.dispatchEvent(new Event("blur"));
        clock += 9_000;
        window.dispatchEvent(new Event("focus"));

        expect(record).not.toHaveBeenCalled();
        expect(paste.defaultPrevented).toBe(false);
    });

    it("does not fail on a page without the form", () => {
        document.body.innerHTML = "";

        expect(bindAttemptGuard({ form: null, record: vi.fn(), notify: vi.fn() }).active).toBe(false);
    });
});
