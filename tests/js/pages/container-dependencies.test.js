import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const fetchContainerDependencies = vi.fn();
const saveContainerDependencies = vi.fn();

vi.mock("../../../resources/js/pages/courses/features/modules/api.js", () => ({
    fetchContainerDependencies: (...args) => fetchContainerDependencies(...args),
    saveContainerDependencies: (...args) => saveContainerDependencies(...args),
}));

import { bindContainerDependenciesModal } from "../../../resources/js/pages/courses/features/modules/modals/container-dependencies.js";

/** A trimmed copy of partials/modals/container-dependencies.php and of a Dependencies button of a unit. */
const markup = `
    <button type="button" data-learning-unit-dependencies data-learning-unit-id="7" data-learning-unit-title="Unit 3">Dependencies</button>
    <button type="button" data-module-dependencies data-module-id="4" data-module-title="Module 2">Dependencies</button>
    <div id="dep-modal" class="hidden">
        <span data-dependencies-item-title></span>
        <input type="checkbox" data-dependencies-enabled>
        <div data-dependencies-options class="hidden">
            <input type="radio" name="dependency_mode" value="previous" data-dependencies-mode>
            <span data-dependencies-previous-hint></span>
            <input type="radio" name="dependency_mode" value="custom" data-dependencies-mode>
            <div data-dependencies-custom class="hidden"><div data-dependencies-candidates></div></div>
        </div>
        <p data-dependencies-error class="hidden"></p>
        <button type="button" data-dependencies-save>Save</button>
    </div>`;

const $ = (selector) => document.querySelector(selector);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const modal = () => ({ element: $("#dep-modal"), open: vi.fn(), close: vi.fn() });

const DATA = {
    success: true,
    item: { id: 7, title: "Unit 3" },
    mode: "none",
    prerequisiteIds: [],
    previous: { id: 6, title: "Unit 2" },
    candidates: [
        { id: 5, title: "Unit 1", status: "published" },
        { id: 6, title: "Unit 2", status: "draft" },
    ],
};

let refresh;
let controller;

const bind = (kind = "unit", data = DATA) => {
    document.body.innerHTML = markup;
    controller = modal();
    refresh = vi.fn().mockResolvedValue(undefined);
    fetchContainerDependencies.mockResolvedValue(data);
    saveContainerDependencies.mockResolvedValue({ success: true });
    bindContainerDependenciesModal({ kind, modal: controller, refresh });
};

const openIt = async (selector = "[data-learning-unit-dependencies]") => {
    $(selector).click();
    await tick();
};

