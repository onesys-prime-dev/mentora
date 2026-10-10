import { describe, expect, it } from "vitest";
import { createSelection, selectionLabel } from "../../../resources/js/shared/selection.js";

describe("createSelection", () => {
    it("starts empty", () => {
        const selection = createSelection();

        expect(selection.count()).toBe(0);
        expect(selection.ids()).toEqual([]);
        expect(selection.has(1)).toBe(false);
    });

    it("can start with some rows ticked, and treats numbers and text alike", () => {
        const selection = createSelection([1, "2"]);

        expect(selection.has("1")).toBe(true);
        expect(selection.has(2)).toBe(true);
        expect(selection.count()).toBe(2);
    });

    it("ticks and unticks a row", () => {
        const selection = createSelection();

        selection.set(5, true);
        expect(selection.has(5)).toBe(true);

        selection.set(5, false);
        expect(selection.has(5)).toBe(false);
    });

    it("does not count a row twice", () => {
        const selection = createSelection();

        selection.set(5, true);
        selection.set("5", true);

        expect(selection.count()).toBe(1);
    });

    it("toggles a row and says what it became", () => {
        const selection = createSelection();

        expect(selection.toggle(7)).toBe(true);
        expect(selection.toggle(7)).toBe(false);
        expect(selection.count()).toBe(0);
    });

    it("keeps the order rows were ticked in", () => {
        const selection = createSelection();

        selection.set(3, true);
        selection.set(1, true);
        selection.set(2, true);

        expect(selection.ids()).toEqual(["3", "1", "2"]);
    });

    it("ticks every row of the page and leaves other ticks alone", () => {
        const selection = createSelection([99]);

        selection.selectAll([1, 2, 3]);

        expect(selection.ids().sort()).toEqual(["1", "2", "3", "99"]);
    });

    it("unticks only the rows given, or everything when none are", () => {
        const selection = createSelection([1, 2, 3, 99]);

        selection.clear([1, 2]);
        expect(selection.ids().sort()).toEqual(["3", "99"]);

        selection.clear();
        expect(selection.count()).toBe(0);
    });
});

describe("stateOf", () => {
    it("is none with nothing ticked", () => {
        expect(createSelection().stateOf([1, 2, 3])).toBe("none");
    });

    it("is some with part of the page ticked", () => {
        expect(createSelection([2]).stateOf([1, 2, 3])).toBe("some");
    });

    it("is all with the whole page ticked", () => {
        expect(createSelection([1, 2, 3]).stateOf([1, 2, 3])).toBe("all");
    });

    it("ignores ticks that are not on this page", () => {
        expect(createSelection([99]).stateOf([1, 2, 3])).toBe("none");
        expect(createSelection([1, 99]).stateOf([1, 2])).toBe("some");
    });

    it("is none for an empty page, never all", () => {
        expect(createSelection([1]).stateOf([])).toBe("none");
    });
});

describe("selectionLabel", () => {
    it("says when no one is selected", () => {
        expect(selectionLabel(0)).toBe("No one selected");
    });

    it("uses the singular for one", () => {
        expect(selectionLabel(1)).toBe("1 person selected");
    });

    it("uses the plural for more", () => {
        expect(selectionLabel(12)).toBe("12 people selected");
    });

    it("takes other nouns", () => {
        expect(selectionLabel(0, { one: "row", many: "rows" })).toBe("No rows selected");
        expect(selectionLabel(2, { one: "row", many: "rows" })).toBe("2 rows selected");
    });
});
