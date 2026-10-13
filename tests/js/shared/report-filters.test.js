import { describe, expect, it } from "vitest";
import { isFiltered, keepProgram, programsFor } from "../../../resources/js/shared/report-filters.js";

const programs = [
    { id: 1, name: "Information Technology", department_id: 10 },
    { id: 2, name: "Computer Science", department_id: 10 },
    { id: 3, name: "Accountancy", department_id: 20 },
];

describe("report filter state", () => {
    it("offers every program when no department is chosen", () => {
        expect(programsFor("", programs)).toHaveLength(3);
        expect(programsFor(null, programs)).toHaveLength(3);
        expect(programsFor(undefined, programs)).toHaveLength(3);
    });

    it("offers only the chosen department's programs", () => {
        expect(programsFor("10", programs).map((p) => p.id)).toEqual([1, 2]);
        expect(programsFor(20, programs).map((p) => p.id)).toEqual([3]);
    });

    it("offers none for a department that has none", () => {
        expect(programsFor("99", programs)).toEqual([]);
    });

    it("keeps the chosen program while it is still offered", () => {
        expect(keepProgram("2", programsFor("10", programs))).toBe("2");
        expect(keepProgram(2, programsFor("", programs))).toBe("2");
    });

    it("drops a program that no longer fits the department", () => {
        expect(keepProgram("3", programsFor("10", programs))).toBe("");
        expect(keepProgram("", programs)).toBe("");
        expect(keepProgram(null, programs)).toBe("");
    });

    it("says whether anything narrows the report", () => {
        expect(isFiltered({ term: "", department: "", program: "", from: "", to: "" })).toBe(false);
        expect(isFiltered({ term: "", department: "10", program: "" })).toBe(true);
        expect(isFiltered({ from: "  " })).toBe(false);
    });
});
