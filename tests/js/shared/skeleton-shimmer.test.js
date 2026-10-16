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

    it("travels from the left edge to the right edge, faster than a second and a half", () => {
        expect(css).toMatch(/@keyframes skeleton-shimmer\s*\{\s*from\s*\{\s*background-position:\s*105% 0;\s*\}\s*to\s*\{\s*background-position:\s*-5% 0;/);
        expect(css).toMatch(/animation:\s*skeleton-shimmer\s+1.1s linear infinite/);
    });

    it("draws a solid white band on a deeper grey so it stands out", () => {
        expect(css).toContain("rgb(255 255 255) 50%");
        expect(css).toContain("background-color: #d5dae1");
    });

    it("does not also fade the whole region while the light moves", () => {
        expect(css).toMatch(/prefers-reduced-motion: no-preference\)\s*\{\s*\.animate-pulse\s*\{\s*animation:\s*none;/);
    });

    it("no longer has a dimming class for loading screens", () => {
        expect(css).not.toContain(".spa-loading");
    });

    it("has a quieter light on the dark appearance, and none when motion is reduced", () => {
        expect(css).toMatch(/prefers-reduced-motion: no-preference\)\s*\{\s*html\[data-theme="dark"\]/);
    });

});
