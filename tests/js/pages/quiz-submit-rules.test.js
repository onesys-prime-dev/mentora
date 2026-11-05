import { afterEach, describe, expect, it, vi } from "vitest";

import { bindSubmitRules, unanswered } from "../../../resources/js/pages/courses/features/assessments/quiz-taking/submit-rules.js";

/** [required?, answered?] per question, in a trimmed copy of the form in quiz-answer-form.php. */
const markup = (questions) => `
    <form id="quiz-answer-form">
        ${questions.map(([required, answered]) => `<div data-quiz-question data-required="${required ? 1 : 0}" data-answered="${answered ? 1 : 0}"></div>`).join("")}
    </form>
    <p data-submit-hint class="hidden"></p>
    <button type="button" data-submit-quiz>Submit Quiz</button>`;

const $ = (selector) => document.querySelector(selector);
const click = () => $("[data-submit-quiz]").dispatchEvent(new MouseEvent("click", { bubbles: true }));
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

let ask;
let submit;

const bind = (questions, answer = true) => {
    document.body.innerHTML = markup(questions);
    ask = vi.fn().mockResolvedValue(answer);
    submit = vi.fn();

    return bindSubmitRules({ form: $("#quiz-answer-form"), button: $("[data-submit-quiz]"), hint: $("[data-submit-hint]"), ask, submit });
};

const answer = (index, on = true) => {
    document.querySelectorAll("[data-quiz-question]")[index].dataset.answered = on ? "1" : "0";
};

afterEach(() => {
    document.body.innerHTML = "";
});

describe("counting what is unanswered", () => {
    it("counts required and optional separately", () => {
        document.body.innerHTML = markup([[true, false], [true, true], [false, false], [false, false], [false, true]]);

        expect(unanswered($("#quiz-answer-form"))).toEqual({ required: 1, optional: 2 });
    });
});

describe("the Submit button", () => {
    it("is disabled while a required question is unanswered, and says how many are left", () => {
        bind([[true, false], [true, false], [false, false]]);

        expect($("[data-submit-quiz]").disabled).toBe(true);
        expect($("[data-submit-hint]").textContent).toBe("Answer the 2 required questions to submit.");
        expect($("[data-submit-hint]").classList.contains("hidden")).toBe(false);
    });

    it("says it in the singular for one", () => {
        bind([[true, false]]);

        expect($("[data-submit-hint]").textContent).toBe("Answer the 1 required question to submit.");
    });

    it("is enabled, with no hint, when every required question is answered, whatever optional ones are left", () => {
        bind([[true, true], [false, false]]);

        expect($("[data-submit-quiz]").disabled).toBe(false);
        expect($("[data-submit-hint]").classList.contains("hidden")).toBe(true);
    });

    it("follows the answers as they come and go", () => {
        const { refresh } = bind([[true, false], [true, false]]);

        answer(0);
        refresh();
        expect($("[data-submit-quiz]").disabled).toBe(true);
        expect($("[data-submit-hint]").textContent).toContain("1 required question ");

        answer(1);
        refresh();
        expect($("[data-submit-quiz]").disabled).toBe(false);

        answer(1, false);
        refresh();
        expect($("[data-submit-quiz]").disabled).toBe(true);
    });

    it("does nothing when clicked while it is disabled (even if something enabled it by hand)", async () => {
        bind([[true, false]]);
        $("[data-submit-quiz]").disabled = false;

        click();
        await tick();

        expect(ask).not.toHaveBeenCalled();
        expect(submit).not.toHaveBeenCalled();
    });
});

describe("pressing Submit", () => {
    it("asks for a plain confirmation when everything is answered, and submits on yes", async () => {
        bind([[true, true], [false, true]]);

        click();
        await tick();

        expect(ask).toHaveBeenCalledWith(expect.objectContaining({ text: expect.stringMatching(/change them/i), yes: "Submit" }));
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it("names the unanswered optional questions in the confirmation", async () => {
        bind([[true, true], [false, false], [false, false], [false, false]]);

        click();
        await tick();

        expect(ask).toHaveBeenCalledWith(expect.objectContaining({ text: "You have 3 unanswered questions. Submit anyway?", yes: "Submit anyway" }));
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it("says one unanswered question in the singular", async () => {
        bind([[false, false]]);

        click();
        await tick();

        expect(ask).toHaveBeenCalledWith(expect.objectContaining({ text: "You have 1 unanswered question. Submit anyway?" }));
    });

    it("does not submit when the student says no", async () => {
        bind([[false, false]], false);

        click();
        await tick();

        expect(submit).not.toHaveBeenCalled();
    });

    it("does not ask twice for a double click while the question is open", async () => {
        document.body.innerHTML = markup([[false, false]]);
        let release;
        ask = vi.fn(() => new Promise((resolve) => { release = resolve; }));
        submit = vi.fn();
        bindSubmitRules({ form: $("#quiz-answer-form"), button: $("[data-submit-quiz]"), hint: $("[data-submit-hint]"), ask, submit });

        click();
        click();
        release(true);
        await tick();

        expect(ask).toHaveBeenCalledTimes(1);
        expect(submit).toHaveBeenCalledTimes(1);
    });
});
