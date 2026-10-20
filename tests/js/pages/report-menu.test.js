import { describe, expect, it } from "vitest";

import { filenameFrom } from "../../../resources/js/pages/courses/features/grades/reports/report-menu.js";

describe("the file name of a downloaded report", () => {

    it("reads the name the server suggested", () => {

        expect(filenameFrom('attachment; filename="gradebook-IT101-A-20261020.xlsx"')).toBe("gradebook-IT101-A-20261020.xlsx");
        expect(filenameFrom("attachment; filename=my-grades.pdf")).toBe("my-grades.pdf");

    });

    it("understands an encoded name", () => {

        expect(filenameFrom("attachment; filename*=UTF-8''grade%20report.pdf")).toBe("grade report.pdf");

    });

    it("falls back to a plain name when the server gave none", () => {

        expect(filenameFrom(null)).toBe("report");
        expect(filenameFrom("", "grades")).toBe("grades");

    });

});
