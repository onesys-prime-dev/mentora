import { describe, expect, it } from "vitest";

import { strength } from "../../../../resources/js/pages/settings/strength.js";

describe("the password strength hint", () => {

    it("says nothing for an empty box and too short below the minimum", () => {
        expect(strength("", 10)).toEqual({ score: 0, word: "" });
        expect(strength("Ab1!", 10)).toEqual({ score: 0, word: "Too short." });
    });

    it("rises with length, mixed characters and symbols", () => {
        expect(strength("abcdefghij", 10).score).toBe(1);
        expect(strength("abcdefghijklmn", 10).score).toBe(2);
        expect(strength("Abcdefghijklm9", 10).score).toBe(3);
        expect(strength("Tr4ins-Lantern-Moss-92", 10)).toEqual({ score: 4, word: "Strong." });
    });
});
