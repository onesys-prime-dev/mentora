import { afterEach, describe, expect, it, vi } from "vitest";

import { bindSpecificStudents } from "../../../resources/js/pages/courses/features/assessments/inspector/specific-students.js";

/** A trimmed copy of the section of partials/editor-inspector-panel.php and of modals/assignees.php. */
const markup = (on, count) => `
    <div data-specific-students data-assignee-count="${count}">
        <input type="checkbox" data-inspector-field="assign_to_specific_students" data-specific-toggle ${on ? "checked" : ""}>
        <div data-specific-state class="hidden">
            <span data-specific-count>${count} students selected</span>
            <button type="button" data-specific-open aria-label="Choose students">open</button>
        </div>
    </div>
    <div id="assignees-modal" class="hidden" role="dialog">
        <button type="button" data-close-assignees-modal aria-label="Close">x</button>
        <input type="checkbox" data-assignees-all>
        <span data-assignees-summary></span>
        <p data-assignees-error></p>
        <ul data-assignees-list></ul>
        <button type="button" data-close-assignees-modal>Cancel</button>
        <button type="button" data-assignees-save>Save</button>
    </div>`;

const STUDENTS = [
    { id: 1, name: "Ada Lovelace", email: "ada@example.com", selected: true },
    { id: 2, name: "Bob Ray", email: "bob@example.com", selected: false },
    { id: 3, name: "Cy Young", email: "cy@example.com", selected: false },
];

const $ = (selector) => document.querySelector(selector);
const all = (selector) => [...document.querySelectorAll(selector)];
const isOpen = () => !$("#assignees-modal").classList.contains("hidden");
const click = (selector) => $(selector).dispatchEvent(new MouseEvent("click", { bubbles: true }));
const boxes = () => all("[data-assignee-id]");
const checkedIds = () => boxes().filter((b) => b.checked).map((b) => Number(b.dataset.assigneeId));
const setToggle = (on) => {
    $("[data-specific-toggle]").checked = on;
    $("[data-specific-toggle]").dispatchEvent(new Event("change", { bubbles: true }));
};
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

let fetchStudents;
let saveStudents;
let notify;

const bind = ({ on = false, count = 1, students = STUDENTS, save } = {}) => {
    document.body.innerHTML = markup(on, count);
    fetchStudents = vi.fn().mockResolvedValue(students);
    saveStudents = save ?? vi.fn().mockResolvedValue({ success: true, count: 2, message: "Students saved." });
    notify = vi.fn();

    return bindSpecificStudents({ fetchStudents, saveStudents, notify });
};

const openList = async () => {
    click("[data-specific-open]");
    await tick();
};

afterEach(() => {
    document.body.innerHTML = "";
    document.body.className = "";
});

describe("the switch", () => {
    it("shows the count and the button only while it is on", () => {
        bind();
        expect($("[data-specific-state]").classList.contains("hidden")).toBe(true);

        setToggle(true);
        expect($("[data-specific-state]").classList.contains("hidden")).toBe(false);

        setToggle(false);
        expect($("[data-specific-state]").classList.contains("hidden")).toBe(true);
    });

    it("starts showing them when it was saved on", () => {
        bind({ on: true });

        expect($("[data-specific-state]").classList.contains("hidden")).toBe(false);
    });
});

