import { describe, expect, it } from "vitest";
import { isFiltered, keepProgram, programsFor } from "../../../resources/js/shared/report-filters.js";

const programs = [
    { id: 1, name: "Information Technology", college_id: 10 },
    { id: 2, name: "Computer Science", college_id: 10 },
    { id: 3, name: "Accountancy", college_id: 20 },
];

describe("report filter state", () => {
    it("offers every program when no college is chosen", () => {
        expect(programsFor("", programs)).toHaveLength(3);
        expect(programsFor(null, programs)).toHaveLength(3);
        expect(programsFor(undefined, programs)).toHaveLength(3);
    });

    it("offers only the chosen college's programs", () => {
        expect(programsFor("10", programs).map((p) => p.id)).toEqual([1, 2]);
        expect(programsFor(20, programs).map((p) => p.id)).toEqual([3]);
    });

    it("offers none for a college that has none", () => {
        expect(programsFor("99", programs)).toEqual([]);
    });

    it("keeps the chosen program while it is still offered", () => {
        expect(keepProgram("2", programsFor("10", programs))).toBe("2");
        expect(keepProgram(2, programsFor("", programs))).toBe("2");
    });

    it("drops a program that no longer fits the college", () => {
        expect(keepProgram("3", programsFor("10", programs))).toBe("");
        expect(keepProgram("", programs)).toBe("");
        expect(keepProgram(null, programs)).toBe("");
    });

    it("says whether anything narrows the report", () => {
        expect(isFiltered({ term: "", college: "", program: "", from: "", to: "" })).toBe(false);
        expect(isFiltered({ term: "", college: "10", program: "" })).toBe(true);
        expect(isFiltered({ from: "  " })).toBe(false);
    });
});
