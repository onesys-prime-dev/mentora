import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const fetchLearningItemDependencies = vi.fn();
const saveLearningItemDependencies = vi.fn();

vi.mock("../../../resources/js/pages/courses/features/modules/api.js", () => ({
    fetchLearningItemDependencies: (...args) => fetchLearningItemDependencies(...args),
    saveLearningItemDependencies: (...args) => saveLearningItemDependencies(...args),
}));

import { bindLearningItemDependenciesModal } from "../../../resources/js/pages/courses/features/modules/modals/learning-item-dependencies.js";

/** A trimmed copy of partials/modals/learning-item-dependencies.php and the item's menu button. */
const markup = `
    <button type="button" data-learning-item-dependencies data-learning-item-id="9" data-learning-item-title="Next">Dependencies</button>
    <div id="dep-modal" class="hidden">
        <span data-dependencies-item-title></span>
        <input type="checkbox" data-dependencies-enabled>
        <div data-dependencies-options class="hidden">
            <input type="radio" name="dependency_mode" value="previous" data-dependencies-mode>
            <span data-dependencies-previous-hint></span>
            <input type="radio" name="dependency_mode" value="custom" data-dependencies-mode>
            <div data-dependencies-custom class="hidden"><div data-dependencies-candidates></div></div>
        </div>
        <div data-minscore-block class="hidden">
            <input type="checkbox" data-minscore-enabled>
            <div data-minscore-options class="hidden">
                <input type="radio" name="minscore_mode" value="passing" data-minscore-mode>
                <input type="radio" name="minscore_mode" value="custom" data-minscore-mode>
                <span data-minscore-custom class="hidden"><input type="number" data-minscore-percent></span>
            </div>
        </div>
        <p data-dependencies-error class="hidden"></p>
        <button type="button" data-dependencies-save>Save</button>
    </div>`;

const $ = (selector) => document.querySelector(selector);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const hidden = (selector) => $(selector).classList.contains("hidden");

const DATA = {
    success: true,
    item: { id: 9, title: "Next" },
    mode: "none",
    prerequisiteIds: [],
    minScore: { mode: "none", percent: null },
    previous: { id: 8, title: "Quiz 1", item_type: "quiz" },
    candidates: [
        { id: 7, title: "Read me", item_type: "reading", status: "published" },
        { id: 8, title: "Quiz 1", item_type: "quiz", status: "published" },
    ],
};

let controller;

const bind = async (data = DATA) => {
    document.body.innerHTML = markup;
    controller = { element: $("#dep-modal"), open: vi.fn(), close: vi.fn() };
    fetchLearningItemDependencies.mockResolvedValue(data);
    saveLearningItemDependencies.mockResolvedValue({ success: true });
    bindLearningItemDependenciesModal({ modal: controller, refresh: vi.fn().mockResolvedValue(undefined) });
    $("[data-learning-item-dependencies]").click();
    await tick();
};

beforeEach(() => {
    fetchLearningItemDependencies.mockReset();
    saveLearningItemDependencies.mockReset();
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("when the minimum score is offered", () => {
    it("is not, while dependencies are off", async () => {
        await bind();

        expect(hidden("[data-minscore-block]")).toBe(true);
    });

    it("is, once it waits on the previous item and that is a quiz", async () => {
        await bind();

        $("[data-dependencies-enabled]").click();

        expect($("[data-dependencies-mode][value='previous']").checked).toBe(true);
        expect(hidden("[data-minscore-block]")).toBe(false);
    });

    it("is not when the previous item is a reading", async () => {
        await bind({ ...DATA, previous: { id: 7, title: "Read me", item_type: "reading" } });

        $("[data-dependencies-enabled]").click();

        expect(hidden("[data-minscore-block]")).toBe(true);
    });

    it("follows the ticked items of a custom list", async () => {
        await bind();
        $("[data-dependencies-enabled]").click();
        $("[data-dependencies-mode][value='custom']").click();

        $("[data-dependency-candidate][value='7']").click();
        expect(hidden("[data-minscore-block]")).toBe(true);

        $("[data-dependency-candidate][value='8']").click();
        expect(hidden("[data-minscore-block]")).toBe(false);

        $("[data-dependency-candidate][value='8']").click();
        expect(hidden("[data-minscore-block]")).toBe(true);
    });
});

describe("choosing the score", () => {
    const turnOn = async (data) => {
        await bind(data);
        $("[data-dependencies-enabled]").click();
        $("[data-minscore-enabled]").click();
    };

    it("starts on the assessment's own passing score and shows no percentage box", async () => {
        await turnOn();

        expect($("[data-minscore-mode][value='passing']").checked).toBe(true);
        expect(hidden("[data-minscore-options]")).toBe(false);
        expect(hidden("[data-minscore-custom]")).toBe(true);
    });

    it("shows the percentage box for a custom one", async () => {
        await turnOn();

        $("[data-minscore-mode][value='custom']").click();

        expect(hidden("[data-minscore-custom]")).toBe(false);
    });

    it("sends the passing score", async () => {
        await turnOn();

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveLearningItemDependencies).toHaveBeenCalledWith(9, { mode: "previous", prerequisite_ids: [], min_score: { mode: "passing", percent: null } });
    });

    it("sends a custom percentage", async () => {
        await turnOn();
        $("[data-minscore-mode][value='custom']").click();
        $("[data-minscore-percent]").value = "70";

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveLearningItemDependencies.mock.calls[0][1].min_score).toEqual({ mode: "custom", percent: 70 });
    });

    it.each(["", "0", "101", "-3"])("will not send a custom percentage of %j", async (typed) => {
        await turnOn();
        $("[data-minscore-mode][value='custom']").click();
        $("[data-minscore-percent]").value = typed;

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveLearningItemDependencies).not.toHaveBeenCalled();
        expect($("[data-dependencies-error]").textContent).toMatch(/1 to 100/);
    });

    it("sends none when the switch is off", async () => {
        await bind();
        $("[data-dependencies-enabled]").click();

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveLearningItemDependencies.mock.calls[0][1].min_score).toEqual({ mode: "none", percent: null });
    });

    it("sends none when nothing it waits on is an assessment any more", async () => {
        await turnOn();
        $("[data-dependencies-mode][value='custom']").click();
        $("[data-dependency-candidate][value='7']").click();

        $("[data-dependencies-save]").click();
        await tick();

        expect(saveLearningItemDependencies.mock.calls[0][1].min_score.mode).toBe("none");
    });
});

describe("what is already saved", () => {
    it("shows a custom percentage", async () => {
        await bind({ ...DATA, mode: "previous", minScore: { mode: "custom", percent: 75 } });

        expect($("[data-minscore-enabled]").checked).toBe(true);
        expect($("[data-minscore-mode][value='custom']").checked).toBe(true);
        expect($("[data-minscore-percent]").value).toBe("75");
        expect(hidden("[data-minscore-custom]")).toBe(false);
    });

    it("shows the passing score", async () => {
        await bind({ ...DATA, mode: "previous", minScore: { mode: "passing", percent: null } });

        expect($("[data-minscore-mode][value='passing']").checked).toBe(true);
    });

    it("shows the switch off when none is required", async () => {
        await bind({ ...DATA, mode: "previous" });

        expect($("[data-minscore-enabled]").checked).toBe(false);
        expect(hidden("[data-minscore-options]")).toBe(true);
    });
});
