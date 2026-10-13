import { afterEach, describe, expect, it } from "vitest";
import { applyBrandTokens, BRAND_PROPERTIES, clearBrandTokens, DEFAULT_BRAND, readBrandTokens } from "../../../resources/js/shared/brand-tokens.js";

describe("brand tokens", () => {
    afterEach(() => {
        document.body.innerHTML = "";
        clearBrandTokens(document.documentElement);
    });

    it("falls back to the Mentora brand when nothing is set", () => {
        expect(readBrandTokens()).toEqual(DEFAULT_BRAND);
    });

    it("reads what an element has set", () => {
        const box = document.createElement("div");
        box.style.setProperty(BRAND_PROPERTIES.brand, " #112233 ");
        document.body.append(box);

        expect(readBrandTokens(box).brand).toBe("#112233");
        expect(readBrandTokens(box).brandDark).toBe(DEFAULT_BRAND.brandDark);
    });

    it("sets valid hex colours and says which it applied", () => {
        const box = document.createElement("div");

        const applied = applyBrandTokens(box, { brand: "#AA00BB", brandTint: "#F0F0FF" });

        expect(applied).toEqual(["brand", "brandTint"]);
        expect(box.style.getPropertyValue("--color-brand")).toBe("#AA00BB");
        expect(box.style.getPropertyValue("--color-brand-tint")).toBe("#F0F0FF");
    });

    it("refuses anything that is not a six-digit hex or not a token", () => {
        const box = document.createElement("div");

        const applied = applyBrandTokens(box, {
            brand: "red",
            brandDark: "#12345",
            brandTint: "#123456; background: url(x)",
            sidebar: "#123456",
        });

        expect(applied).toEqual([]);
        expect(box.getAttribute("style") ?? "").toBe("");
    });

    it("clears its overrides", () => {
        const box = document.createElement("div");
        applyBrandTokens(box, { brand: "#AA00BB", brandDark: "#110022" });

        clearBrandTokens(box);

        expect(box.style.getPropertyValue("--color-brand")).toBe("");
        expect(box.style.getPropertyValue("--color-brand-dark")).toBe("");
    });
});
