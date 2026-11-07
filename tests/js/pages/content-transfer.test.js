import { afterEach, describe, expect, it, vi } from "vitest";

import { bindContentTransfer, stepsFor } from "../../../resources/js/pages/courses/features/modules/modals/content-transfer.js";

/** A trimmed copy of partials/modals/content-transfer.php and a "Copy to" button. */
const markup = `
    <button type="button" id="open-copy-module" data-transfer data-transfer-action="copy" data-transfer-kind="module" data-transfer-id="4" data-transfer-name="Module 1">Copy</button>
    <button type="button" id="open-move-module" data-transfer data-transfer-action="move" data-transfer-kind="module" data-transfer-id="4" data-transfer-name="Module 1">Move</button>
    <button type="button" id="open-copy-unit" data-transfer data-transfer-action="copy" data-transfer-kind="unit" data-transfer-id="7" data-transfer-name="Unit A">Copy</button>
    <button type="button" id="open-move-unit" data-transfer data-transfer-action="move" data-transfer-kind="unit" data-transfer-id="7" data-transfer-name="Unit A">Move</button>
    <button type="button" id="open-copy-item" data-transfer data-transfer-action="copy" data-transfer-kind="item" data-transfer-id="9" data-transfer-name="Read me">Copy</button>
    <button type="button" id="open-move-item" data-transfer data-transfer-action="move" data-transfer-kind="item" data-transfer-id="9" data-transfer-name="Read me">Move</button>
    <div id="transfer-modal" class="hidden">
        <h2 data-transfer-title></h2>
        <p data-transfer-subtitle></p>
        <span data-transfer-progress></span>

        <div data-transfer-step="course" class="hidden">
            <label data-transfer-this-row><input type="radio" name="scope" value="this" data-transfer-scope> This course</label>
            <label><input type="radio" name="scope" value="other" data-transfer-scope> Another course</label>
            <select data-transfer-offering></select>
            <p data-transfer-no-other class="hidden"></p>
        </div>
        <div data-transfer-step="module" class="hidden">
            <select data-transfer-module></select>
            <p data-transfer-no-modules class="hidden"></p>
        </div>
        <div data-transfer-step="unit" class="hidden">
            <select data-transfer-unit></select>
            <p data-transfer-no-units class="hidden"></p>
        </div>
        <div data-transfer-step="tree" class="hidden"><div data-transfer-tree></div></div>
        <div data-transfer-step="confirm" class="hidden"><p data-transfer-confirm-text></p></div>

        <p data-transfer-error class="hidden"></p>
        <button type="button" data-transfer-back>Back</button>
        <button type="button" data-close-transfer-modal>Cancel</button>
        <button type="button" data-transfer-next>Next</button>
    </div>`;

const OFFERINGS = [
    { id: 1, label: "IT101 · A", title: "Intro", term: "1st Semester 2026-2027", is_current: true },
    { id: 2, label: "IT102 · B", title: "Databases", term: "1st Semester 2026-2027", is_current: false },
];

const TREE = {
    success: true,
    title: "Module 1",
    units: [
        { id: 10, title: "Unit A", items: [{ id: 100, title: "Read me", item_type: "reading" }, { id: 101, title: "Quiz", item_type: "quiz" }] },
        { id: 11, title: "Unit B", items: [{ id: 102, title: "Video", item_type: "video" }] },
    ],
};

const $ = (selector) => document.querySelector(selector);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const shownSteps = () => [...document.querySelectorAll("[data-transfer-step]")].filter((el) => !el.classList.contains("hidden")).map((el) => el.dataset.transferStep);
const click = (selector) => $(selector).click();
const next = async () => { click("[data-transfer-next]"); await tick(); };
const choose = (scope) => { const radio = $(`[data-transfer-scope][value="${scope}"]`); radio.checked = true; radio.dispatchEvent(new Event("change", { bubbles: true })); };
const pick = (selector, value) => { $(selector).value = String(value); $(selector).dispatchEvent(new Event("change", { bubbles: true })); };

let api;
let controller;
let refresh;
let notify;

const bind = () => {
    document.body.innerHTML = markup;
    controller = { element: $("#transfer-modal"), open: vi.fn(), close: vi.fn() };
    api = {
        fetchOfferings: vi.fn().mockResolvedValue(OFFERINGS),
        fetchModules: vi.fn().mockResolvedValue([{ id: 20, title: "Target module", status: "published" }, { id: 21, title: "Other", status: "draft" }]),
        fetchUnits: vi.fn().mockResolvedValue([{ id: 30, title: "Target unit", status: "published" }]),
        fetchTree: vi.fn().mockResolvedValue(TREE),
        copy: vi.fn().mockResolvedValue({ success: true, message: "Module copied." }),
        move: vi.fn().mockResolvedValue({ success: true, message: "Module moved." }),
    };
    refresh = vi.fn().mockResolvedValue(undefined);
    notify = vi.fn();
    bindContentTransfer({ modal: controller, api, refresh, notify });
};