describe("the student list", () => {
    it("is fetched when it opens and shows each student with whoever is chosen ticked", async () => {
        bind({ on: true });
        await openList();

        expect(fetchStudents).toHaveBeenCalledTimes(1);
        expect(isOpen()).toBe(true);
        expect(boxes()).toHaveLength(3);
        expect(checkedIds()).toEqual([1]);
        expect($("[data-assignees-list]").textContent).toContain("Ada Lovelace");
        expect($("[data-assignees-list]").textContent).toContain("ada@example.com");
        expect($("[data-assignees-summary]").textContent).toBe("1 of 3 selected");
    });

    it("selects and unselects one student and keeps the summary right", async () => {
        bind({ on: true });
        await openList();

        boxes()[1].click();
        expect(checkedIds()).toEqual([1, 2]);
        expect($("[data-assignees-summary]").textContent).toBe("2 of 3 selected");

        boxes()[0].click();
        expect(checkedIds()).toEqual([2]);
    });

    it("Select all ticks everyone, and unticking it clears everyone", async () => {
        bind({ on: true });
        await openList();

        $("[data-assignees-all]").click();
        expect(checkedIds()).toEqual([1, 2, 3]);

        $("[data-assignees-all]").click();
        expect(checkedIds()).toEqual([]);
    });

    it("Select all follows the individual boxes", async () => {
        bind({ on: true });
        await openList();

        boxes()[1].click();
        boxes()[2].click();
        expect($("[data-assignees-all]").checked).toBe(true);

        boxes()[2].click();
        expect($("[data-assignees-all]").checked).toBe(false);
    });

    it("shows a section with no students as that, and does not offer Select all", async () => {
        bind({ on: true, students: [] });
        await openList();

        expect($("[data-assignees-list]").textContent).toMatch(/no students/i);
        expect($("[data-assignees-all]").disabled).toBe(true);
    });

    it("says so when the list cannot be loaded", async () => {
        bind({ on: true });
        fetchStudents.mockRejectedValue(new Error("offline"));
        await openList();

        expect($("[data-assignees-error]").textContent).toMatch(/could not be loaded/i);
    });

    it("escapes a name from the database", async () => {
        bind({ on: true, students: [{ id: 9, name: "<img src=x onerror=alert(1)>", email: "", selected: false }] });
        await openList();

        expect($("[data-assignees-list] img")).toBeNull();
    });
});

describe("saving the choice", () => {
    it("sends the ids ticked, then shows the new count and closes", async () => {
        bind({ on: true });
        await openList();
        boxes()[1].click();

        click("[data-assignees-save]");
        await tick();

        expect(saveStudents).toHaveBeenCalledWith([1, 2]);
        expect($("[data-specific-count]").textContent).toBe("2 students selected");
        expect($("[data-specific-students]").dataset.assigneeCount).toBe("2");
        expect(isOpen()).toBe(false);
        expect(notify).toHaveBeenCalledWith("Students saved.", "success");
    });

    it("says one student, not one students", async () => {
        bind({ on: true, save: vi.fn().mockResolvedValue({ success: true, count: 1 }) });
        await openList();

        click("[data-assignees-save]");
        await tick();

        expect($("[data-specific-count]").textContent).toBe("1 student selected");
    });

    it("keeps the modal open and shows the reason when the server refuses", async () => {
        bind({ on: true, save: vi.fn().mockResolvedValue({ success: false, message: "Only students enrolled in this section can be chosen." }) });
        await openList();

        click("[data-assignees-save]");
        await tick();

        expect(isOpen()).toBe(true);
        expect($("[data-assignees-error]").textContent).toContain("Only students enrolled");
        expect($("[data-specific-students]").dataset.assigneeCount).toBe("1");
    });

    it("keeps the modal open when the request fails", async () => {
        bind({ on: true, save: vi.fn().mockRejectedValue(new Error("offline")) });
        await openList();

        click("[data-assignees-save]");
        await tick();

        expect(isOpen()).toBe(true);
        expect($("[data-assignees-error]").textContent).toMatch(/could not be saved/i);
    });

    it("Cancel closes without saving", async () => {
        bind({ on: true });
        await openList();
        boxes()[1].click();

        all("[data-close-assignees-modal]")[1].click();

        expect(saveStudents).not.toHaveBeenCalled();
        expect(isOpen()).toBe(false);
    });

    it("opens fresh from the server each time (a cancelled tick is forgotten)", async () => {
        bind({ on: true });
        await openList();
        boxes()[1].click();
        $("[data-close-assignees-modal]").click();

        await openList();

        expect(fetchStudents).toHaveBeenCalledTimes(2);
        expect(checkedIds()).toEqual([1]);
    });
});

describe("problem()", () => {
    it("blocks saving the settings with the switch on and nobody chosen", () => {
        const { problem } = bind({ on: true, count: 0 });

        expect(problem()).toMatch(/choose at least one student/i);
    });

    it("is fine with somebody chosen, or with the switch off", () => {
        expect(bind({ on: true, count: 2 }).problem()).toBeNull();
        expect(bind({ on: false, count: 0 }).problem()).toBeNull();
    });

    it("follows a save that chose somebody", async () => {
        const { problem } = bind({ on: true, count: 0 });
        await openList();

        click("[data-assignees-save]");
        await tick();

        expect(problem()).toBeNull();
    });
});

describe("a page without the section", () => {
    it("does nothing and blocks nothing", () => {
        document.body.innerHTML = "";

        expect(bindSpecificStudents({ fetchStudents: vi.fn(), saveStudents: vi.fn(), notify: vi.fn() }).problem()).toBeNull();
    });
});
