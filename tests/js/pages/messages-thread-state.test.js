import { describe, expect, it } from "vitest";
import { mergeById, stepIndex, toggleReaction } from "../../../resources/js/pages/messages/thread-state.js";

describe("toggleReaction", () => {
    it("adds a first reaction", () => {
        expect(toggleReaction([], "👍")).toEqual([{ emoji: "👍", count: 1, mine: true }]);
    });

    it("joins an existing chip", () => {
        expect(toggleReaction([{ emoji: "👍", count: 2, mine: false }], "👍")).toEqual([{ emoji: "👍", count: 3, mine: true }]);
    });

    it("removes my reaction when I pick the same emoji again", () => {
        expect(toggleReaction([{ emoji: "👍", count: 1, mine: true }], "👍")).toEqual([]);
        expect(toggleReaction([{ emoji: "👍", count: 2, mine: true }], "👍")).toEqual([{ emoji: "👍", count: 1, mine: false }]);
    });

    it("replaces my previous reaction with another emoji", () => {
        expect(toggleReaction([{ emoji: "👍", count: 1, mine: true }], "❤️")).toEqual([{ emoji: "❤️", count: 1, mine: true }]);
    });

    it("removes with null and does not mutate the input", () => {
        const before = [{ emoji: "👍", count: 1, mine: true }];

        expect(toggleReaction(before, null)).toEqual([]);
        expect(before).toEqual([{ emoji: "👍", count: 1, mine: true }]);
    });

    it("rolls back by toggling back to the saved list", () => {
        const saved = [{ emoji: "👍", count: 1, mine: false }];

        expect(toggleReaction(saved, "🔥")).not.toEqual(saved);
        expect(saved).toEqual([{ emoji: "👍", count: 1, mine: false }]);
    });
});

describe("mergeById", () => {
    it("replaces changed messages in place and keeps order", () => {
        const merged = mergeById([{ id: 1, body: "a" }, { id: 2, body: "b" }], [{ id: 2, body: "B" }]);

        expect(merged).toEqual([{ id: 1, body: "a" }, { id: 2, body: "B" }]);
    });

    it("adds new messages in id order and keeps unsent drafts last", () => {
        const draft = { id: "local-1", body: "x" };
        const merged = mergeById([{ id: 5 }, draft], [{ id: 3 }, { id: 9 }]);

        expect(merged.map((m) => m.id)).toEqual([3, 5, 9, "local-1"]);
    });
});

describe("stepIndex", () => {
    it("wraps both ways", () => {
        expect(stepIndex(0, 3, 1)).toBe(1);
        expect(stepIndex(2, 3, 1)).toBe(0);
        expect(stepIndex(0, 3, -1)).toBe(2);
        expect(stepIndex(0, 0, 1)).toBe(0);
    });
});
