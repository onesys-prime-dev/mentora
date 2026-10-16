import { describe, expect, it } from "vitest";

import {
    addLabel,
    filterQuestions,
    questionMeta,
} from "../../../resources/js/pages/courses/features/assessments/import-export/question-picker.js";

const questions = [
    { id: 1, question_text: "Which layer routes packets?" },
    { id: 2, question_text: "What does CPU stand for?" },
    { id: 3, question_text: "Define latency." },
];

describe("filterQuestions", () => {

    it("returns everything for an empty search", () => {
        expect(filterQuestions(questions, "  ")).toHaveLength(3);
    });

    it("matches every word in any order, ignoring case", () => {
        expect(filterQuestions(questions, "PACKETS layer").map((q) => q.id)).toEqual([1]);
    });

    it("returns nothing when a word is missing", () => {
        expect(filterQuestions(questions, "layer latency")).toEqual([]);
    });

});

describe("addLabel", () => {

    it("names the count", () => {
        expect(addLabel(0)).toBe("Add questions");
        expect(addLabel(1)).toBe("Add 1 question");
        expect(addLabel(4)).toBe("Add 4 questions");
    });

});

describe("questionMeta", () => {

    it("says the type and the points", () => {
        expect(questionMeta({ question_type: "Essay", points: 1 })).toBe("Essay, 1 point");
        expect(questionMeta({ question_type: "Multiple Choice", points: "2.5" })).toBe("Multiple Choice, 2.5 points");
    });

});
