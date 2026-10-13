import { beforeEach, describe, expect, it } from "vitest";
import { applyTheme, bindThemeToggle, currentTheme, STORAGE_KEY, toggleTheme } from "../../../resources/js/shared/theme.js";

function memoryStorage(initial = {}) {
    const data = { ...initial };

    return {
        getItem: (key) => data[key] ?? null,
        setItem: (key, value) => {
            data[key] = String(value);
        },
        data,
    };
}

beforeEach(() => {
    document.documentElement.removeAttribute("data-theme");
    document.body.innerHTML = '<button type="button" data-theme-toggle aria-pressed="false">Dark</button>';
});

describe("the appearance", () => {
    it("is light until someone chooses dark", () => {
        expect(currentTheme(document)).toBe("light");
    });

    it("applies the choice to the page and remembers it", () => {
        const storage = memoryStorage();

        expect(applyTheme("dark", document, storage)).toBe("dark");
        expect(document.documentElement.dataset.theme).toBe("dark");
        expect(storage.data[STORAGE_KEY]).toBe("dark");
    });

    it("treats anything but dark as light", () => {
        applyTheme("purple", document, memoryStorage());

        expect(currentTheme(document)).toBe("light");
    });

    it("still applies when the browser will not store it", () => {
        const blocked = {
            setItem: () => {
                throw new Error("blocked");
            },
        };

        expect(applyTheme("dark", document, blocked)).toBe("dark");
        expect(currentTheme(document)).toBe("dark");
    });

    it("says its state in the button's words and aria-pressed", () => {
        const button = document.querySelector("[data-theme-toggle]");

        applyTheme("dark", document, null);

        expect(button.getAttribute("aria-pressed")).toBe("true");
        expect(button.getAttribute("aria-label")).toContain("Switch to light mode");

        applyTheme("light", document, null);

        expect(button.getAttribute("aria-pressed")).toBe("false");
        expect(button.getAttribute("aria-label")).toContain("Switch to dark mode");
    });

    it("toggles with the button", () => {
        expect(bindThemeToggle(document)).toBe(1);

        document.querySelector("[data-theme-toggle]").click();
        expect(currentTheme(document)).toBe("dark");

        document.querySelector("[data-theme-toggle]").click();
        expect(currentTheme(document)).toBe("light");
        expect(toggleTheme(document)).toBe("dark");
    });

    it("does nothing on a page with no toggle", () => {
        document.body.innerHTML = "";

        expect(bindThemeToggle(document)).toBe(0);
    });
});
