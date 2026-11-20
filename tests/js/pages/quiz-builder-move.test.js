import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("../../../resources/js/pages/courses/features/assessments/api.js", () => ({
    createQuestion: vi.fn(),
    updateQuestion: vi.fn(),
    deleteQuestion: vi.fn(),
    duplicateQuestion: vi.fn(),
    reorderQuestions: vi.fn().mockResolvedValue({ success: true }),
    clearAllQuestions: vi.fn(),
}));

vi.mock("../../../resources/js/shared/toast.js", () => ({ showToast: vi.fn() }));

const { showToast } = await import("../../../resources/js/shared/toast.js");
const { reorderQuestions } = await import("../../../resources/js/pages/courses/features/assessments/api.js");
const { initializeQuestions } = await import("../../../resources/js/pages/courses/features/assessments/questions/questions.js");

const card = (id) => `
    <div data-question-card data-question-id="${id}">
        <span data-question-number>Q?</span>
        <button type="button" data-question-move="up">Up</button>
        <button type="button" data-question-move="down">Down</button>
    </div>`;

const ids = () => [...document.querySelectorAll("[data-question-card]")].map((c) => c.dataset.questionId);
const move = (id, dir) => document.querySelector(`[data-question-id="${id}"] [data-question-move="${dir}"]`);

let refresh;

beforeEach(() => {
    reorderQuestions.mockReset().mockResolvedValue({ success: true });
    showToast.mockClear();
    refresh = vi.fn();
    document.body.innerHTML = `
        <div data-question-list>${card("a")}${card("b")}${card("c")}</div>
        <p data-question-move-status aria-live="polite"></p>`;
    initializeQuestions(7, 9, refresh);
});

describe("quiz builder move buttons", () => {
    it("a move button reorders the list and calls the reorder path", async () => {
        const up = move("c", "up");
        up.focus();
        up.click();

        expect(ids()).toEqual(["a", "c", "b"]);
        expect(reorderQuestions).toHaveBeenCalledWith(7, 9, ["a", "c", "b"]);
        expect(document.querySelector('[data-question-id="c"] [data-question-number]').textContent).toBe("Q2");
        // Focus stays on the button that was pressed, and the move is announced.
        expect(document.activeElement).toBe(up);
        expect(document.querySelector("[data-question-move-status]").textContent).toBe("Question moved to position 2 of 3.");
    });

    it("the first card's up button is disabled", () => {
        expect(move("a", "up").disabled).toBe(true);
        expect(move("a", "down").disabled).toBe(false);
        expect(move("c", "down").disabled).toBe(true);

        // Reaching the top hands focus to the card's other arrow, since a disabled button cannot keep it.
        const up = move("b", "up");
        up.focus();
        up.click();
        expect(ids()).toEqual(["b", "a", "c"]);
        expect(up.disabled).toBe(true);
        expect(document.activeElement).toBe(move("b", "down"));
        expect(move("a", "up").disabled).toBe(false);
    });

    it("a disabled arrow does nothing", () => {
        move("a", "up").click();
        expect(ids()).toEqual(["a", "b", "c"]);
        expect(reorderQuestions).not.toHaveBeenCalled();
    });

    it("rapid taps send one save at a time and end on the order on screen", async () => {
        let finish;
        reorderQuestions.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));

        move("c", "up").click();
        move("c", "up").click();
        expect(ids()).toEqual(["c", "a", "b"]);
        // The second tap waits for the first save instead of racing it.
        expect(reorderQuestions).toHaveBeenCalledTimes(1);
        expect(reorderQuestions).toHaveBeenLastCalledWith(7, 9, ["a", "c", "b"]);

        finish({ success: true });
        await vi.waitFor(() => expect(reorderQuestions).toHaveBeenCalledTimes(2));
        expect(reorderQuestions).toHaveBeenLastCalledWith(7, 9, ["c", "a", "b"]);

        // Settled: the next tap saves straight away again.
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        move("a", "down").click();
        expect(reorderQuestions).toHaveBeenCalledTimes(3);
        expect(reorderQuestions).toHaveBeenLastCalledWith(7, 9, ["c", "b", "a"]);
    });

    it("a failed save says so and reloads the builder to put the list back", async () => {
        reorderQuestions.mockRejectedValueOnce(new Error("offline"));
        vi.spyOn(console, "error").mockImplementation(() => {});

        move("b", "up").click();

        await vi.waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
        expect(showToast).toHaveBeenCalledWith("The new order could not be saved. Try again.", { tone: "danger" });
    });
});
