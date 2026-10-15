import { describe, expect, it } from "vitest";

import {
    DEFAULTS,
    fineTuneSummary,
    recall,
    remember,
    sanitize,
} from "../../../resources/js/pages/courses/features/assessments/ai-generation/generate-settings.js";

function memory() {
    const data = {};
    return { getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = v; } };
}

describe("sanitize", () => {

    it("falls back to the defaults for nothing or nonsense", () => {
        expect(sanitize(null)).toEqual({ ...DEFAULTS, types: { multiple_choice: 10 } });
        expect(sanitize("x").count).toBe(10);
    });

    it("keeps values in range and drops unknown options", () => {
        const s = sanitize({
            count: 500, choices: 9, difficulty: "brutal", level: "apply",
            types: { true_false: 3, "bad key!": 2, essay: 0 }, language: "x".repeat(80), focus: 5,
        });

        expect(s.count).toBe(100);
        expect(s.choices).toBe(5);
        expect(s.difficulty).toBe("mixed");
        expect(s.level).toBe("apply");
        expect(s.types).toEqual({ true_false: 3 });
        expect(s.language).toHaveLength(40);
        expect(s.focus).toBe("");
    });

});

describe("remember and recall", () => {

    it("returns what was asked for last time, per course", () => {
        const store = memory();

        remember(7, { ...DEFAULTS, count: 20, difficulty: "harder", types: { true_false: 20 } }, store);

        expect(recall(7, store).count).toBe(20);
        expect(recall(7, store).types).toEqual({ true_false: 20 });
        expect(recall(8, store).count).toBe(10);
    });

    it("survives storage that is blocked or holds rubbish", () => {
        expect(recall(1, { getItem: () => { throw new Error("blocked"); } }).count).toBe(10);
        expect(recall(1, { getItem: () => "{oops" }).count).toBe(10);
        expect(() => remember(1, DEFAULTS, { setItem: () => { throw new Error("full"); } })).not.toThrow();
    });

});

describe("fineTuneSummary", () => {

    it("is empty for the defaults and names what differs", () => {
        expect(fineTuneSummary(DEFAULTS)).toBe("");
        expect(fineTuneSummary({ ...DEFAULTS, difficulty: "harder", choices: 5, focus: "ch 3" })).toBe("(harder, 5 choices, focused)");
    });

});
