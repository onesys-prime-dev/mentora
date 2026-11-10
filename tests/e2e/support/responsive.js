// The responsive sweep's checks (tests/e2e/responsive.spec.js): the window sizes, and the four things every
// screen must hold at each of them: no sideways page scroll, touch targets of at least 44×44 that fit the
// window, no serious or critical axe findings, and (on Linux only, where the CI baselines are made) a
// full-page screenshot that matches its baseline.
//
// Markup contract: a horizontal scroller that is allowed to be wider than the window (a grid table) carries
// `data-scroll-x`; anything inside it is left out of the overflow check.
import AxeBuilder from "@axe-core/playwright";
import { expect } from "@playwright/test";

const size = (w, h) => ({ w, h, name: `${w}x${h}` });

/** The sizes a normal run checks: a phone, a tablet, a laptop. */
export const SMOKE = [size(375, 812), size(834, 1112), size(1366, 768)];

/** The nightly matrix, smallest phone to a large desktop, plus a phone held sideways. */
export const FULL = [
    size(320, 640),
    size(375, 812),
    size(430, 932),
    size(768, 1024),
    size(834, 1112),
    size(1024, 768),
    size(1366, 768),
    size(1440, 900),
    size(1920, 1080),
];
export const LANDSCAPE = size(667, 375);

/** RESPONSIVE_FULL=1 (the nightly workflow) gives the whole matrix; otherwise the smoke sizes. */
export function sizes() {
    return process.env.RESPONSIVE_FULL === "1" ? [...FULL, LANDSCAPE] : SMOKE;
}

/**
 * The page is no wider than the window, apart from content inside a `[data-scroll-x]` scroller. With `scope`
 * (a selector), only that element is measured: it has no sideways scroll of its own, whatever the page does.
 */
export async function expectNoPageOverflow(page, scope) {
    if (scope) {
        const box = await page.locator(scope).first().evaluate((el) => ({ scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }));
        expect(box.scrollWidth, `${scope} is ${box.scrollWidth}px wide inside ${box.clientWidth}px`).toBeLessThanOrEqual(box.clientWidth);

        return;
    }

    const { scrollWidth, width, offenders } = await page.evaluate(() => {
        const html = globalThis.document.documentElement;
        // Clip the allowed scrollers for the measurement, so only what spills outside them counts.
        const scrollers = [...html.querySelectorAll("[data-scroll-x]")];
        const saved = scrollers.map((el) => el.style.overflowX);
        scrollers.forEach((el) => (el.style.overflowX = "hidden"));
        // clientWidth, not innerWidth: a mobile browser widens innerWidth to fit content that spills out,
        // and it is in the same units as scrollWidth when the page is zoomed.
        const result = {
            scrollWidth: html.scrollWidth,
            width: html.clientWidth,
            offenders: [...globalThis.document.body.querySelectorAll("*")]
                .filter((el) => !el.closest("[data-scroll-x]") && el.getBoundingClientRect().right > html.clientWidth + 1)
                .slice(0, 5)
                .map((el) => el.outerHTML.slice(0, 160)),
        };
        scrollers.forEach((el, i) => (el.style.overflowX = saved[i]));

        return result;
    });

    expect(scrollWidth, `page is ${scrollWidth}px wide in a ${width}px window; sticking out: ${offenders.join("\n")}`).toBeLessThanOrEqual(width);
}

/**
 * The open dialog's panel has no sideways scroll. The dialog is position: fixed, so it never widens the page
 * and expectNoPageOverflow cannot see it spill; this checks the panel itself.
 */
export async function expectNoPanelOverflow(dialog) {
    const { scrollWidth, clientWidth } = await dialog.locator("[data-dialog-panel]").evaluate((el) => ({ scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }));

    expect(scrollWidth, `dialog panel is ${scrollWidth}px wide inside ${clientWidth}px`).toBeLessThanOrEqual(clientWidth);
}

