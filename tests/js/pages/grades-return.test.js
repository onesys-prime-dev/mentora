import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const leaveTo = vi.fn();

vi.mock("../../../resources/js/shared/grades-return.js", async (importOriginal) => ({
    ...(await importOriginal()),
    leaveTo: (url) => leaveTo(url),
}));

vi.mock("../../../resources/js/pages/courses/features/assessments/api.js", async (importOriginal) => ({
    ...(await importOriginal()),
    fetchGradeWork: vi.fn(async () => ({
        html: '<button data-back-to-assessments><svg></svg></button>',
    })),
}));

vi.mock("../../../resources/js/pages/courses/features/assessments/grading/grading.js", () => ({
    initializeGradeWork: vi.fn(),
}));

import { safeReturn } from "../../../resources/js/shared/grades-return.js";
import { loadGradeWork } from "../../../resources/js/pages/courses/features/assessments/index.js";

const ORIGIN = "http://localhost";

describe("safeReturn", () => {
    it("accepts the Grades page with any query", () => {
        expect(safeReturn("/instructor/grades?tab=to-grade&qsection=3", ORIGIN)).toBe("/instructor/grades?tab=to-grade&qsection=3");
        expect(safeReturn("/mentora/public/instructor/grades?term=3", ORIGIN)).toBe("/mentora/public/instructor/grades?term=3");
        expect(safeReturn("/mentora/public/index.php/chairperson/grades", ORIGIN)).not.toBeNull();
        expect(safeReturn(`${ORIGIN}/instructor/grades?tab=to-grade`, ORIGIN)).not.toBeNull();
    });

    it("rejects other sites, schemes, other pages and empty values", () => {
        expect(safeReturn("https://evil.test/instructor/grades", ORIGIN)).toBeNull();
        expect(safeReturn("//evil.test/instructor/grades", ORIGIN)).toBeNull();
        expect(safeReturn("/\\evil.test/instructor/grades", ORIGIN)).toBeNull();
        expect(safeReturn("javascript:alert(1)", ORIGIN)).toBeNull();
        expect(safeReturn("/instructor/courses/3/x/grades", ORIGIN)).toBeNull();
        expect(safeReturn(null, ORIGIN)).toBeNull();
        expect(safeReturn("", ORIGIN)).toBeNull();
    });
});

describe("loadGradeWork with a return", () => {
    const back = "/mentora/public/index.php/instructor/grades?term=3&tab=to-grade&qsection=12";

    beforeEach(() => {
        leaveTo.mockClear();
        document.body.innerHTML = '<div id="course-content"></div>';
        window.coursesConfig = { role: "instructor", offeringId: 5 };
    });

    afterEach(() => {
        window.history.replaceState(null, "", "/");
    });

    it("keeps return on the next student and leads back to the queue", async () => {
        window.history.replaceState(null, "", `/instructor/courses/5/x/assessments?assessment=4&grade=1&return=${encodeURIComponent(back)}`);

        await loadGradeWork(5, 4, 9, () => {}, { pushState: true, mode: "refresh" });

        const params = new URLSearchParams(window.location.search);
        expect(params.get("student")).toBe("9");
        expect(params.get("return")).toBe(back);

        const button = document.querySelector("[data-back-to-assessments]");
        expect(button.textContent.trim()).toBe("← Back to To grade");

        button.click();
        expect(leaveTo).toHaveBeenCalledWith(back);
    });

    it("ignores a foreign return and behaves as before", async () => {
        window.history.replaceState(null, "", "/instructor/courses/5/x/assessments?assessment=4&grade=1&return=https%3A%2F%2Fevil.test%2Finstructor%2Fgrades");
        const refresh = vi.fn();

        await loadGradeWork(5, 4, 9, refresh, { pushState: true, mode: "refresh" });

        expect(new URLSearchParams(window.location.search).has("return")).toBe(false);

        const button = document.querySelector("[data-back-to-assessments]");
        expect(button.textContent.trim()).toBe("");

        button.click();
        expect(leaveTo).not.toHaveBeenCalled();
        expect(refresh).toHaveBeenCalled();
    });
});
