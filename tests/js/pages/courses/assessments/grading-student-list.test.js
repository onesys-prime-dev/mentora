import { afterEach, describe, expect, it, vi } from "vitest";

import { initializeGradeWork } from "../../../../../resources/js/pages/courses/features/assessments/grading/grading.js";

/* Grade Work's student rows load the student on click, except from the avatar (it opens the profile card). */

function setup() {
    document.body.innerHTML = `
        <div data-grade-work-student-list data-selected-student-id="1">
            <div data-grade-work-student data-grade-work-student-id="7">
                <button type="button" data-person-avatar="7"><span>AB</span></button>
                <span data-name>Ann Bee</span>
            </div>
        </div>`;
    const loadStudent = vi.fn();
    initializeGradeWork(1, 2, vi.fn(), loadStudent);

    return loadStudent;
}

describe("Grade Work student list", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });

    it("loads the student when the row is clicked", () => {
        const loadStudent = setup();
        document.querySelector("[data-name]").click();
        expect(loadStudent).toHaveBeenCalledWith("7", null);
    });

    it("ignores clicks from inside the avatar", () => {
        const loadStudent = setup();
        document.querySelector("[data-person-avatar] span").click();
        expect(loadStudent).not.toHaveBeenCalled();
    });
});
