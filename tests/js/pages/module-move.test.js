import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("sortablejs", () => ({ default: { create: vi.fn() } }));

vi.mock("../../../resources/js/pages/courses/features/modules/api.js", () => ({
    reorderModules: vi.fn(),
    reorderLearningUnits: vi.fn(),
    reorderLearningItems: vi.fn(),
}));

vi.mock("../../../resources/js/shared/toast.js", () => ({ showToast: vi.fn() }));

const { default: Sortable } = await import("sortablejs");
const { showToast } = await import("../../../resources/js/shared/toast.js");
const api = await import("../../../resources/js/pages/courses/features/modules/api.js");
const { initializeModuleSorting } = await import("../../../resources/js/pages/courses/features/modules/module-sort.js");
const { initializeLearningUnitSorting } = await import("../../../resources/js/pages/courses/features/modules/learning-unit-sort.js");
const { initializeLearningItemSorting } = await import("../../../resources/js/pages/courses/features/modules/learning-item-sort.js");

const arrows = (attr) => `<button type="button" ${attr}="up">Up</button><button type="button" ${attr}="down">Down</button>`;

const moduleRow = (id) => `<div data-module-wrapper data-module-id="${id}">${arrows("data-module-move")}<div data-module="${id}"></div></div>`;

const itemRow = (id) => `<div data-learning-item-row><div data-learning-item-wrapper data-learning-item="${id}">${arrows("data-learning-item-move")}</div></div>`;

const unitRow = (id, items) => `
    <div data-learning-unit-row>${arrows("data-learning-unit-move")}
        <div data-learning-unit-wrapper data-learning-unit-id="${id}">
            <div data-learning-unit-items>${items.map(itemRow).join("")}<div data-create-learning-item-button></div></div>
        </div>
    </div>`;

const press = (selector) => {
    const button = document.querySelector(selector);
    button.focus();
    button.click();

    return button;
};

const status = () => document.querySelector("[data-module-move-status]").textContent;

let refresh;

beforeEach(() => {
    vi.clearAllMocks();
    Object.values(api).forEach((fn) => fn.mockResolvedValue({ success: true }));
    refresh = vi.fn();
});

describe("modules list", () => {
    beforeEach(() => {
        document.body.innerHTML = `<p data-module-move-status aria-live="polite"></p><div id="modules-container">${[1, 2, 3].map(moduleRow).join("")}</div>`;
        initializeModuleSorting(refresh);
    });

    const ids = () => [...document.querySelectorAll("[data-module-wrapper]")].map((m) => m.dataset.moduleId);

    it("a move button reorders modules through the drag's persist path", async () => {
        const up = press('[data-module-id="2"] [data-module-move="up"]');

        expect(ids()).toEqual(["2", "1", "3"]);
        expect(api.reorderModules).toHaveBeenCalledWith([
            { id: 2, order_index: 1 },
            { id: 1, order_index: 2 },
            { id: 3, order_index: 3 },
        ]);
        expect(status()).toBe("Module moved to position 1 of 3.");
        // Reaching the top disables ▲, so focus goes to the row's ▼ rather than being lost.
        expect(up.disabled).toBe(true);
        expect(document.activeElement).toBe(document.querySelector('[data-module-id="2"] [data-module-move="down"]'));

        // The drop calls the same save with the order on screen.
        const { onEnd } = Sortable.create.mock.calls[0][1];
        document.querySelector("#modules-container").append(document.querySelector('[data-module-id="2"]'));
        await onEnd({ item: document.querySelector('[data-module-id="2"]') });
        expect(api.reorderModules).toHaveBeenLastCalledWith([
            { id: 1, order_index: 1 },
            { id: 3, order_index: 2 },
            { id: 2, order_index: 3 },
        ]);
        expect(document.querySelector('[data-module-id="2"] [data-module-move="down"]').disabled).toBe(true);
    });

    it("keeps focus on a pressed button that is still enabled", () => {
        const down = press('[data-module-id="1"] [data-module-move="down"]');

        expect(ids()).toEqual(["2", "1", "3"]);
        expect(document.activeElement).toBe(down);
    });

    it("the first ▲ and the last ▼ are disabled and do nothing", () => {
        expect(document.querySelector('[data-module-id="1"] [data-module-move="up"]').disabled).toBe(true);
        expect(document.querySelector('[data-module-id="3"] [data-module-move="down"]').disabled).toBe(true);

        document.querySelector('[data-module-id="1"] [data-module-move="up"]').click();
        expect(ids()).toEqual(["1", "2", "3"]);
        expect(api.reorderModules).not.toHaveBeenCalled();
    });

    it("overlapping saves go one at a time and end on the order on screen", async () => {
        let finish;
        api.reorderModules.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));

        press('[data-module-id="3"] [data-module-move="up"]');
        press('[data-module-id="3"] [data-module-move="up"]');
        expect(api.reorderModules).toHaveBeenCalledTimes(1);

        finish({ success: true });
        await vi.waitFor(() => expect(api.reorderModules).toHaveBeenCalledTimes(2));
        expect(api.reorderModules.mock.calls[1][0].map((m) => m.id)).toEqual([3, 1, 2]);
    });

    it("a failed save says so and reloads the list", async () => {
        api.reorderModules.mockRejectedValueOnce(new Error("nope"));
        vi.spyOn(console, "error").mockImplementation(() => {});

        press('[data-module-id="2"] [data-module-move="up"]');

        await vi.waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
        expect(showToast).toHaveBeenCalledWith("The new order could not be saved. Try again.", { tone: "danger" });
    });
});

