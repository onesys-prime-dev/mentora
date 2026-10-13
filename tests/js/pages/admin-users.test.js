import { beforeEach, describe, expect, it } from "vitest";
import { initUserSelection } from "../../../resources/js/pages/admin-users/admin-users.js";

function page(ticked = []) {
    document.body.innerHTML = `
        <form data-users-bulk>
            <input type="checkbox" data-select-all>
            <span data-selected-count></span>
            ${[1, 2, 3]
                .map((id) => `<input type="checkbox" data-user-select value="${id}" ${ticked.includes(id) ? "checked" : ""}>`)
                .join("")}
            <button type="submit" data-bulk-action name="action" value="deactivate">Deactivate</button>
            <button type="submit" data-bulk-action name="action" value="export">Export</button>
        </form>`;

    return {
        all: document.querySelector("[data-select-all]"),
        boxes: Array.from(document.querySelectorAll("[data-user-select]")),
        label: document.querySelector("[data-selected-count]"),
        buttons: Array.from(document.querySelectorAll("[data-bulk-action]")),
    };
}

function tick(box, checked = true) {
    box.checked = checked;
    box.dispatchEvent(new Event("change", { bubbles: true }));
}

beforeEach(() => {
    document.body.innerHTML = "";
});

describe("initUserSelection", () => {
    it("does nothing on a page without the bulk form", () => {
        expect(initUserSelection(document)).toBeNull();
    });

    it("starts with the bulk buttons off and no one counted", () => {
        const { all, label, buttons } = page();

        initUserSelection(document);

        expect(label.textContent).toBe("No one selected");
        expect(buttons.every((button) => button.disabled)).toBe(true);
        expect(all.checked).toBe(false);
        expect(all.indeterminate).toBe(false);
    });

    it("turns the buttons on and counts when someone is ticked", () => {
        const { boxes, label, buttons, all } = page();

        initUserSelection(document);
        tick(boxes[1]);

        expect(label.textContent).toBe("1 person selected");
        expect(buttons.every((button) => !button.disabled)).toBe(true);
        expect(all.indeterminate).toBe(true);
        expect(all.checked).toBe(false);
    });

    it("selects everyone with the select-all box and clears them again", () => {
        const { all, boxes, label } = page();

        initUserSelection(document);
        tick(all);

        expect(boxes.every((box) => box.checked)).toBe(true);
        expect(label.textContent).toBe("3 people selected");
        expect(all.indeterminate).toBe(false);

        tick(all, false);

        expect(boxes.every((box) => !box.checked)).toBe(true);
        expect(label.textContent).toBe("No one selected");
    });

    it("unticks select-all when one person is unticked", () => {
        const { all, boxes } = page();

        initUserSelection(document);
        tick(all);
        tick(boxes[0], false);

        expect(all.checked).toBe(false);
        expect(all.indeterminate).toBe(true);
    });

    it("picks up boxes that are already ticked when the page loads", () => {
        const { label, buttons } = page([1, 3]);

        initUserSelection(document);

        expect(label.textContent).toBe("2 people selected");
        expect(buttons.every((button) => !button.disabled)).toBe(true);
    });
});
