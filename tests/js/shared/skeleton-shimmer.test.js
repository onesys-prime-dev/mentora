import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const css = readFileSync(resolve(__dirname, "../../../resources/css/app.css"), "utf8");

describe("the skeleton shimmer", () => {

    it("sweeps a light across every placeholder block of a skeleton", () => {
        expect(css).toContain("@keyframes skeleton-shimmer");
        expect(css).toContain('.animate-pulse [class~="bg-gray-200"]');
        expect(css).toContain('[data-skeleton] [class~="bg-gray-200"]');
        expect(css).toMatch(/animation:\s*skeleton-shimmer\s+[\d.]+s/);
    });

    it("never leaves a skeleton static: reduced motion swaps the sweep for a slow breathing", () => {
        const reduced = css.slice(css.indexOf("@media (prefers-reduced-motion: reduce)"));

        expect(css).toContain("@keyframes skeleton-breathe");
        expect(reduced).toContain('.animate-pulse [class~="bg-gray-200"]');
        expect(reduced).toMatch(/background-image:\s*none;\s*animation:\s*skeleton-breathe/);
    });

    it("uses a band that shows on the darker blocks and on the lighter panels", () => {
        expect(css).toMatch(/\.animate-pulse \[class~="bg-gray-100"\]\s*\{\s*background-image:\s*linear-gradient\(90deg, transparent 0%, rgb\(209 213 219/);
        expect(css).toMatch(/rgb\(255 255 255\) 50%/);
    });

    it("has a quieter light on the dark appearance, and none when motion is reduced", () => {
        expect(css).toMatch(/prefers-reduced-motion: no-preference\)\s*\{\s*html\[data-theme="dark"\]/);
    });

});