const open = async (id) => { click(`#${id}`); await tick(); };

afterEach(() => {
    document.body.innerHTML = "";
});

describe("the steps", () => {
    it.each([
        ["copy", "module", ["course", "tree"]],
        ["copy", "unit", ["course", "module", "tree"]],
        ["copy", "item", ["course", "module", "unit"]],
        ["move", "module", ["course", "confirm"]],
        ["move", "unit", ["course", "module", "confirm"]],
        ["move", "item", ["course", "module", "unit", "confirm"]],
    ])("%s a %s goes through %j", (action, kind, steps) => {
        expect(stepsFor(action, kind)).toEqual(steps);
    });
});

describe("opening", () => {
    it("names what is being copied and starts at the course", async () => {
        bind();
        await open("open-copy-module");

        expect(controller.open).toHaveBeenCalled();
        expect($("[data-transfer-title]").textContent).toBe("Copy “Module 1”");
        expect(shownSteps()).toEqual(["course"]);
        expect($("[data-transfer-progress]").textContent).toBe("Step 1 of 2");
        expect(api.fetchOfferings).toHaveBeenCalledTimes(1);
    });

    it("says Move for a move", async () => {
        bind();
        await open("open-move-unit");

        expect($("[data-transfer-title]").textContent).toBe("Move “Unit A”");
    });
});

describe("the course step", () => {
    it("offers this course and another course for a copy", async () => {
        bind();
        await open("open-copy-module");

        expect($("[data-transfer-this-row]").classList.contains("hidden")).toBe(false);
        expect([...$("[data-transfer-offering]").options].map((o) => o.value)).toEqual(["2"]);
        expect($("[data-transfer-offering]").options[0].textContent).toContain("IT102 · B");
    });

    it("offers only another course for a move", async () => {
        bind();
        await open("open-move-module");

        expect($("[data-transfer-this-row]").classList.contains("hidden")).toBe(true);
        expect($("[data-transfer-scope][value='other']").checked).toBe(true);
    });

    it("the chooser is shown for another course only", async () => {
        bind();
        await open("open-copy-module");

        choose("this");
        expect($("[data-transfer-offering]").disabled).toBe(true);

        choose("other");
        expect($("[data-transfer-offering]").disabled).toBe(false);
    });

    it("asks for a choice before going on", async () => {
        bind();
        await open("open-copy-module");

        await next();

        expect($("[data-transfer-error]").textContent).toMatch(/choose/i);
        expect(shownSteps()).toEqual(["course"]);
    });

    it("says so when there is no other course to move to, and cannot go on", async () => {
        bind();
        api.fetchOfferings.mockResolvedValue([OFFERINGS[0]]);
        await open("open-move-module");

        expect($("[data-transfer-no-other]").classList.contains("hidden")).toBe(false);
        expect($("[data-transfer-next]").disabled).toBe(true);
    });

    it("says so when the courses cannot be loaded", async () => {
        bind();
        api.fetchOfferings.mockRejectedValue(new Error("offline"));
        await open("open-copy-module");

        expect($("[data-transfer-error]").textContent).toMatch(/could not be loaded/i);
        expect($("[data-transfer-next]").disabled).toBe(true);
    });
});