describe("learning units and items", () => {
    beforeEach(() => {
        document.body.innerHTML = `<p data-module-move-status aria-live="polite"></p>
            <div data-learning-unit-list>${unitRow(10, [100, 101])}${unitRow(11, [])}<div data-create-learning-unit-button></div></div>`;
        initializeLearningUnitSorting(refresh);
        initializeLearningItemSorting(refresh);
    });

    it("a move button reorders units through the drag's persist path, and an item's arrow does not move its unit", async () => {
        press('[data-learning-unit-id="10"] [data-learning-item="101"] [data-learning-item-move="up"]');
        expect(api.reorderLearningUnits).not.toHaveBeenCalled();
        expect(api.reorderLearningItems).toHaveBeenCalledWith([
            { id: 101, order_index: 1 },
            { id: 100, order_index: 2 },
        ]);
        expect(status()).toBe("Learning item moved to position 1 of 2.");

        // The unit's own ▼ (its arrows sit before its wrapper, so look them up from the row).
        const row = document.querySelector('[data-learning-unit-id="10"]').closest("[data-learning-unit-row]");
        row.querySelector(':scope > [data-learning-unit-move="down"]').click();
        expect(api.reorderLearningUnits).toHaveBeenCalledWith([
            { id: 11, order_index: 1 },
            { id: 10, order_index: 2 },
        ]);
        expect(status()).toBe("Learning unit moved to position 2 of 2.");

        // Dropping a unit with no items saves too (the drop read an item's wrapper and threw on an empty unit).
        const { onEnd } = Sortable.create.mock.calls.find(([el]) => el.matches("[data-learning-unit-list]"))[1];
        await onEnd({ item: document.querySelector('[data-learning-unit-id="11"]').closest("[data-learning-unit-row]") });
        expect(api.reorderLearningUnits).toHaveBeenCalledTimes(2);
    });

    it("each unit's item list saves on its own", async () => {
        document.querySelector('[data-learning-unit-id="11"] [data-learning-unit-items]').insertAdjacentHTML("afterbegin", itemRow(200) + itemRow(201));
        initializeLearningItemSorting(refresh);

        let finish;
        api.reorderLearningItems.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));

        press('[data-learning-item="101"] [data-learning-item-move="up"]');
        press('[data-learning-item="201"] [data-learning-item-move="up"]');

        // A save running for one unit does not hold back (or swallow) the other unit's.
        expect(api.reorderLearningItems).toHaveBeenCalledTimes(2);
        expect(api.reorderLearningItems.mock.calls[1][0].map((i) => i.id)).toEqual([201, 200]);
        finish({ success: true });
    });
});