/**
 * Every visible control in `scope` that is not an inline text link is at least 44×44 and sits inside the
 * window's width (a control inside a `[data-scroll-x]` scroller only needs the size). A checkbox or radio
 * wrapped in a <label> is measured by its label (the label is what a finger presses).
 */
export async function expectTouchTargets(page, scope = "body") {
    const coarse = await page.evaluate(() => globalThis.matchMedia("(pointer: coarse)").matches);
    expect(coarse, "touch emulation did not make pointer: coarse match; the 44px check would be meaningless").toBe(true);

    const offenders = await page.evaluate((root) => {
        const controls = "a[href], button, [role=button], input:not([type=hidden]), select, textarea, summary";
        const width = globalThis.document.documentElement.clientWidth;

        return [...globalThis.document.querySelectorAll(root)]
            .flatMap((el) => [...el.querySelectorAll(controls)])
            .filter((el) => el.checkVisibility() && globalThis.getComputedStyle(el).display !== "inline")
            .flatMap((el) => {
                const label = /^(checkbox|radio)$/.test(el.type) ? el.closest("label") : null;
                const r = (label ?? el).getBoundingClientRect();
                // A control scrolled out of sight inside a [data-scroll-x] scroller may sit outside the window.
                const inWindow = el.closest("[data-scroll-x]") || (r.left >= 0 && r.right <= width);
                const ok = r.width >= 44 && r.height >= 44 && inWindow;

                return ok ? [] : [`${Math.round(r.width)}×${Math.round(r.height)} at x=${Math.round(r.left)}: ${el.outerHTML.slice(0, 160)}`];
            });
    }, scope);

    expect(offenders, "controls smaller than 44×44 or outside the window").toEqual([]);
}

/** The (first) element matching `selector` is visible and lies wholly inside the window. */
export async function expectInViewport(page, selector) {
    const el = page.locator(selector).first();
    await expect(el, selector).toBeVisible();

    const { r, w, h } = await el.evaluate((node) => ({
        r: node.getBoundingClientRect().toJSON(),
        w: globalThis.document.documentElement.clientWidth,
        h: globalThis.innerHeight,
    }));
    const inside = r.left >= 0 && r.top >= 0 && r.right <= w + 1 && r.bottom <= h + 1;

    expect(inside, `${selector} at ${Math.round(r.left)},${Math.round(r.top)} to ${Math.round(r.right)},${Math.round(r.bottom)} in a ${w}×${h} window`).toBe(true);
}

/** No serious or critical axe findings against WCAG 2.2 A and AA (the filter dean.spec.js uses). */
export async function expectAccessible(page, scope) {
    let axe = new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"]);
    if (scope) {
        axe = axe.include(scope);
    }

    const results = await axe.analyze();
    const serious = results.violations
        .filter((v) => v.impact === "serious" || v.impact === "critical")
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length}, first: ${v.nodes[0].target.join(" ")})`);

    expect(serious).toEqual([]);
}

/** Starts every page of this context in the given theme (shared/theme.js reads the same key). */
export async function setTheme(page, theme) {
    await page.addInitScript((value) => globalThis.localStorage.setItem("mentora-theme", value), theme);
}

/**
 * Browser zoom at 200% on a 640×800 window: a 320×400 CSS px viewport, the WCAG 1.4.10 reflow case. It sets
 * that viewport rather than `html { zoom: 2 }`, because CSS zoom leaves media queries at 640px, so the page
 * would keep its `sm:` layout in 320px of room, which real browser zoom never shows.
 */
export async function zoom200(page) {
    await page.setViewportSize({ width: 320, height: 400 });
}

/** Compares a full-page screenshot with its baseline, on Linux only (the baselines are made on CI's runner). */
export async function snap(page, name) {
    if (process.platform !== "linux") {
        return;
    }

    await expect(page).toHaveScreenshot(`${name}.png`, { fullPage: true, animations: "disabled", maxDiffPixelRatio: 0.01 });
}