describe("copying a module", () => {
    it("this course goes straight to choosing what to copy, with every unit and item ticked", async () => {
        bind();
        await open("open-copy-module");
        choose("this");

        await next();

        expect(api.fetchTree).toHaveBeenCalledWith("module", 4);
        expect(shownSteps()).toEqual(["tree"]);
        expect($("[data-transfer-next]").textContent).toBe("Copy");
        expect(document.querySelectorAll("[data-transfer-item]:checked")).toHaveLength(3);
        expect(document.querySelectorAll("[data-transfer-unit-box]:checked")).toHaveLength(2);
        expect($("[data-transfer-tree]").textContent).toContain("Quiz");
    });

    it("unticking a unit unticks its items; ticking an item ticks its unit", async () => {
        bind();
        await open("open-copy-module");
        choose("this");
        await next();

        $("[data-transfer-unit-box][value='10']").click();
        expect([...document.querySelectorAll("[data-transfer-item]:checked")].map((b) => b.value)).toEqual(["102"]);

        $("[data-transfer-item][value='101']").click();
        expect($("[data-transfer-unit-box][value='10']").checked).toBe(true);
    });

    it("sends the units and items ticked", async () => {
        bind();
        await open("open-copy-module");
        choose("other");
        pick("[data-transfer-offering]", 2);
        await next();

        $("[data-transfer-item][value='100']").click();
        $("[data-transfer-unit-box][value='11']").click();
        await next();

        expect(api.copy).toHaveBeenCalledWith({
            kind: "module",
            id: 4,
            target: { offering_id: 2 },
            unit_ids: [10],
            item_ids: [101],
        });
        expect(controller.close).toHaveBeenCalled();
        expect(notify).toHaveBeenCalledWith("Module copied.", "success");
        expect(refresh).toHaveBeenCalled();
    });

    it("keeps the dialog open with the reason when the server refuses", async () => {
        bind();
        api.copy.mockResolvedValue({ success: false, message: "Choose a course you teach." });
        await open("open-copy-module");
        choose("this");
        await next();
        await next();

        expect(controller.close).not.toHaveBeenCalled();
        expect($("[data-transfer-error]").textContent).toBe("Choose a course you teach.");
        expect($("[data-transfer-next]").disabled).toBe(false);
    });

    it("keeps the dialog open when the request fails", async () => {
        bind();
        api.copy.mockRejectedValue(new Error("offline"));
        await open("open-copy-module");
        choose("this");
        await next();
        await next();

        expect(controller.close).not.toHaveBeenCalled();
        expect($("[data-transfer-error]").textContent).toMatch(/could not be copied/i);
    });
});

describe("copying a unit", () => {
    it("picks the course, then the module, then the items", async () => {
        bind();
        await open("open-copy-unit");
        choose("other");
        pick("[data-transfer-offering]", 2);
        await next();

        expect(api.fetchModules).toHaveBeenCalledWith(2);
        expect(shownSteps()).toEqual(["module"]);
        expect($("[data-transfer-progress]").textContent).toBe("Step 2 of 3");

        pick("[data-transfer-module]", 20);
        await next();

        expect(api.fetchTree).toHaveBeenCalledWith("unit", 7);
        expect(shownSteps()).toEqual(["tree"]);

        await next();

        expect(api.copy).toHaveBeenCalledWith({
            kind: "unit",
            id: 7,
            target: { offering_id: 2, module_id: 20 },
            unit_ids: null,
            item_ids: [100, 101, 102],
        });
    });

    it("this course lists this course's modules", async () => {
        bind();
        await open("open-copy-unit");
        choose("this");
        await next();

        expect(api.fetchModules).toHaveBeenCalledWith(1);
    });

    it("says so when the course has no module to put it in", async () => {
        bind();
        api.fetchModules.mockResolvedValue([]);
        await open("open-copy-unit");
        choose("other");
        pick("[data-transfer-offering]", 2);
        await next();

        expect($("[data-transfer-no-modules]").classList.contains("hidden")).toBe(false);
        expect($("[data-transfer-next]").disabled).toBe(true);
    });
});

describe("copying an item", () => {
    it("picks the course, the module, then the unit, and copies", async () => {
        bind();
        await open("open-copy-item");
        choose("other");
        pick("[data-transfer-offering]", 2);
        await next();
        pick("[data-transfer-module]", 20);
        await next();

        expect(api.fetchUnits).toHaveBeenCalledWith(20);
        expect(shownSteps()).toEqual(["unit"]);
        expect($("[data-transfer-next]").textContent).toBe("Copy");

        pick("[data-transfer-unit]", 30);
        await next();

        expect(api.copy).toHaveBeenCalledWith({ kind: "item", id: 9, target: { offering_id: 2, module_id: 20, unit_id: 30 }, unit_ids: null, item_ids: null });
    });

    it("says so when the module has no unit", async () => {
        bind();
        api.fetchUnits.mockResolvedValue([]);
        await open("open-copy-item");
        choose("this");
        await next();
        pick("[data-transfer-module]", 20);
        await next();

        expect($("[data-transfer-no-units]").classList.contains("hidden")).toBe(false);
        expect($("[data-transfer-next]").disabled).toBe(true);
    });
});

