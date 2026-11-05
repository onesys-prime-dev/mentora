import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { adjustBellCount, setBellCount } from "../../../resources/js/shared/notifications-badge.js";

beforeEach(() => {
    document.body.innerHTML = `<span data-notifications-badge data-count="2">2</span>`;
});

afterEach(() => {
    document.body.innerHTML = "";
});

const badge = () => document.querySelector("[data-notifications-badge]");

describe("the bell badge", () => {
    it("shows the count, says 99+ past 99, and hides at zero", () => {
        setBellCount(5);
        expect(badge().textContent).toBe("5");
        expect(badge().hidden).toBe(false);

        setBellCount(120);
        expect(badge().textContent).toBe("99+");

        setBellCount(0);
        expect(badge().hidden).toBe(true);
    });

    it("moves by a step and never goes below zero", () => {
        adjustBellCount(-1);
        expect(badge().textContent).toBe("1");

        adjustBellCount(-1);
        adjustBellCount(-1);
        expect(badge().hidden).toBe(true);
        expect(badge().dataset.count).toBe("0");

        adjustBellCount(1);
        expect(badge().textContent).toBe("1");
    });

    it("does nothing on a page without the badge", () => {
        document.body.innerHTML = "";

        expect(() => { setBellCount(3); adjustBellCount(1); }).not.toThrow();
    });
});
