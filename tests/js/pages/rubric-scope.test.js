import { beforeEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({
    fetchRubrics: vi.fn(),
    fetchRubric: vi.fn(),
    createRubric: vi.fn(),
    selectRubric: vi.fn(),
    updateRubric: vi.fn(),
    deleteRubric: vi.fn(),
    createRubricCriterion: vi.fn(async () => ({ rubric: { criteria: [] } })),
    updateRubricCriterion: vi.fn(async () => ({ rubric: { criteria: [] } })),
    deleteRubricCriterion: vi.fn(),
}));

vi.mock("../../../resources/js/pages/courses/features/assessments/api.js", () => api);
vi.mock("../../../resources/js/pages/courses/features/assessments/modals/index.js", () => ({
    bindModal: () => ({ open() {}, close() {} }),
}));

// The module keeps its state between calls, so each test gets a fresh copy.
async function load() {

    vi.resetModules();

    return (await import("../../../resources/js/pages/courses/features/assessments/rubrics/rubrics.js")).initializeRubrics;

}

function page(groupActivity) {

    document.body.innerHTML = `
        <div id="rubric-editor-container" ${groupActivity ? "data-rubric-group-activity=\"1\"" : ""}></div>
        <button data-add-rubric-criteria data-context="create"></button>
        <div data-rubric-scope-field class="hidden">
            <select data-criteria-create-scope>
                <option value="individual" selected>Per student</option>
                <option value="group">Group</option>
            </select>
        </div>
        <form id="rubric-create-form">
            <input data-rubric-create-title value="Rubric">
            <textarea data-rubric-create-description></textarea>
        </form>
        <form id="rubric-criteria-create-form">
            <input data-criteria-create-title value="Teamwork">
            <input data-criteria-create-description value="">
            <input data-criteria-create-max-points value="5">
        </form>
    `;

}

describe("the Scored control on a rubric criterion", () => {

    beforeEach(() => {

        vi.clearAllMocks();

    });

    it("is shown only for a group activity", async () => {

        const initializeRubrics = await load();

        page(true);
        initializeRubrics(1, 2, () => {});
        expect(document.querySelector("[data-rubric-scope-field]").classList.contains("hidden")).toBe(false);

        page(false);
        initializeRubrics(1, 2, () => {});
        expect(document.querySelector("[data-rubric-scope-field]").classList.contains("hidden")).toBe(true);

    });

    it("is saved with a new criterion", async () => {

        const initializeRubrics = await load();
        page(true);
        initializeRubrics(1, 2, () => {});

        // Staged flow (the rubric is not saved yet): the criterion keeps its scope until the rubric is created.
        document.querySelector("[data-add-rubric-criteria]").click();
        document.querySelector("[data-criteria-create-scope]").value = "group";
        document.querySelector("#rubric-criteria-create-form").dispatchEvent(new Event("submit", { cancelable: true }));
        document.querySelector("#rubric-create-form").dispatchEvent(new Event("submit", { cancelable: true }));
        await Promise.resolve();

        expect(api.createRubric).toHaveBeenCalledTimes(1);
        expect(api.createRubric.mock.calls[0][2].criteria).toEqual([
            { title: "Teamwork", description: "", max_points: 5, scope: "group" },
        ]);

    });

    it("falls back to per student when left alone", async () => {

        const initializeRubrics = await load();
        page(false);
        initializeRubrics(1, 2, () => {});

        document.querySelector("[data-add-rubric-criteria]").click();
        document.querySelector("#rubric-criteria-create-form").dispatchEvent(new Event("submit", { cancelable: true }));
        document.querySelector("#rubric-create-form").dispatchEvent(new Event("submit", { cancelable: true }));
        await Promise.resolve();

        expect(api.createRubric.mock.calls[0][2].criteria[0].scope).toBe("individual");

    });

});
