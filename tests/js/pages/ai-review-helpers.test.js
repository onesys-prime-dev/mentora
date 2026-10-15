import { describe, expect, it } from "vitest";

import {
    acceptCounts,
    acceptReport,
    cardChips,
    sourceLine,
} from "../../../resources/js/pages/courses/features/assessments/ai-generation/review-helpers.js";

describe("cardChips", () => {

    it("says Checked for a question the second check confirmed and nothing else flagged", () => {
        expect(cardChips({ verification: { solver_agrees: true } })).toEqual([{ label: "Checked", tone: "ok" }]);
    });

    it("lists the serious problems first", () => {
        const chips = cardChips({
            math_check: "mismatch",
            duplicate_of: "Which layer routes packets?",
            similarity_score: 0.5,
            verification: { solver_agrees: false },
        });

        expect(chips.map((c) => c.label)).toEqual([
            "Marked answer looks wrong",
            "A second check disagreed",
            "Already in this quiz",
            "Similar to source",
        ]);
        expect(chips[0].tone).toBe("danger");
    });

    it("tells a close copy of the source from a loose likeness", () => {
        expect(cardChips({ similarity_score: 0.7 })[0].label).toBe("Copies the source closely");
        expect(cardChips({ similarity_score: 0.45 })[0].label).toBe("Similar to source");
        expect(cardChips({ similarity_score: 0.2 })).toEqual([]);
    });

});

describe("sourceLine", () => {

    it("names the title and page when known", () => {
        expect(sourceLine({ source_title: "Networking basics", source_page: 4 })).toBe("From Networking basics, page 4");
        expect(sourceLine({ source_title: "Networking basics" })).toBe("From Networking basics");
        expect(sourceLine({ source_page: 2 })).toBe("From the material, page 2");
        expect(sourceLine({})).toBe("");
    });

});

describe("acceptCounts and acceptReport", () => {

    it("counts what accept all verified will add", () => {
        expect(acceptCounts([{ needs_look: true }, {}, {}, { needs_look: false }]))
            .toEqual({ total: 4, clear: 3, needLook: 1 });
    });

    it("reports what was added and what is left", () => {
        expect(acceptReport({ accepted_count: 8, skipped_count: 2 })).toBe("8 questions were added; 2 left to review.");
        expect(acceptReport({ accepted_count: 1, skipped_count: 0 })).toBe("1 question was added.");
    });

});

import { REPLACE_CHOICES, otherTypes } from "../../../resources/js/pages/courses/features/assessments/ai-generation/review-helpers.js";

describe("replace choices", () => {

    it("offers different, easier and harder", () => {
        expect(REPLACE_CHOICES.map((c) => c.mode)).toEqual(["different", "easier", "harder"]);
    });

    it("offers the other types, never the current one", () => {
        expect(otherTypes("Essay")).toEqual(["Multiple Choice", "Short Answer"]);
        expect(otherTypes("Multiple Choice")).not.toContain("Multiple Choice");
    });

});

import { coverageRows, shortfallText } from "../../../resources/js/pages/courses/features/assessments/ai-generation/review-helpers.js";

describe("shortfallText", () => {

    it("says nothing when nothing is missing", () => {
        expect(shortfallText({ missing: 0, reasons: [], not_enough_material: false })).toBe("");
        expect(shortfallText(undefined)).toBe("");
    });

    it("names what is missing and why, in plain words", () => {
        const text = shortfallText({
            missing: 3,
            reasons: [{ count: 2, text: "a second check did not agree with the marked answer" }, { count: 1, text: "it repeated another question" }],
            not_enough_material: true,
        });

        expect(text).toBe(
            "3 questions are missing: there was not enough distinct material to write more; "
            + "2 were dropped because a second check did not agree with the marked answer; "
            + "1 was dropped because it repeated another question."
        );
    });

});

describe("coverageRows", () => {

    it("says how many questions came from each reading and flags the empty ones", () => {
        expect(coverageRows([{ title: "Routing", count: 5 }, { title: "Slides", count: 0 }, { title: "One", count: 1 }])).toEqual([
            { title: "Routing", text: "5 questions", empty: false },
            { title: "Slides", text: "no questions", empty: true },
            { title: "One", text: "1 question", empty: false },
        ]);
    });

});
