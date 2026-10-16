import { describe, expect, it } from "vitest";

import {
    fileSummary,
    importLabel,
    isAcceptedFile,
    recallExportPrefs,
    rememberExportPrefs,
    statusChip,
    stepState,
} from "../../../resources/js/pages/courses/features/assessments/import-export/import-ui.js";

describe("isAcceptedFile", () => {

    it("accepts the supported types in any case", () => {
        ["a.docx", "a.RTF", "b.xlsx", "c.csv", "d.txt", "e.pdf", "f.html", "g.xml", "h.zip"].forEach((n) => expect(isAcceptedFile(n)).toBe(true));
    });

    it("refuses other types and names without an extension", () => {
        ["a.exe", "a.png", "docx", ".docx"].forEach((n) => expect(isAcceptedFile(n)).toBe(false));
    });

});

describe("fileSummary", () => {

    it("names the file and its size", () => {
        expect(fileSummary({ name: "quiz.docx", size: 24 * 1024 })).toBe("quiz.docx, 24 KB");
        expect(fileSummary({ name: "big.xlsx", size: 3 * 1024 * 1024 })).toBe("big.xlsx, 3.0 MB");
        expect(fileSummary({ name: "tiny.csv", size: 10 })).toBe("tiny.csv, 1 KB");
    });

});

describe("stepState", () => {

    it("marks earlier steps done and later ones upcoming", () => {
        expect(stepState("upload", "review")).toBe("done");
        expect(stepState("review", "review")).toBe("current");
        expect(stepState("done", "review")).toBe("upcoming");
    });

});

describe("statusChip", () => {

    it("calls an importable new question New and an existing one Changed", () => {
        expect(statusChip({ importable: true, problems: [] }).label).toBe("New");
        expect(statusChip({ id: 4, importable: true, problems: [] }).label).toBe("Changed");
    });

    it("flags a likely duplicate and a question that cannot be imported", () => {
        const dup = { importable: true, problems: [{ severity: "warning", message: 'This looks like a question already in the quiz: "x".' }] };
        const bad = { importable: false, problems: [{ severity: "error", message: "No correct answer." }] };

        expect(statusChip(dup).tone).toBe("duplicate");
        expect(statusChip(bad).tone).toBe("problem");
    });

});

describe("importLabel", () => {

    it("names how many questions go in", () => {
        expect(importLabel({ added: 0, changed: 0, removed: 0 })).toBe("Import");
        expect(importLabel({ added: 1, changed: 0, removed: 0 })).toBe("Import 1 question");
        expect(importLabel({ added: 8, changed: 2, removed: 0 })).toBe("Import 10 questions");
        expect(importLabel({ added: 3, changed: 0, removed: 2 })).toBe("Import 3 and delete 2");
    });

});

describe("export preferences", () => {

    function memory() {
        const data = {};
        return { getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = v; } };
    }

    it("remembers the last choice", () => {
        const store = memory();

        rememberExportPrefs({ format: "test-docx", print: { versions: 2 } }, store);

        expect(recallExportPrefs(store)).toEqual({ format: "test-docx", print: { versions: 2 } });
    });

    it("starts empty when nothing is stored, or storage is unusable or corrupt", () => {
        expect(recallExportPrefs(memory())).toEqual({});
        expect(recallExportPrefs({ getItem: () => "{not json" })).toEqual({});
        expect(recallExportPrefs({ getItem: () => { throw new Error("blocked"); } })).toEqual({});
        expect(() => rememberExportPrefs({ a: 1 }, { setItem: () => { throw new Error("full"); } })).not.toThrow();
    });

});

import { detectedLine } from "../../../resources/js/pages/courses/features/assessments/import-export/import-ui.js";

describe("detectedLine", () => {

    it("names what the file was read as", () => {
        expect(detectedLine({ detected: "Blackboard (QTI)" })).toBe("Read as: Blackboard (QTI)");
        expect(detectedLine({ source: "mentora" })).toBe("Read as: Mentora export");
        expect(detectedLine({ source: "plain" })).toBe("");
    });

});
