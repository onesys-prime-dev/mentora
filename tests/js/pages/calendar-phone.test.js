import { afterEach, describe, expect, it, vi } from "vitest";

/**
 * Below md a month cell shows coloured dots (one per kind of event, at most three) instead of the title
 * chips; the chips come back from md. Both are in the markup and the breakpoint classes pick one, so this
 * reads the classes rather than a media query.
 */

const mount = async (events) => {
    document.body.innerHTML = `
        <div id="calendar-app"
             data-today="2026-11-10"
             data-role="student"
             data-api-base="/student/api/calendar"
             data-courses-api-base="/student/api/courses"
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

    return import("../../../resources/js/pages/calendar/calendar.js");
};

afterEach(() => {
    document.body.innerHTML = "";
    document.body.className = "";
    vi.restoreAllMocks();
    delete window.matchMedia;
});

/** A window narrower than md (jsdom has no matchMedia of its own). */
const asPhone = () => {
    window.matchMedia = (query) => ({ matches: query === "(width < 48rem)", media: query, addEventListener() {}, removeEventListener() {} });
};

const modal = () => document.getElementById("calendar-modal");
const key = (target, name, shiftKey = false) => target.dispatchEvent(new KeyboardEvent("keydown", { key: name, shiftKey, bubbles: true }));

const event = (kind, id, title = `${kind} ${id}`) => ({ kind, id, title, date: "2026-11-12", time: "9:00 AM" });

describe("eventDots", () => {
    it("eventDots gives one dot per kind, at most three", async () => {
        const { eventDots } = await mount([]);
        const holder = document.createElement("div");

        holder.innerHTML = eventDots([event("assessment", 1), event("assessment", 2), event("reminder", 3), event("announcement", 4), event("assessment", 5)]);
        const kinds = [...holder.querySelectorAll("[data-calendar-dot]")].map((dot) => dot.dataset.calendarDot);

        expect(kinds).toEqual(["assessment", "reminder", "announcement"]);
        expect(holder.querySelector('[data-calendar-dot="assessment"]').className).toContain("bg-[#946200]");
        expect(holder.querySelector('[data-calendar-dot="reminder"]').className).toContain("bg-emerald-600");

        holder.innerHTML = eventDots([]);
        expect(holder.querySelectorAll("[data-calendar-dot]")).toHaveLength(0);

        // An unknown kind is drawn as a reminder (as its chip is) and never makes a fourth dot.
        holder.innerHTML = eventDots([event("assessment", 1), event("announcement", 2), event("reminder", 3), event("other", 4)]);
        expect(holder.querySelectorAll("[data-calendar-dot]")).toHaveLength(3);
    });
});

describe("month view on a phone", () => {
    it("month cells show no chips below md", async () => {
        await mount([event("assessment", 1, "Quiz 3"), event("announcement", 2, "Midterm")]);
        const cell = document.querySelector('[data-calendar-cell="2026-11-12"]');

        const chips = cell.querySelector("[data-calendar-chips]");
        expect(chips.textContent).toContain("Quiz 3");
        expect(chips.classList.contains("hidden")).toBe(true);
        expect(chips.classList.contains("md:flex")).toBe(true);

        const dots = cell.querySelector("[data-calendar-dots]");
        expect(dots.classList.contains("md:hidden")).toBe(true);
        expect(dots.querySelectorAll("[data-calendar-dot]")).toHaveLength(2);
        // The dots say nothing to a screen reader, so the cell names its events.
        expect(dots.querySelector(".sr-only").textContent).toContain("2 events");

        // Phone height first, the desktop height from md.
        expect(cell.className).toMatch(/(^|\s)min-h-14(\s|$)/);
        expect(cell.className).toContain("md:min-h-[96px]");

        // A day with nothing on it has no dots row.
        expect(document.querySelector('[data-calendar-cell="2026-11-13"] [data-calendar-dots]')).toBeNull();
    });
});

describe("the day sheet's dialog contract", () => {
    it("moves focus in, traps Tab, closes on Escape and gives focus back to the cell", async () => {
        await mount([]);
        const cell = document.querySelector('[data-calendar-cell="2026-11-12"]');
        const close = document.querySelector("[data-calendar-modal-close]");

        cell.focus();
        cell.click();

        expect(modal().classList.contains("hidden")).toBe(false);
        expect(document.activeElement).toBe(close);
        expect(document.body.classList.contains("overflow-hidden")).toBe(true);

        // Tab from the last control wraps to the first; Shift+Tab from the first wraps to the last.
        const last = [...modal().querySelectorAll("button")].at(-1);
        last.focus();
        key(last, "Tab");
        expect(document.activeElement).toBe(close);
        key(close, "Tab", true);
        expect(document.activeElement).toBe(last);

        key(last, "Escape");
        expect(modal().classList.contains("hidden")).toBe(true);
        expect(document.body.classList.contains("overflow-hidden")).toBe(false);
        expect(document.activeElement).toBe(cell);
    });

    it("gives focus back after the backdrop closes it, and keeps the first opener across steps", async () => {
        await mount([]);
        const cell = document.querySelector('[data-calendar-cell="2026-11-13"]');

        cell.focus();
        cell.click();
        // The next step (the reminder form) reuses the open shell.
        modal().querySelector('[data-calendar-action="add-reminder"]').click();
        expect(modal().querySelector('[data-calendar-form="reminder"]')).not.toBeNull();

        modal().click();
        expect(modal().classList.contains("hidden")).toBe(true);
        expect(document.activeElement).toBe(cell);
    });

    it("keeps focus in the sheet when the next step replaces the body, so Escape still closes it", async () => {
        await mount([]);
        const cell = document.querySelector('[data-calendar-cell="2026-11-13"]');

        cell.focus();
        cell.click();
        const action = modal().querySelector('[data-calendar-action="add-reminder"]');
        action.focus();
        action.click();

        expect(document.activeElement).toBe(document.querySelector("[data-calendar-modal-close]"));

        key(document.activeElement, "Escape");
        expect(modal().classList.contains("hidden")).toBe(true);
        expect(document.activeElement).toBe(cell);
    });
});

describe("a past day on a phone", () => {
    it("opens its Day view, listing what was on it, and still offers no Add", async () => {
        asPhone();
        vi.spyOn(window, "fetch").mockResolvedValue({
            ok: true,
            json: async () => ({ events: [{ ...event("assessment", 1, "Lab 2"), date: "2026-11-09" }] }),
        });

        await mount([{ ...event("assessment", 1, "Lab 2"), date: "2026-11-09" }]);
        const past = document.querySelector('[data-calendar-cell="2026-11-09"]');
        expect(past.hasAttribute("aria-disabled")).toBe(false);

        past.click();
        await vi.waitFor(() => expect(document.querySelector("[data-calendar-label]").textContent).toBe("November 9, 2026"));

        expect(document.querySelector('[data-calendar-event="assessment:1"]').textContent).toContain("Lab 2");
        expect(document.querySelector("[data-calendar-add]")).toBeNull();
        expect(modal().classList.contains("hidden")).toBe(true);
    });

    it("stays inert from md up", async () => {
        await mount([]);
        const past = document.querySelector('[data-calendar-cell="2026-11-09"]');
        expect(past.getAttribute("aria-disabled")).toBe("true");

        past.click();
        expect(document.querySelector("[data-calendar-label]").textContent).toBe("November 2026");
        expect(modal().classList.contains("hidden")).toBe(true);
    });
});

describe("the date menu", () => {
    it("lists an announcement with a url as a link into its course", async () => {
        await mount([
            { ...event("announcement", 2, "Midterm coverage"), url: "/student/courses/1/IT101-A/announcements" },
            event("assessment", 3, "Quiz 3"),
        ]);

        document.querySelector('[data-calendar-cell="2026-11-12"]').click();

        const link = modal().querySelector("a[data-calendar-menu-link]");
        expect(link.getAttribute("href")).toBe("/student/courses/1/IT101-A/announcements");
        expect(link.textContent).toContain("Midterm coverage");
        // An assessment has no link in the read-only list.
        expect(modal().querySelectorAll("a[data-calendar-menu-link]")).toHaveLength(1);
    });
});