beforeEach(() => {
    fetchContainerDependencies.mockReset();
    saveContainerDependencies.mockReset();
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("opening", () => {
    it("loads the unit's options and opens", async () => {
        bind("unit");
        await openIt();

        expect(fetchContainerDependencies).toHaveBeenCalledWith("unit", 7);
        expect(controller.open).toHaveBeenCalled();
        expect($("[data-dependencies-item-title]").textContent).toBe("“Unit 3”");
        expect($("[data-dependencies-previous-hint]").textContent).toBe("Unlocks after “Unit 2” is completed.");
        expect(document.querySelectorAll("[data-dependency-candidate]")).toHaveLength(2);
        expect($("[data-dependencies-enabled]").checked).toBe(false);
    });

    it("loads a module's options from the module button", async () => {
        bind("module");
        await openIt("[data-module-dependencies]");

        expect(fetchContainerDependencies).toHaveBeenCalledWith("module", 4);
    });

    it("does not react to the other kind's button", async () => {
        bind("module");
        await openIt("[data-learning-unit-dependencies]");

        expect(fetchContainerDependencies).not.toHaveBeenCalled();
    });

    it("shows what is already chosen, with the list open", async () => {
        bind("unit", { ...DATA, mode: "custom", prerequisiteIds: [5] });
        await openIt();

        expect($("[data-dependencies-enabled]").checked).toBe(true);
        expect($("[data-dependency-candidate][value='5']").checked).toBe(true);
        expect($("[data-dependency-candidate][value='6']").checked).toBe(false);
        expect($("[data-dependencies-custom]").classList.contains("hidden")).toBe(false);
    });

    it("says there is nothing before the first one and cannot choose previous", async () => {
        bind("unit", { ...DATA, previous: null, candidates: [] });
        await openIt();

        expect($("[data-dependencies-previous-hint]").textContent).toMatch(/first learning unit/);
        expect($("[data-dependencies-mode][value='previous']").disabled).toBe(true);
        expect($("[data-dependencies-candidates]").textContent).toMatch(/no earlier learning units/i);
    });

    it("says so when the options cannot be loaded, and does not allow saving", async () => {
        bind("unit");
        fetchContainerDependencies.mockRejectedValue(new Error("offline"));
        await openIt();

        expect($("[data-dependencies-error]").textContent).toMatch(/couldn't load this learning unit/i);
        expect($("[data-dependencies-save]").disabled).toBe(true);
    });

    it("escapes a title from the database", async () => {
        bind("unit", { ...DATA, candidates: [{ id: 5, title: "<img src=x onerror=alert(1)>", status: "published" }] });
        await openIt();

        expect(document.querySelector("[data-dependencies-candidates] img")).toBeNull();
    });
});

describe("saving", () => {
    it("turning it on picks the previous one, and saves it", async () => {
        bind("unit");
        await openIt();

        $("[data-dependencies-enabled]").click();
        expect($("[data-dependencies-mode][value='previous']").checked).toBe(true);

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveContainerDependencies).toHaveBeenCalledWith("unit", 7, { mode: "previous", prerequisite_ids: [] });
        expect(controller.close).toHaveBeenCalled();
        expect(refresh).toHaveBeenCalled();
    });

    it("saves a custom list", async () => {
        bind("module");
        await openIt("[data-module-dependencies]");

        $("[data-dependencies-enabled]").click();
        $("[data-dependencies-mode][value='custom']").click();
        $("[data-dependency-candidate][value='5']").click();

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveContainerDependencies).toHaveBeenCalledWith("module", 4, { mode: "custom", prerequisite_ids: [5] });
    });

    it("turning it off saves none", async () => {
        bind("unit", { ...DATA, mode: "previous" });
        await openIt();

        $("[data-dependencies-enabled]").click();
        $("[data-dependencies-save]").click();
        await tick();

        expect(saveContainerDependencies).toHaveBeenCalledWith("unit", 7, { mode: "none", prerequisite_ids: [] });
    });

    it("asks for at least one when custom has none ticked", async () => {
        bind("unit");
        await openIt();

        $("[data-dependencies-enabled]").click();
        $("[data-dependencies-mode][value='custom']").click();
        $("[data-dependencies-save]").click();
        await tick();

        expect(saveContainerDependencies).not.toHaveBeenCalled();
        expect($("[data-dependencies-error]").textContent).toMatch(/pick at least one learning unit/i);
    });

    it("keeps the modal open and shows the reason when the server refuses", async () => {
        bind("unit");
        await openIt();
        saveContainerDependencies.mockResolvedValue({ success: false, message: "This is the first learning unit, so there's no previous one to wait for." });

        $("[data-dependencies-enabled]").click();
        $("[data-dependencies-save]").click();
        await tick();

        expect(controller.close).not.toHaveBeenCalled();
        expect($("[data-dependencies-error]").textContent).toContain("first learning unit");
        expect($("[data-dependencies-save]").disabled).toBe(false);
    });

    it("keeps the modal open when the request fails", async () => {
        bind("unit");
        await openIt();
        saveContainerDependencies.mockRejectedValue(new Error("offline"));

        $("[data-dependencies-enabled]").click();
        $("[data-dependencies-save]").click();
        await tick();

        expect(controller.close).not.toHaveBeenCalled();
        expect($("[data-dependencies-error]").textContent).toMatch(/couldn't save/i);
    });
});

describe("without the modal", () => {
    it("does nothing", () => {
        document.body.innerHTML = markup;

        expect(() => bindContainerDependenciesModal({ kind: "unit", modal: null, refresh: vi.fn() })).not.toThrow();
    });
});
