import { describe, expect, it } from "vitest";

import { overrideDialogState } from "../../../resources/js/pages/courses/features/grades/roster/override-dialog.js";

const ROUTE = "It goes to the chairperson (Maria Chair) to endorse, then to the dean to decide.";

describe("override dialog state", () => {
    it("is the plain override where the institution has no deans", () => {
        const state = overrideDialogState("direct", "", { isOverride: "1", currentPercentage: "92", overrideReason: "Appeal" });

        expect(state).toMatchObject({
            mode: "direct",
            title: "Override Final Grade",
            submitLabel: "Save",
            percentage: "92",
            reason: "Appeal",
            requestId: null,
            readOnly: false,
            canWithdraw: false,
        });
    });

    it("starts empty in the plain override when no override is set", () => {
        const state = overrideDialogState("direct", "", { isOverride: "0", currentPercentage: "88" });

        expect(state.percentage).toBe("");
        expect(state.reason).toBe("");
    });

    it("asks for a request, and says it changes nothing and who it goes to", () => {
        const state = overrideDialogState("request", ROUTE, { isOverride: "0", currentPercentage: "88" });

        expect(state.title).toBe("Request an override");
        expect(state.submitLabel).toBe("Send request");
        expect(state.note).toContain("does not change the grade");
        expect(state.note).toContain("Maria Chair");
        expect(state.requestId).toBeNull();
        expect(state.canWithdraw).toBe(false);
        expect(state.placeholder).toContain("removed");
        expect(state.percentage).toBe("");
    });

    it("shows a request that was sent back with the comment, ready to edit and send again", () => {
        const state = overrideDialogState("request", ROUTE, {
            requestId: "7", requestStatus: "returned", requestPercentage: "92", requestReason: "Late quiz",
            requestComment: "Attach the medical note.",
        });

        expect(state.title).toBe("Edit your request");
        expect(state.submitLabel).toBe("Send again");
        expect(state.statusText).toBe("Sent back: “Attach the medical note.”");
        expect(state.requestId).toBe("7");
        expect(state.readOnly).toBe(false);
        expect(state.canWithdraw).toBe(true);
        expect([state.percentage, state.reason]).toEqual(["92", "Late quiz"]);
    });

    it("copes with a sent-back request that has no comment", () => {
        const state = overrideDialogState("request", ROUTE, { requestId: "7", requestStatus: "returned", requestPercentage: "92" });

        expect(state.statusText).toBe("Sent back.");
    });

    it("shows a waiting request as read-only, with only the way to withdraw it", () => {
        const state = overrideDialogState("request", ROUTE, {
            requestId: "7", requestStatus: "awaiting_chair", requestPercentage: "92", requestReason: "Late quiz",
            requestWaiting: "the chairperson",
        });

        expect(state.title).toBe("Your request");
        expect(state.readOnly).toBe(true);
        expect(state.submitLabel).toBe("");
        expect(state.canWithdraw).toBe(true);
        expect(state.statusText).toBe("Asked for 92%: waiting for the chairperson.");
    });

    it("words a request to remove an override, and one with nobody named yet", () => {
        const state = overrideDialogState("request", ROUTE, { requestId: "9", requestStatus: "awaiting_dean", requestPercentage: "" });

        expect(state.statusText).toBe("Asked for removing the override: waiting for the next person.");
        expect(state.percentage).toBe("");
    });
});
