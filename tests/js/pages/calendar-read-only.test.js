import { afterEach, describe, expect, it, vi } from "vitest";

/**
 * The calendar page for a parent is read-only: it shows what is on each day
 * and offers nothing to add, edit or delete. A student keeps the add
 * actions, so the same script serves both.
 */

const events = [
    { kind: "assessment", id: 5, date: "2026-11-10", time: "5:00 PM", title: "Quiz Three", course: "IT101 - A", offering_id: 1, tone: "warning", detail: "Quiz" },
];

const mount = async (role) => {
    document.body.innerHTML = `
        <div id="calendar-app"
             data-today="2026-11-10"
             data-role="${role}"
             data-api-base="/${role}/api/calendar"
             data-courses-api-base="/${role}/api/courses"
             data-initial-events='${JSON.stringify(events)}'
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

describe("calendar for a parent", () => {
    it("opens a day to its list and offers nothing to add", async () => {
        await mount("parent");

        document.querySelector('[data-calendar-cell="2026-11-10"]').click();

        expect(modalBody().textContent).toContain("Quiz Three");
        expect(modalBody().querySelector("[data-calendar-action]")).toBeNull();
        expect(modalBody().querySelector("[data-calendar-edit-deadline]")).toBeNull();
    });

    it("says so when a day is empty, still with nothing to add", async () => {
        await mount("parent");

        document.querySelector('[data-calendar-cell="2026-11-12"]').click();

        expect(modalBody().textContent).toContain("Nothing on this day");
        expect(modalBody().querySelector("[data-calendar-action]")).toBeNull();
    });

    it("has no Add button in the day view", async () => {
        vi.spyOn(window, "fetch").mockResolvedValue({ ok: true, json: async () => ({ events }) });

        await mount("parent");
        document.querySelector('[data-calendar-view="day"]').click();
        await vi.waitFor(() => expect(document.querySelector("[data-calendar-label]").textContent).toBe("November 10, 2026"));

        expect(document.querySelector("#calendar-body").textContent).toContain("Quiz Three");
        expect(document.querySelector("[data-calendar-add]")).toBeNull();
    });
});

describe("calendar for a student", () => {
    it("still offers to add a reminder", async () => {
        await mount("student");

        document.querySelector('[data-calendar-cell="2026-11-10"]').click();

        expect(modalBody().querySelector('[data-calendar-action="add-reminder"]')).not.toBeNull();
    });

    it("still has the Add button in the day view", async () => {
        vi.spyOn(window, "fetch").mockResolvedValue({ ok: true, json: async () => ({ events }) });

        await mount("student");
        document.querySelector('[data-calendar-view="day"]').click();
        await vi.waitFor(() => expect(document.querySelector("[data-calendar-label]").textContent).toBe("November 10, 2026"));

        expect(document.querySelector("[data-calendar-add]")).not.toBeNull();
    });
});
