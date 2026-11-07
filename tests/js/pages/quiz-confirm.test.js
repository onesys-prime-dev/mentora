import { afterEach, describe, expect, it } from "vitest";

import { askInQuiz } from "../../../resources/js/pages/courses/features/assessments/quiz-taking/confirm.js";

/** A trimmed copy of the dialog the quiz page renders (components/dialog.php with the quiz's buttons). */
const markup = `
    <div id="quiz-confirm" data-dialog hidden class="hidden" role="dialog" aria-modal="true">
        <div tabindex="-1">
            <h2 data-dialog-title>Submit your answers?</h2>
            <button type="button" data-dialog-close aria-label="Close">x</button>
            <p data-quiz-confirm-text></p>
            <button type="button" data-dialog-close data-quiz-confirm-no>Keep working</button>
            <button type="button" data-quiz-confirm-yes>Submit</button>
        </div>
    </div>`;

const $ = (selector) => document.querySelector(selector);
const isOpen = () => !$("#quiz-confirm").hidden;
const options = { title: "Leave this quiz?", text: "Your answers are saved. Leave this page?", yes: "Leave anyway", no: "Stay" };

afterEach(() => {
    document.body.innerHTML = "";
    document.body.className = "";
});

describe("asking inside the quiz page", () => {
    it("opens with the words given", () => {
        document.body.innerHTML = markup;

        askInQuiz(options);

        expect(isOpen()).toBe(true);
        expect($("[data-dialog-title]").textContent).toBe("Leave this quiz?");
        expect($("[data-quiz-confirm-text]").textContent).toBe("Your answers are saved. Leave this page?");
        expect($("[data-quiz-confirm-yes]").textContent).toBe("Leave anyway");
        expect($("[data-quiz-confirm-no]").textContent).toBe("Stay");
    });

    it("answers true on the confirm button and closes", async () => {
        document.body.innerHTML = markup;

        const answer = askInQuiz(options);
        $("[data-quiz-confirm-yes]").click();

        expect(await answer).toBe(true);
        expect(isOpen()).toBe(false);
    });

    it("answers false on the other button, on the X and on Escape", async () => {
        document.body.innerHTML = markup;

        for (const close of [
            () => $("[data-quiz-confirm-no]").click(),
            () => $("[data-dialog-close]").click(),
            () => $("#quiz-confirm").dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true })),
        ]) {
            const answer = askInQuiz(options);
            close();

            expect(await answer).toBe(false);
        }
    });

    it("answers false at once when the page has no dialog", async () => {
        document.body.innerHTML = "";

        expect(await askInQuiz(options)).toBe(false);
    });

    it("can be asked again after it was answered", async () => {
        document.body.innerHTML = markup;

        const first = askInQuiz(options);
        $("[data-quiz-confirm-yes]").click();
        await first;

        const second = askInQuiz({ ...options, text: "Again?" });
        expect($("[data-quiz-confirm-text]").textContent).toBe("Again?");
        $("[data-quiz-confirm-no]").click();

        expect(await second).toBe(false);
    });
});
