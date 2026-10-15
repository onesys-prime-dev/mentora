import { beforeEach, describe, expect, it, vi } from "vitest";

const initialize = vi.fn(async () => {});

vi.mock("../../../resources/js/pages/courses/features/overview/index.js", () => ({ initialize: (...a) => initialize(...a) }));
vi.mock("../../../resources/js/pages/courses/features/modules/index.js", () => ({ initialize: (...a) => initialize(...a) }));

beforeEach(() => {
    vi.resetModules();
    initialize.mockClear();

    document.body.innerHTML = `
        <button data-course-feature="overview" aria-selected="true"></button>
        <button data-course-feature="modules" aria-selected="false"></button>
        <div id="course-content" aria-busy="true" data-server-skeleton>
            <div data-server-skeleton-shape>shape</div>
        </div>`;

    window.coursesConfig = { activeFeature: "overview", browserBaseUrl: "/c" };
});

describe("the first load of a course page", () => {

    it("keeps the server's skeleton until the screen has loaded, then lets it go", async () => {
        const { loadFeature } = await import("../../../resources/js/pages/courses/core/featureLoader.js");
        const content = document.querySelector("#course-content");

        let release;
        initialize.mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }));

        await loadFeature("overview");

        // The module is running and has not finished: the skeleton is still there.
        expect(content.querySelector("[data-server-skeleton-shape]")).not.toBeNull();
        expect(content.getAttribute("aria-busy")).toBe("true");
        expect(content.dataset.serverSkeleton).toBe("");

        content.innerHTML = "<p>the screen</p>";
        release();
        await new Promise((r) => setTimeout(r, 0));

        expect(content.hasAttribute("aria-busy")).toBe(false);
        expect(content.dataset.serverSkeleton).toBeUndefined();
        expect(content.dataset.spaSkeleton).toBeUndefined();
    });

    it("starts a later tab from an empty region as before", async () => {
        const { loadFeature } = await import("../../../resources/js/pages/courses/core/featureLoader.js");
        const content = document.querySelector("#course-content");

        await loadFeature("overview");
        await new Promise((r) => setTimeout(r, 0));

        content.innerHTML = "<p>overview</p>";

        let seen = null;
        initialize.mockImplementationOnce(async () => { seen = content.innerHTML; });

        await loadFeature("modules");

        expect(seen).toBe("");
    });

});
