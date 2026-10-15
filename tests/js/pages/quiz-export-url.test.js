import { beforeEach, describe, expect, it } from "vitest";

import { exportQuestionsUrl } from "../../../resources/js/pages/courses/features/assessments/api.js";

beforeEach(() => {
    globalThis.window = { coursesConfig: { apiBaseUrl: "/api/courses/1" } };
});

describe("exportQuestionsUrl", () => {

    it("keeps the editable export free of print settings", () => {
        expect(exportQuestionsUrl(1, 9, "docx", { versions: 3 }))
            .toBe("/api/courses/1/assessments/9/export?format=docx");
    });

    it("carries the print settings for a printable test", () => {
        const url = exportQuestionsUrl(1, 9, "test-pdf", {
            versions: 2, paper: "Legal", shuffleQuestions: true, keyOnly: true,
        });

        expect(url).toContain("format=test-pdf");
        expect(url).toContain("versions=2");
        expect(url).toContain("paper=Legal");
        expect(url).toContain("shuffle_questions=1");
        expect(url).toContain("key_only=1");
        expect(url).not.toContain("shuffle_choices");
    });

    it("defaults to one version on A4", () => {
        const url = exportQuestionsUrl(1, 9, "test-docx");

        expect(url).toContain("versions=1");
        expect(url).toContain("paper=A4");
    });

});
