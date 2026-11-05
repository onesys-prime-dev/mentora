import { afterEach, describe, expect, it, vi } from "vitest";

import { bindLeaveGuard, leaveMessage } from "../../../resources/js/pages/courses/features/assessments/quiz-taking/leave-guard.js";

const markup = `
    <a id="away" href="/student/dashboard">Dashboard</a>
    <a id="blank" href="/elsewhere" target="_blank">New tab</a>
    <a id="hash" href="#top">Top</a>
    <button id="tab" data-course-feature="modules">Modules</button>
    <button id="back" data-back-to-assessment-view>Back</button>
    <button id="other">Something in the quiz</button>
    <form id="quiz-answer-form"></form>`;

const $ = (selector) => document.querySelector(selector);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

let ask;
let assign;
let guard;
let reached;
let history_;

const bind = ({ timed = false, answer = true } = {}) => {
    document.body.innerHTML = markup;
    ask = vi.fn().mockResolvedValue(answer);
    assign = vi.fn();
    history_ = { pushState: vi.fn(), back: vi.fn() };
    reached = vi.fn();

    // What the page would do with a click on them once the guard lets it through.
    ["tab", "back", "other"].forEach((id) => $(`#${id}`).addEventListener("click", reached));

    guard = bindLeaveGuard({ form: $("#quiz-answer-form"), timed, ask, assign, history: history_, quizUrl: "/student/courses/1/assessments/9/take" });

    return guard;
};

const click = (selector) => {
    const event = new MouseEvent("click", { bubbles: true, cancelable: true });
    $(selector).dispatchEvent(event);

    return event;
};

afterEach(() => {
    guard?.stop();
    document.body.innerHTML = "";
});

describe("what the student is told", () => {
    it("says the timer keeps running for a timed quiz", () => {
        expect(leaveMessage(true)).toBe("Your answers are saved, but the timer keeps running while you're away. Leave anyway?");
    });

    it("says only that the answers are saved for an untimed quiz", () => {
        expect(leaveMessage(false)).toBe("Your answers are saved. Leave this page?");
    });
});

describe("refreshing or closing the tab", () => {
    it("asks the browser to warn, while the quiz is in progress", () => {
        bind();

        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it("does not warn once the guard has stopped (the quiz was handed in)", () => {
        bind();
        guard.stop();

        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it("does not warn once the quiz has left the page", () => {
        bind();
        document.body.innerHTML = "";

        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });
});

describe("going to another page from inside the app", () => {
    it("asks before a link leaves, with the message for the quiz", async () => {
        bind({ timed: true });

        const event = click("#away");
        await tick();

        expect(event.defaultPrevented).toBe(true);
        expect(ask).toHaveBeenCalledWith(expect.objectContaining({ text: leaveMessage(true), yes: "Leave anyway" }));
    });

    it("goes there on yes, and stops warning", async () => {
        bind();

        click("#away");
        await tick();

        expect(assign).toHaveBeenCalledWith("http://localhost:3000/student/dashboard");
        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });

    it("stays on no", async () => {
        bind({ answer: false });

        click("#away");
        await tick();

        expect(assign).not.toHaveBeenCalled();
        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
    });

    it.each(["#tab", "#back"])("asks before %s, and lets it through on yes", async (selector) => {
        bind();

        click(selector);
        expect(reached).not.toHaveBeenCalled();
        await tick();

        expect(ask).toHaveBeenCalledTimes(1);
        expect(reached).toHaveBeenCalledTimes(1);
    });

    it("keeps it from happening on no", async () => {
        bind({ answer: false });

        click("#tab");
        await tick();

        expect(reached).not.toHaveBeenCalled();
    });

    it("leaves alone a new-tab link, a link within the page, and anything inside the quiz", async () => {
        bind();

        click("#blank");
        click("#hash");
        click("#other");
        await tick();

        expect(ask).not.toHaveBeenCalled();
        expect(reached).toHaveBeenCalledTimes(1); // #other, untouched
    });

    it("asks once for a double click", async () => {
        bind();

        click("#away");
        click("#away");
        await tick();

        expect(ask).toHaveBeenCalledTimes(1);
    });

    it("does not ask again after the guard has stopped", async () => {
        bind();
        guard.stop();

        const event = click("#away");
        await tick();

        expect(event.defaultPrevented).toBe(false);
        expect(ask).not.toHaveBeenCalled();
    });
});

describe("the browser's Back button", () => {
    it("is held on the quiz and asked about", async () => {
        bind({ answer: false });

        window.dispatchEvent(new PopStateEvent("popstate"));
        await tick();

        expect(history_.pushState).toHaveBeenCalledWith(null, "", "/student/courses/1/assessments/9/take");
        expect(ask).toHaveBeenCalledTimes(1);
        expect(history_.back).not.toHaveBeenCalled();
    });

    it("goes back for real on yes", async () => {
        bind();

        window.dispatchEvent(new PopStateEvent("popstate"));
        await tick();

        expect(history_.back).toHaveBeenCalledTimes(1);
    });

    it("does not let the page react to it while it is asking", () => {
        bind();
        const pageHandler = vi.fn();
        window.addEventListener("popstate", pageHandler);

        window.dispatchEvent(new PopStateEvent("popstate"));
        window.removeEventListener("popstate", pageHandler);

        expect(pageHandler).not.toHaveBeenCalled();
    });

    it("is not touched once the guard has stopped", () => {
        bind();
        guard.stop();

        window.dispatchEvent(new PopStateEvent("popstate"));

        expect(history_.pushState).not.toHaveBeenCalled();
    });
});

describe("a page without the quiz", () => {
    it("does nothing", () => {
        document.body.innerHTML = "";

        const none = bindLeaveGuard({ form: null, timed: false, ask: vi.fn() });
        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);

        expect(none.active).toBe(false);
        expect(event.defaultPrevented).toBe(false);
    });
});
