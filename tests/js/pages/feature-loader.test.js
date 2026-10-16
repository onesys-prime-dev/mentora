import { beforeEach, describe, expect, it, vi } from "vitest";

const initialize = vi.fn(async () => {});

vi.mock("../../../resources/js/pages/courses/features/overview/index.js", () => ({ initialize: (...a) => initialize(...a) }));
vi.mock("../../../resources/js/pages/courses/features/modules/index.js", () => ({ initialize: (...a) => initialize(...a) }));
vi.mock("../../../resources/js/pages/courses/features/overview/skeleton.js", () => ({ renderOverviewSkeleton: () => "<div data-js-skeleton='overview'></div>" }));
vi.mock("../../../resources/js/pages/courses/features/modules/skeleton.js", () => ({ renderModulesSkeleton: () => "<div data-js-skeleton='modules'></div>" }));

const bar = () => document.querySelector("[data-spa-progress]");
const barOn = () => bar()?.classList.contains("is-active") ?? false;
const tick = () => new Promise((r) => setTimeout(r, 0));

beforeEach(async () => {
    vi.resetModules();
    initialize.mockReset();
    initialize.mockImplementation(async () => {});

    document.body.innerHTML = `
        <button data-course-feature="overview" aria-selected="true"></button>
        <button data-course-feature="modules" aria-selected="false"></button>
        <div id="course-content" aria-busy="true" data-server-skeleton>
            <div data-server-skeleton-shape>shape</div>
        </div>`;

    window.coursesConfig = { activeFeature: "overview", browserBaseUrl: "/c", role: "student" };

    const { resetProgress } = await import("../../../resources/js/shared/spa/progress.js");
    resetProgress();
});

describe("the first load of a course page", () => {

    it("keeps the server's skeleton until the screen has loaded, then lets it go", async () => {
        const { loadFeature } = await import("../../../resources/js/pages/courses/core/featureLoader.js");
        const content = document.querySelector("#course-content");

        let release;
        initialize.mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }));

        await loadFeature("overview");

        expect(content.querySelector("[data-server-skeleton-shape]")).not.toBeNull();
        expect(content.getAttribute("aria-busy")).toBe("true");

        content.innerHTML = "<p>the screen</p>";
        release();
        await tick();

        expect(content.hasAttribute("aria-busy")).toBe(false);
        expect(content.dataset.serverSkeleton).toBeUndefined();
        expect(content.dataset.spaSkeleton).toBeUndefined();
    });

});

describe("going to another tab feels like opening the page", () => {

    async function afterFirstLoad() {
        const loader = await import("../../../resources/js/pages/courses/core/featureLoader.js");

        await loader.loadFeature("overview");
        await tick();

        document.querySelector("#course-content").innerHTML = "<p>overview</p>";

        return loader;
    }

    it("lights the tab at once and draws the new tab's shape before its content", async () => {
        const { loadFeature } = await afterFirstLoad();
        const content = document.querySelector("#course-content");

        let release;
        let seenWhileLoading = null;

        initialize.mockImplementationOnce(() => {
            seenWhileLoading = content.innerHTML;

            return new Promise((resolve) => { release = resolve; });
        });

        await loadFeature("modules");

        expect(document.querySelector('[data-course-feature="modules"]').getAttribute("aria-selected")).toBe("true");
        expect(seenWhileLoading).toContain('data-js-skeleton="modules"');
        expect(content.dataset.serverSkeleton).toBe("");
        expect(content.classList.contains("spa-loading")).toBe(false);

        content.innerHTML = "<p>modules</p>";
        release();
        await tick();

        expect(content.dataset.serverSkeleton).toBeUndefined();
        expect(content.hasAttribute("aria-busy")).toBe(false);
    });

    it("shows the top bar while the tab loads and clears it afterwards", async () => {
        const { loadFeature } = await afterFirstLoad();

        let release;
        initialize.mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }));

        await loadFeature("modules");

        expect(barOn()).toBe(true);

        release();
        await tick();

        expect(barOn()).toBe(false);
    });

    it("leaves the bar to the newer tab when a slower one is overtaken", async () => {
        const { loadFeature } = await afterFirstLoad();

        let releaseSlow;
        initialize.mockImplementationOnce(() => new Promise((resolve) => { releaseSlow = resolve; }));

        const slow = loadFeature("modules");
        const fast = loadFeature("overview");

        await Promise.all([slow, fast]);
        await tick();

        // Both waits ended or were superseded; nothing is left spinning once the open one finishes.
        releaseSlow?.();
        await tick();

        expect(barOn()).toBe(false);
    });

});
