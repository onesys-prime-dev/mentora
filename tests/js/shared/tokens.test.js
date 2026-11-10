// Guards the responsive tokens in app.css: later layout work relies on these exact values.
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const css = readFileSync(resolve(__dirname, "../../../resources/css/app.css"), "utf8");

describe("responsive tokens", () => {
    it.each([
        ["--breakpoint-xs", "25rem"], ["--breakpoint-3xl", "120rem"],
        ["--text-fluid-h1", "clamp(1.5rem, 1.357rem + 0.714vw, 2rem)"],
        ["--text-fluid-h2", "clamp(1.25rem, 1.179rem + 0.357vw, 1.5rem)"],
        ["--text-fluid-metric", "clamp(1.75rem, 1.536rem + 1.071vw, 2.5rem)"],
        ["--spacing-gutter", "clamp(1rem, 4vw, 2.5rem)"],
        ["--spacing-fluid", "clamp(0.75rem, 0.536rem + 1.071vw, 1.5rem)"],
        ["--ease-spring", "cubic-bezier(.34,1.45,.64,1)"], ["--ease-exit", "cubic-bezier(.4,0,1,1)"],
        ["--motion-fast", "180ms"], ["--motion-base", "380ms"], ["--motion-slow", "480ms"],
        ["--max-width-reading", "70ch"], ["--max-width-shell", "105rem"],
    ])("declares %s: %s", (name, value) => expect(css).toContain(`${name}: ${value};`));

    it("zeroes motion for reduced-motion users", () =>
        expect(css).toMatch(/prefers-reduced-motion: reduce\)[\s\S]*--motion-base: 0ms/));

    it("declares the lock variant", () =>
        expect(css).toContain("@custom-variant lock (@media (min-width: 80rem) and (min-height: 47.5rem));"));

    it("keeps backdrop-filter off the header itself (it would trap fixed descendants)", () => {
        expect(css).toMatch(/\[data-app-header\]\[data-scrolled\]::before \{[^}]*backdrop-filter/);
        expect(css).not.toMatch(/\[data-app-header\]\[data-scrolled\] \{[^}]*backdrop-filter/);
    });
});
