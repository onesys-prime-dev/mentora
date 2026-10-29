import { afterEach, describe, expect, it, vi } from "vitest";

/**
 * The calendar offers an instructor only what their institution lets them do: adding an
 * assessment and moving a deadline need courses.manage ("content"), adding an announcement
 * needs announcements.manage ("announcements"). The server refuses the rest anyway; the page
 * just does not offer it.
 */

const events = [
    { kind: "assessment", id: 5, date: "2026-11-10", time: "5:00 PM", title: "Quiz Three", course: "IT101 - A", offering_id: 1, tone: "warning", detail: "Quiz" },
];

const mount = async (role, abilities) => {
    document.body.innerHTML = `
        <div id="calendar-app"
             data-today="2026-11-10"
             data-role="${role}"
             data-api-base="/${role}/api/calendar"
             data-courses-api-base="/${role}/api/courses"
             data-initial-events='${JSON.stringify(events)}'
             data-courses="[]"
             data-assessment-types="[]"
             ${abilities === undefined ? "" : `data-abilities='${JSON.stringify(abilities)}'`}>
            <button data-calendar-nav="prev"></button>
            <button data-calendar-nav="next"></button>
            <button data-calendar-today></button>
            <span data-calendar-label></span>
            <button data-calendar-view="day"></button>
            <button data-calendar-view="month"></button>
            <button data-calendar-view="year"></button>
            <div id="calendar-body"></div>
        </div>
        <div id="calendar-modal" class="hidden">
            <span data-calendar-modal-title></span>
            <button data-calendar-modal-close></button>
            <div data-calendar-modal-body></div>
        </div>`;

    vi.resetModules();
    await import("../../../resources/js/pages/calendar/calendar.js");
};

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = "";
});

const modalBody = () => document.querySelector("[data-calendar-modal-body]");

const openDay = () => document.querySelector('[data-calendar-cell="2026-11-10"]').click();

describe("calendar for an instructor", () => {
    it("offers assessments and deadlines without announcements", async () => {
        await mount("instructor", { content: true, grading: true, announcements: false, discussions: true });
        openDay();

        expect(modalBody().querySelector('[data-calendar-action="add-assessment"]')).not.toBeNull();
        expect(modalBody().querySelector("[data-calendar-edit-deadline]")).not.toBeNull();
        expect(modalBody().querySelector('[data-calendar-action="add-announcement"]')).toBeNull();
    });

    it("offers announcements without assessments or deadlines", async () => {
        await mount("instructor", { content: false, grading: true, announcements: true, discussions: true });
        openDay();

        expect(modalBody().querySelector('[data-calendar-action="add-announcement"]')).not.toBeNull();
        expect(modalBody().querySelector('[data-calendar-action="add-assessment"]')).toBeNull();
        expect(modalBody().querySelector("[data-calendar-edit-deadline]")).toBeNull();
    });

    it("offers neither without the abilities", async () => {
        await mount("chairperson");
        openDay();

        expect(modalBody().querySelector('[data-calendar-action="add-announcement"]')).toBeNull();
        expect(modalBody().querySelector('[data-calendar-action="add-assessment"]')).toBeNull();
        expect(modalBody().querySelector("[data-calendar-edit-deadline]")).toBeNull();
    });
});
