import { describe, expect, it } from "vitest";
import { check, contrast, dark, luminance, MIN_RATIO, normalise, tint } from "../../../resources/js/shared/brand-colours.js";

describe("brand colour rules", () => {
    it("normalises hex however it is written", () => {
        expect(normalise("#0040a6")).toBe("#0040A6");
        expect(normalise("0040a6")).toBe("#0040A6");
        expect(normalise(" #abc ")).toBe("#AABBCC");
        expect(normalise("blue")).toBeNull();
        expect(normalise("#12345")).toBeNull();
        expect(normalise(null)).toBeNull();
    });

    it("computes the WCAG contrast", () => {
        expect(contrast("#000000", "#FFFFFF")).toBeCloseTo(21, 5);
        expect(contrast("#FFFFFF", "#FFFFFF")).toBeCloseTo(1, 5);
        expect(luminance("#FFFFFF")).toBeCloseTo(1, 5);
        expect(contrast("#0040A6", "#FFFFFF")).toBeGreaterThan(8);
    });

    it("derives the hover colour and the tint from the primary", () => {
        expect(dark("#0040A6")).toBe("#002867");
        expect(tint("#0040A6")).toBe("#F2F5FB");
        expect(dark("#000000")).toBe("#000000");
        expect(tint("#FFFFFF")).toBe("#FFFFFF");
    });

    it("accepts a readable primary and says so", () => {
        const result = check("#0040A6", "primary");

        expect(result.ok).toBe(true);
        expect(result.hex).toBe("#0040A6");
        expect(result.message).toMatch(/passes AA/);
    });

    it("refuses a primary that is too light, with the ratio", () => {
        const result = check("#7FB2FF", "primary");

        expect(result.ok).toBe(false);
        expect(result.ratio).toBeLessThan(MIN_RATIO);
        expect(result.message).toMatch(/Too light: contrast \d+\.\d:1/);
    });

    it("judges the accent against white", () => {
        expect(check("#2E7D4F", "accent").ok).toBe(true);
        expect(check("#FFD700", "accent").ok).toBe(false);
    });

    it("refuses something that is not a colour", () => {
        const result = check("not a colour", "primary");

        expect(result.ok).toBe(false);
        expect(result.hex).toBeNull();
        expect(result.message).toMatch(/six hex digits/);
    });
});
