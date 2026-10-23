import { beforeEach, describe, expect, it, vi } from "vitest";

describe("select-arrow", () => {
    let select;

    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML = `<select data-select-arrow><option>a</option><option>b</option></select><select><option>x</option></select>`;
        select = document.querySelector("[data-select-arrow]");
        await import("../../../resources/js/shared/select-arrow.js");
    });

    const press = (el) => el.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));

    it("opens on a click and closes on the next one", () => {
        press(select);
        expect(select.dataset.open).toBe("true");

        press(select);
        expect(select.dataset.open).toBe("false");
    });

    it("closes when an option is chosen, on leaving and on Escape", () => {
        press(select);
        select.dispatchEvent(new Event("change", { bubbles: true }));
        expect(select.dataset.open).toBe("false");

        press(select);
        select.dispatchEvent(new FocusEvent("focusout", { bubbles: true }));
        expect(select.dataset.open).toBe("false");

        press(select);
        select.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
        expect(select.dataset.open).toBe("false");
    });

    it("leaves other selects alone", () => {
        const other = document.querySelectorAll("select")[1];
        press(other);
        expect(other.dataset.open).toBeUndefined();
    });
});
