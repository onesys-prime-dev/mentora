import { beforeEach, describe, expect, it } from "vitest";

import { movedIds, uniqueCategoryName } from "../../../resources/js/pages/courses/features/grades/scheme/scheme.js";

const editorWith = (names) => {

    const editor = document.createElement("div");

    editor.innerHTML = names.map((name) => `<input data-grade-category-name value="${name}">`).join("");

    return editor;

};

describe("the name a new category starts with", () => {

    it("is plain when it is free", () => {
        expect(uniqueCategoryName(editorWith(["Quizzes", "Exams"]))).toBe("New category");
    });

    it("counts up when it is taken, whatever the case", () => {

        expect(uniqueCategoryName(editorWith(["New category"]))).toBe("New category 2");
        expect(uniqueCategoryName(editorWith(["new CATEGORY", "New category 2"]))).toBe("New category 3");

    });

});

describe("moving a row up or down", () => {

    let list;

    beforeEach(() => {

        list = document.createElement("div");

        list.innerHTML = [1, 2, 3].map((id) => `<div data-grade-category="${id}"></div>`).join("")
            + `<section><div data-grade-category="9"></div></section>`;

    });

    const row = (id) => list.querySelector(`[data-grade-category="${id}"]`);

    it("swaps with its neighbour", () => {

        expect(movedIds(list, "[data-grade-category]", "gradeCategory", row(2), "up")).toEqual(["2", "1", "3"]);
        expect(movedIds(list, "[data-grade-category]", "gradeCategory", row(2), "down")).toEqual(["1", "3", "2"]);

    });

    it("stays put at either end", () => {

        expect(movedIds(list, "[data-grade-category]", "gradeCategory", row(1), "up")).toEqual(["1", "2", "3"]);
        expect(movedIds(list, "[data-grade-category]", "gradeCategory", row(3), "down")).toEqual(["1", "2", "3"]);

    });

    it("only considers the rows beside it, not ones in another group", () => {

        expect(movedIds(list, "[data-grade-category]", "gradeCategory", row(9), "up")).toEqual(["9"]);

    });

});
