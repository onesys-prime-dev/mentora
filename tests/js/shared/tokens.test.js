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

    it("drops the data table's own card on phones, over the admin and panel outlines", () =>
        expect(css).toMatch(/@media \(width < 48rem\) \{\s*html body \[data-table\]\[data-phone-rows\]\.bg-white\.shadow-sm \{[^}]*min-height: 0;[^}]*border: 0;[^}]*box-shadow: none;/));

    it("makes every control in the row-actions sheet finger-sized under a coarse pointer", () =>
        expect(css).toMatch(/@media \(pointer: coarse\) \{\s*:is\(\[data-row-actions-slot\], \[data-row-actions-fields\]\) :is\(a, button, summary, \[role="button"\], input[^)]*\), select\) \{[^}]*min-height: 2\.75rem;[^}]*min-width: 2\.75rem;[^}]*display: inline-flex;[^}]*align-items: center;/));

    it("pins a full-screen form's own submit row as an opaque bottom bar on phones", () => {
        const phone = css.match(/\/\* Full-screen form dialogs on a phone[\s\S]*?@media \(width < 40rem\) \{([\s\S]*?)\n\}/)?.[1] ?? "";
        expect(phone).toMatch(/\[data-dialog-pin-actions\] > form > :has\(> button\[type="submit"\]\) \{[^}]*position: sticky;[^}]*bottom: 0;[^}]*background-color: #fff;[^}]*border-top: 1px solid[^}]*padding-bottom: max\(1rem, env\(safe-area-inset-bottom\)\);/);
        expect(phone).toMatch(/\[data-dialog-pin-actions\] > form > button\[type="submit"\] \{[^}]*position: sticky;/);
        expect(css).toMatch(/html\[data-theme="dark"\] \[data-dialog-pin-actions\] > form > :has\(> button\[type="submit"\]\) \{[^}]*var\(--dk-surface\)/);
    });

    it("hides Cancel inside a full-screen dialog's body on phones (the top bar has it)", () =>
        expect(css).toMatch(/@media \(width < 40rem\) \{[\s\S]*?\[data-dialog-variant="fullscreen"\] \[data-dialog-body\] \[data-dialog-close\] \{\s*display: none;/));

    it("keeps backdrop-filter off the header itself (it would trap fixed descendants)", () => {
        expect(css).toMatch(/\[data-app-header\]\[data-scrolled\]::before \{[^}]*backdrop-filter/);
        expect(css).not.toMatch(/\[data-app-header\]\[data-scrolled\] \{[^}]*backdrop-filter/);
    });

    it("defines the .form-grid container-query component (one column below 28rem, two from there)", () => {
        expect(css).toMatch(/\.form-grid \{[^}]*display: grid;[^}]*grid-template-columns: minmax\(0, 1fr\);/);
        expect(css).toMatch(/@container \(width >= 28rem\) \{\s*\.form-grid \{[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/);
    });
});
