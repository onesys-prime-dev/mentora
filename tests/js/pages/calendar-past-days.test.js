import { afterEach, describe, expect, it, vi } from "vitest";

/**
 * A student cannot add to a day that has passed: in the month view the tile
 * of yesterday and earlier does nothing when clicked, and the day view of such
 * a day has no Add button. Today and later keep both.
 */

const mount = async (role) => {
    document.body.innerHTML = `
        <div id="calendar-app"
             data-today="2026-11-10"
             data-role="${role}"
             data-api-base="/${role}/api/calendar"
             data-courses-api-base="/${role}/api/courses"
             data-initial-events='[]'
             data-courses="[]"
             data-assessment-types="[]">
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

describe("calendar days that have passed, for a student", () => {
    it("does nothing when the tile of yesterday or earlier is clicked", async () => {
        await mount("student");

        document.querySelector('[data-calendar-cell="2026-11-09"]').click();
        document.querySelector('[data-calendar-cell="2026-11-01"]').click();

        expect(modalBody().querySelector("[data-calendar-action]")).toBeNull();
        expect(document.querySelector('[data-calendar-cell="2026-11-09"]').hasAttribute("data-calendar-past")).toBe(true);
    });

    it("still opens today and later days", async () => {
        await mount("student");

        document.querySelector('[data-calendar-cell="2026-11-10"]').click();
        expect(modalBody().querySelector('[data-calendar-action="add-reminder"]')).not.toBeNull();

        document.querySelector('[data-calendar-cell="2026-11-11"]').click();
        expect(modalBody().querySelector('[data-calendar-action="add-reminder"]')).not.toBeNull();
    });

    it("has no Add button in the day view of a past day, and has one today", async () => {
        vi.spyOn(window, "fetch").mockResolvedValue({ ok: true, json: async () => ({ events: [] }) });

        await mount("student");
        document.querySelector('[data-calendar-view="day"]').click();
        await vi.waitFor(() => expect(document.querySelector("[data-calendar-label]").textContent).toBe("November 10, 2026"));

        expect(document.querySelector("[data-calendar-add]")).not.toBeNull();

        document.querySelector('[data-calendar-nav="prev"]').click();
        await vi.waitFor(() => expect(document.querySelector("[data-calendar-label]").textContent).toBe("November 9, 2026"));

        expect(document.querySelector("[data-calendar-add]")).toBeNull();
    });
});
