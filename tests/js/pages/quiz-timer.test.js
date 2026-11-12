import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const submitQuizAttempt = vi.fn();

vi.mock("../../../resources/js/pages/courses/features/assessments/api.js", () => ({
    recordSecurityEvent: vi.fn(),
    saveQuizAnswer: vi.fn(),
    submitQuizAttempt: (...a) => submitQuizAttempt(...a),
}));
vi.mock("../../../resources/js/shared/toast.js", () => ({ showToast: vi.fn() }));

const { initializeQuizTaking } = await import("../../../resources/js/pages/courses/features/assessments/quiz-taking/quiz-taking.js");

// The top bar's timer, and the copy in the pinned submit bar that phones see (quiz-answer-form.php).
const page = (endsAt) => `
    <div data-quiz-timer data-ends-at="${endsAt}"><span data-quiz-timer-value>--:--</span></div>
    <form id="quiz-answer-form" data-submission-id="7">
        <div>
            <span data-quiz-timer-copy aria-hidden="true"><span data-quiz-timer-value>--:--</span></span>
            <p data-submit-hint class="hidden"></p>
            <button type="button" data-submit-quiz>Submit Quiz</button>
        </div>
    </form>`;

const values = () => [...document.querySelectorAll("[data-quiz-timer-value]")].map((el) => el.textContent);

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-11-12T10:00:00Z"));
    submitQuizAttempt.mockReset().mockResolvedValue({ success: true, html: "" });
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("the quiz timer", () => {
    it("counts down in the top bar and in the submit bar's copy alike", () => {
        document.body.innerHTML = page("2026-11-12T10:01:30Z");
        initializeQuizTaking(1, 2, vi.fn());

        expect(values()).toEqual(["1:30", "1:30"]);
        vi.advanceTimersByTime(31_000);
        expect(values()).toEqual(["0:59", "0:59"]);
    });

    it("shows 0:00 in both and hands the quiz in once when time is up", async () => {
        document.body.innerHTML = page("2026-11-12T10:00:02Z");
        initializeQuizTaking(1, 2, vi.fn());

        await vi.advanceTimersByTimeAsync(5_000);

        expect(values()).toEqual(["0:00", "0:00"]);
        expect(submitQuizAttempt).toHaveBeenCalledTimes(1);
        expect(submitQuizAttempt).toHaveBeenCalledWith(1, "7");
    });
});

describe("a failed autosave", () => {
    it("says so in one danger toast, since the top bar's status scrolls away on phones", async () => {
        const { saveQuizAnswer } = await import("../../../resources/js/pages/courses/features/assessments/api.js");
        const { showToast } = await import("../../../resources/js/shared/toast.js");
        saveQuizAnswer.mockReset().mockRejectedValue(new Error("offline"));
        showToast.mockReset().mockImplementation(() => document.body.appendChild(document.createElement("div")));
        vi.spyOn(console, "error").mockImplementation(() => {});

        document.body.innerHTML = page("2026-11-12T11:00:00Z").replace(
            "<div>",
            '<div data-quiz-question data-question-id="5"><textarea data-quiz-text-input></textarea></div><div>'
        );
        initializeQuizTaking(1, 2, vi.fn());

        const input = document.querySelector("[data-quiz-text-input]");
        for (const value of ["a", "ab"]) {
            input.value = value;
            input.dispatchEvent(new Event("input", { bubbles: true }));
            await vi.advanceTimersByTimeAsync(1_000);
        }

        expect(saveQuizAnswer).toHaveBeenCalledTimes(2);
        expect(showToast).toHaveBeenCalledTimes(1);
        expect(showToast).toHaveBeenCalledWith(expect.stringContaining("not saved"), { tone: "danger" });
    });
});