describe("moving", () => {
    it("a module: another course, then a final confirmation naming what happens", async () => {
        bind();
        await open("open-move-module");
        pick("[data-transfer-offering]", 2);
        await next();

        expect(shownSteps()).toEqual(["confirm"]);
        expect($("[data-transfer-next]").textContent).toBe("Move");
        expect($("[data-transfer-confirm-text]").textContent).toContain("“Module 1”");
        expect($("[data-transfer-confirm-text]").textContent).toContain("IT102 · B");
        expect($("[data-transfer-confirm-text]").textContent).toMatch(/draft/i);
        expect(api.move).not.toHaveBeenCalled();

        await next();

        expect(api.move).toHaveBeenCalledWith({ kind: "module", id: 4, target: { offering_id: 2 } });
        expect(notify).toHaveBeenCalledWith("Module moved.", "success");
        expect(refresh).toHaveBeenCalled();
    });

    it("a unit: another course, the module, then the confirmation", async () => {
        bind();
        await open("open-move-unit");
        pick("[data-transfer-offering]", 2);
        await next();
        pick("[data-transfer-module]", 21);
        await next();

        expect(shownSteps()).toEqual(["confirm"]);
        expect($("[data-transfer-confirm-text]").textContent).toContain("Other");

        await next();

        expect(api.move).toHaveBeenCalledWith({ kind: "unit", id: 7, target: { offering_id: 2, module_id: 21 } });
    });

    it("an item: another course, the module, the unit, then the confirmation", async () => {
        bind();
        await open("open-move-item");
        pick("[data-transfer-offering]", 2);
        await next();
        pick("[data-transfer-module]", 20);
        await next();
        pick("[data-transfer-unit]", 30);
        await next();

        expect(shownSteps()).toEqual(["confirm"]);
        expect($("[data-transfer-progress]").textContent).toBe("Step 4 of 4");

        await next();

        expect(api.move).toHaveBeenCalledWith({ kind: "item", id: 9, target: { offering_id: 2, module_id: 20, unit_id: 30 } });
    });

    it("shows the refusal, such as student work, and stays open", async () => {
        bind();
        api.move.mockResolvedValue({ success: false, message: "Students have already worked on 2 items in this module, so it cannot be moved. Copy it instead." });
        await open("open-move-module");
        pick("[data-transfer-offering]", 2);
        await next();
        await next();

        expect(controller.close).not.toHaveBeenCalled();
        expect($("[data-transfer-error]").textContent).toContain("Copy it instead");
    });
});

describe("going back and starting over", () => {
    it("Back returns to the step before, and is not offered on the first", async () => {
        bind();
        await open("open-copy-unit");
        expect($("[data-transfer-back]").classList.contains("hidden")).toBe(true);

        choose("this");
        await next();
        expect(shownSteps()).toEqual(["module"]);
        expect($("[data-transfer-back]").classList.contains("hidden")).toBe(false);

        click("[data-transfer-back]");
        expect(shownSteps()).toEqual(["course"]);
    });

    it("opening again starts clean, without the old error or choices", async () => {
        bind();
        await open("open-copy-module");
        await next();
        expect($("[data-transfer-error]").textContent).not.toBe("");

        await open("open-move-item");

        expect($("[data-transfer-error]").textContent).toBe("");
        expect(shownSteps()).toEqual(["course"]);
        expect($("[data-transfer-title]").textContent).toBe("Move “Read me”");
    });

    it("does nothing twice for a double press on the last step", async () => {
        bind();
        let release;
        api.copy.mockImplementation(() => new Promise((resolve) => { release = resolve; }));
        await open("open-copy-item");
        choose("this");
        await next();
        pick("[data-transfer-module]", 20);
        await next();
        pick("[data-transfer-unit]", 30);

        click("[data-transfer-next]");
        click("[data-transfer-next]");
        release({ success: true, message: "ok" });
        await tick();

        expect(api.copy).toHaveBeenCalledTimes(1);
    });
});

describe("what comes from the database is text", () => {
    it("a title is never read as markup", async () => {
        bind();
        api.fetchTree.mockResolvedValue({ success: true, title: "x", units: [{ id: 10, title: "<img src=x onerror=alert(1)>", items: [{ id: 100, title: "<b>bold</b>", item_type: "reading" }] }] });
        await open("open-copy-module");
        choose("this");
        await next();

        expect(document.querySelector("[data-transfer-tree] img")).toBeNull();
        expect(document.querySelector("[data-transfer-tree] b")).toBeNull();
    });
});

describe("a page without the dialog", () => {
    it("does nothing", () => {
        document.body.innerHTML = "";

        expect(() => bindContentTransfer({ modal: null, api: {}, refresh: vi.fn(), notify: vi.fn() })).not.toThrow();
    });
});
