import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * A parent's four tabs reuse the student's tab scripts unchanged. Those
 * scripts bind to whatever the fragment contains, so a lean parent fragment
 * (no composer, no reply or reaction buttons, no assessment links) must load
 * without the error screen, and the one control a parent has, the grade
 * report button, must still reach the parent's own address.
 */

const api = "/parent/children/2/api/courses/1";

const fragments = {
    overview: '<div data-marker="parent-overview"><p>Intro to Computing</p></div>',
    announcements: '<div data-marker="parent-announcements"><article data-announcement-card data-announcement-id="7"><h3>Room change</h3></article></div>',
    assessments: '<div data-marker="parent-assessments"><section data-parent-assessments="Quiz"><ul><li>Quiz Three</li></ul></section></div>',
    grades: '<div data-marker="parent-grades"><div><button type="button" data-report-path="mine" data-report-format="pdf" data-my-grade-report>Download grade report</button></div></div>',
};

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const load = async (feature, html = fragments[feature]) => {
    document.body.innerHTML = '<div id="course-content" aria-busy="true"></div>';

    window.coursesConfig = {
        offeringId: 1,
        slug: "intro",
        role: "parent",
        apiBaseUrl: api,
        browserBaseUrl: "/parent/children/2/courses/1/intro",
        activeFeature: feature,
    };

    const fetch = vi.spyOn(window, "fetch").mockResolvedValue({ ok: true, json: async () => ({ html }) });

    vi.resetModules();
    const { initialize } = await import(`../../../resources/js/pages/courses/features/${feature}/index.js`);

    await initialize();
    await tick();

    return fetch;
};

beforeEach(() => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    window.history.replaceState({}, "", "/parent/children/2/courses/1/intro");
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = "";
    delete window.coursesConfig;
});

// The first import of a tab script pulls in a large module graph; on a busy machine that can pass
// the 5 second default.
describe("the tab scripts with a parent's fragments", { timeout: 30000 }, () => {
    for (const feature of Object.keys(fragments)) {
        it(`${feature}: asks the parent's address and shows the fragment, not the error screen`, async () => {
            const fetch = await load(feature);
            const content = document.querySelector("#course-content");

            expect(fetch.mock.calls[0][0]).toContain(`${api}/${feature}`);
            expect(content.querySelector(`[data-marker="parent-${feature}"]`)).not.toBeNull();
            expect(content.textContent).not.toContain("We couldn't load");
            expect(console.error).not.toHaveBeenCalled();
        });
    }

    it("grades: the report button downloads from the parent's own report address", async () => {
        const fetch = await load("grades");

        document.querySelector("[data-my-grade-report]").click();
        await tick();

        const urls = fetch.mock.calls.map((call) => String(call[0]));

        expect(urls).toContain(`${api}/grades/report/mine?format=pdf`);
    });

    it("grades: with the grades not final there is no button and nothing breaks", async () => {
        await load("grades", '<div data-marker="parent-grades"><p>Grades are not published yet</p></div>');

        expect(document.querySelector("[data-my-grade-report]")).toBeNull();
        expect(document.querySelector("#course-content").textContent).not.toContain("We couldn't load");
    });

    it("announcements: a calendar link's ?announcement= is accepted and the post is on the page", async () => {
        window.history.replaceState({}, "", "/parent/children/2/courses/1/intro/announcements?announcement=7");

        // jsdom has no scrolling; a browser does.
        const scroll = vi.fn();
        window.HTMLElement.prototype.scrollIntoView = scroll;

        const fetch = await load("announcements");

        expect(fetch).toHaveBeenCalled();
        expect(scroll).toHaveBeenCalled();
        expect(document.querySelector('[data-announcement-card][data-announcement-id="7"]')).not.toBeNull();
        expect(window.location.search).toBe("");
    });
});
