import { describe, expect, it } from "vitest";

import { scoreText } from "../../../resources/js/pages/courses/features/grades/import-export/grade-sheet.js";

describe("how a score reads in the import preview", () => {

    it("says Excused for an excused item, whatever number is left behind", () => {
        expect(scoreText(null, true)).toBe("Excused");
        expect(scoreText(7, true)).toBe("Excused");
    });

    it("shows a number with up to two decimals", () => {
        expect(scoreText(7, false)).toBe("7");
        expect(scoreText(7.5, false)).toBe("7.5");
        expect(scoreText(7.256, false)).toBe("7.26");
    });

    it("shows a dash when nothing is entered", () => {
        expect(scoreText(null, false)).toBe("—");
        expect(scoreText(undefined, undefined)).toBe("—");
    });

});
