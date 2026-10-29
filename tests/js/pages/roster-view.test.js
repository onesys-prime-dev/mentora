import { describe, expect, it } from "vitest";

import { compareStudents, matchesRow, studentOf } from "../../../resources/js/pages/courses/features/grades/roster/roster-view.js";
import { gradeNumber, gradePercent } from "../../../resources/js/shared/grade-format.js";

const student = (over = {}) => ({ search: "tester, ann 2026-001", name: "tester, ann", grade: 80, tag: "On track", override: false, ...over });

describe("which gradebook rows show", () => {

    it("matches the search text on name or number, in any case", () => {

        expect(matchesRow(student(), "ANN", "all")).toBe(true);
        expect(matchesRow(student(), "2026-001", "all")).toBe(true);
        expect(matchesRow(student(), "  ", "all")).toBe(true);
        expect(matchesRow(student(), "bob", "all")).toBe(false);

    });

    it("filters to who needs a look, who is incomplete, who has no grade and who was overridden", () => {

        expect(matchesRow(student({ tag: "At risk" }), "", "attention")).toBe(true);
        expect(matchesRow(student({ tag: "Failed" }), "", "attention")).toBe(true);
        expect(matchesRow(student({ tag: "On track" }), "", "attention")).toBe(false);

        expect(matchesRow(student({ tag: "INC" }), "", "inc")).toBe(true);
        expect(matchesRow(student(), "", "inc")).toBe(false);

        expect(matchesRow(student({ grade: null }), "", "ungraded")).toBe(true);
        expect(matchesRow(student({ grade: 0 }), "", "ungraded")).toBe(false);

        expect(matchesRow(student({ override: true }), "", "override")).toBe(true);
        expect(matchesRow(student(), "", "override")).toBe(false);

    });

    it("applies the search and the filter together", () => {

        expect(matchesRow(student({ tag: "At risk" }), "ann", "attention")).toBe(true);
        expect(matchesRow(student({ tag: "At risk" }), "bob", "attention")).toBe(false);

    });

});

describe("how the gradebook rows are ordered", () => {

    const rows = [
        student({ name: "cruz", grade: 60 }),
        student({ name: "abad", grade: null }),
        student({ name: "bautista", grade: 90 }),
        student({ name: "dela", grade: 60 }),
    ];

    const order = (sort) => [...rows].sort(compareStudents(sort)).map((r) => r.name);

    it("sorts by name", () => {
        expect(order("name")).toEqual(["abad", "bautista", "cruz", "dela"]);
    });

    it("sorts by grade, with no grade last either way and ties by name", () => {
        expect(order("grade-desc")).toEqual(["bautista", "cruz", "dela", "abad"]);
        expect(order("grade-asc")).toEqual(["cruz", "dela", "bautista", "abad"]);
    });

});

describe("reading a row", () => {

    it("takes the values from its data attributes", () => {

        const row = document.createElement("tr");

        Object.assign(row.dataset, { search: "x", name: "x", grade: "72.5", tag: "At risk", override: "1" });

        expect(studentOf(row)).toMatchObject({ grade: 72.5, tag: "At risk", override: true });

        row.dataset.grade = "";

        expect(studentOf(row).grade).toBeNull();

    });

});

describe("how a grade is written", () => {

    it("keeps up to two decimals and drops trailing zeros", () => {

        expect(gradePercent(85)).toBe("85%");
        expect(gradePercent(85.5)).toBe("85.5%");
        expect(gradePercent("85.25")).toBe("85.25%");
        expect(gradePercent(85.256)).toBe("85.26%");
        expect(gradeNumber(0)).toBe("0");

    });

    it("shows a dash when there is no grade", () => {

        expect(gradePercent(null)).toBe("—");
        expect(gradePercent("")).toBe("—");
        expect(gradePercent(undefined)).toBe("—");
        expect(gradePercent("n/a")).toBe("—");
        expect(gradePercent(null, "")).toBe("");

    });

});
