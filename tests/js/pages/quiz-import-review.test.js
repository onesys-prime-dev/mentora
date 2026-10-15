import { describe, expect, it } from "vitest";
import { describeItem, importSummary } from "../../../resources/js/pages/courses/features/assessments/import-export/import-export.js";

describe("importSummary", () => {
    it("says what will happen with the ticked items", () => {
        expect(importSummary({ added: 3, changed: 1, removed: 0, skipped: 0 })).toBe("3 questions will be added, 1 will be updated.");
        expect(importSummary({ added: 1, changed: 0, removed: 2, skipped: 1 })).toBe("1 question will be added, 2 will be deleted, 1 skipped.");
    });

    it("says there is nothing to import when nothing is ticked", () => {
        expect(importSummary({ added: 0, changed: 0, removed: 0, skipped: 4 })).toBe("Nothing to import.");
    });
});

describe("describeItem", () => {
    it("shows the type, the points and the choices with the correct one marked", () => {
        const text = describeItem({
            question_type: "Multiple Choice",
            points: 2,
            choices: [{ text: "Router", is_correct: true }, { text: "Hub", is_correct: false }],
        });

        expect(text).toBe("Multiple Choice, 2 points — A. Router ✓  B. Hub");
    });

    it("uses the singular for one point and leaves out choices when there are none", () => {
        expect(describeItem({ question_type: "Essay", points: 1, choices: [] })).toBe("Essay, 1 point");
        expect(describeItem({ question_type: "Short Answer", points: "3" })).toBe("Short Answer, 3 points");
    });
});
