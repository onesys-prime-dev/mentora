import { csrfExpired } from "../../shared/csrf-expired.js";

/*
|--------------------------------------------------------------------------
| Calendar
|--------------------------------------------------------------------------
| A whole-app page (not a course tab), so there's no window.coursesConfig
| here — the two API bases and the initial day's events come from
| #calendar-app's data-* attributes instead (set server-side in
| pages/calendar/index.php).
|
| Rendering: PHP hands over events for the starting month so first paint
| has no loading flash; every view switch or prev/next after that re-fetches
| JSON and re-renders client-side. One #calendar-modal is reused for every
| action (Add Assessment / Add Announcement / Add Reminder / Edit Deadline)
| rather than four separate modals mostly hidden at once.
|--------------------------------------------------------------------------
*/

import { showLoadError } from "../../shared/spa/error-state.js";
import { startPageProgress } from "../../shared/spa/page-progress.js";
import { initializeComposerEditor, getComposerEditor, destroyComposerEditor } from "../courses/features/announcements/composer/tiptap.js";
import { initializeComposerToolbar } from "../courses/features/announcements/composer/toolbar.js";
import { serializeMessage } from "../courses/features/announcements/composer/serializer.js";
import { mountEmojiPicker } from "../courses/features/announcements/emoji-picker.js";

const DAY_NAMES = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const MONTH_NAMES = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
];

const KIND_META = {
    assessment: { dot: "bg-[#946200]", chip: "bg-[#FFF1D6] text-[#946200]" },
    announcement: { dot: "bg-brand", chip: "bg-[#E0E5F2] text-brand-dark" },
    reminder: { dot: "bg-emerald-600", chip: "bg-emerald-100 text-emerald-700" },
};

let root, apiBase, coursesApiBase, role, courses, assessmentTypes;

// What the person's institution lets them change (CourseAbilities): none when the page does not say.
let abilities = {};

let view = "month";
let cursor; // the date driving the visible period
let eventsByDate = new Map();

const modulesCache = {};

initialize();

function initialize()
{
    root = document.getElementById("calendar-app");

    if (!root) {
        return;
    }

    role = root.dataset.role;
    apiBase = root.dataset.apiBase;
    coursesApiBase = root.dataset.coursesApiBase;
    courses = JSON.parse(root.dataset.courses || "[]");
    assessmentTypes = JSON.parse(root.dataset.assessmentTypes || "[]");
    abilities = JSON.parse(root.dataset.abilities || "{}");

    cursor = parseDate(root.dataset.today);

    setEvents(JSON.parse(root.dataset.initialEvents || "[]"));

    bindToolbar();
    bindModal();

    render();
}

/** Instructors and chairpersons both manage deadlines and announcements from the calendar. */
function isCourseStaff()
{
    return role === "instructor" || role === "chairperson";
}

/** Adding an assessment and moving a deadline need courses.manage. */
function canChangeContent()
{
    return isCourseStaff() && abilities.content === true;
}

/** Adding an announcement needs announcements.manage. */
function canAnnounce()
{
    return isCourseStaff() && abilities.announcements === true;
}

/** A parent only looks: nothing can be added, edited or deleted from their calendar. */
function isReadOnly()
{
    return role === "parent";
}

/*
|--------------------------------------------------------------------------
| Date helpers
|--------------------------------------------------------------------------
*/

function pad2(n)
{
    return String(n).padStart(2, "0");
}

function isoDate(date)
{
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function parseDate(isoString)
{
    const [y, m, d] = isoString.split("-").map(Number);
    return new Date(y, m - 1, d);
}

/** Mon=0 .. Sun=6, matching how the rest of the app orders a week. */
function mondayIndex(date)
{
    return (date.getDay() + 6) % 7;
}

function sameDay(a, b)
{
    return isoDate(a) === isoDate(b);
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/

function setEvents(events)
{
    eventsByDate = new Map();

    events.forEach((event) => {

        if (!eventsByDate.has(event.date)) {
            eventsByDate.set(event.date, []);
        }

        eventsByDate.get(event.date).push(event);

    });
}

function rangeForView()
{
    if (view === "day") {

        const from = isoDate(cursor);

        return { from: `${from} 00:00:00`, to: `${from} 23:59:59` };

    }

    if (view === "year") {

        return {
            from: `${cursor.getFullYear()}-01-01 00:00:00`,
            to: `${cursor.getFullYear()}-12-31 23:59:59`,
        };

    }

    // month: the visible grid runs from the Monday on/before the 1st to the
    // Sunday on/after the last day, so events on those spillover days still
    // show up on the cells that display them.
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);

    const from = new Date(first);
    from.setDate(from.getDate() - mondayIndex(first));

    const to = new Date(last);
    to.setDate(to.getDate() + (6 - mondayIndex(last)));

    return { from: `${isoDate(from)} 00:00:00`, to: `${isoDate(to)} 23:59:59` };
}

let latestFetch = 0;

async function fetchAndRender()
{
    const { from, to } = rangeForView();

    const body = document.getElementById("calendar-body");

    const fetchId = ++latestFetch;

    body.setAttribute("aria-busy", "true");

    // The grid keeps its shape between months, so the current one stays up
    // and only dims if the fetch is slow; fast loads show nothing extra.
    const dimTimer = setTimeout(() => {
        if (fetchId === latestFetch) {
            body.classList.add("opacity-60", "pointer-events-none", "transition-opacity");
        }
    }, 150);

    let data;

    try {

        const response = await fetch(
            `${apiBase}/events?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
            { headers: defaultHeaders() },
        );

        if (!response.ok) {
            throw new Error("Unable to load calendar events.");
        }

        data = await response.json();

    }

    catch (error) {

        console.error(error);

        // A newer navigation already owns the calendar; its result wins.
        if (fetchId !== latestFetch) {
            return;
        }

        clearTimeout(dimTimer);
        body.classList.remove("opacity-60", "pointer-events-none", "transition-opacity");
        body.removeAttribute("aria-busy");

        showLoadError(body, "the calendar", fetchAndRender);

        return;

    }

    // A slower, older request must not overwrite a newer month/view.
    if (fetchId !== latestFetch) {
        return;
    }

    clearTimeout(dimTimer);
    body.classList.remove("opacity-60", "pointer-events-none", "transition-opacity");
    body.removeAttribute("aria-busy");

    setEvents(data.events || []);

    render();
}

function defaultHeaders()
{
    return { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" };
}

function jsonHeaders()
{
    return { ...defaultHeaders(), "Content-Type": "application/json" };
}

/*
|--------------------------------------------------------------------------
| Toolbar
|--------------------------------------------------------------------------
*/

function setActiveViewButton(activeView)
{
    document.querySelectorAll("[data-calendar-view]").forEach((button) => {

        const isActive = button.dataset.calendarView === activeView;

        button.classList.toggle("bg-brand", isActive);
        button.classList.toggle("text-white", isActive);

    });
}

function bindToolbar()
{
    document.querySelectorAll("[data-calendar-view]").forEach((button) => {

        button.addEventListener("click", () => {

            view = button.dataset.calendarView;

            setActiveViewButton(view);

            fetchAndRender();

        });

    });

    document.querySelector('[data-calendar-nav="prev"]')
        ?.addEventListener("click", () => { step(-1); });

    document.querySelector('[data-calendar-nav="next"]')
        ?.addEventListener("click", () => { step(1); });

    document.querySelector("[data-calendar-today]")
        ?.addEventListener("click", () => {
            cursor = parseDate(root.dataset.today);
            fetchAndRender();
        });
}

function step(direction)
{
    if (view === "day") {
        cursor.setDate(cursor.getDate() + direction);
    } else if (view === "year") {
        cursor.setFullYear(cursor.getFullYear() + direction);
    } else {
        cursor = new Date(cursor.getFullYear(), cursor.getMonth() + direction, 1);
    }

    fetchAndRender();
}

/*
|--------------------------------------------------------------------------
| Render — dispatch
|--------------------------------------------------------------------------
*/

function render()
{
    const label = document.querySelector("[data-calendar-label]");

    if (view === "day") {
        label.textContent = `${MONTH_NAMES[cursor.getMonth()]} ${cursor.getDate()}, ${cursor.getFullYear()}`;
        renderDay();
    } else if (view === "year") {
        label.textContent = String(cursor.getFullYear());
        renderYear();
    } else {
        label.textContent = `${MONTH_NAMES[cursor.getMonth()]} ${cursor.getFullYear()}`;
        renderMonth();
    }
}

/*
|--------------------------------------------------------------------------
| Month view
|--------------------------------------------------------------------------
*/

function renderMonth()
{
    const body = document.getElementById("calendar-body");

    const today = parseDate(root.dataset.today);

    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);

    const gridStart = new Date(first);
    gridStart.setDate(gridStart.getDate() - mondayIndex(first));

    let cellsHtml = "";

    for (let i = 0; i < 42; i++) {

        const cellDate = new Date(gridStart);
        cellDate.setDate(cellDate.getDate() + i);

        const inMonth = cellDate.getMonth() === cursor.getMonth();

        const dateStr = isoDate(cellDate);

        const events = eventsByDate.get(dateStr) || [];

        const visible = events.slice(0, 3);

        const overflow = events.length - visible.length;

        cellsHtml += `

        <button
            type="button"
            data-calendar-cell="${dateStr}"
            class="flex h-full min-h-[96px] flex-col items-stretch gap-1 border-b border-r border-[#A9A9A9]/20 p-2 text-left transition hover:bg-brand/5 ${inMonth ? "" : "bg-black/[0.02]"}">

            <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold ${sameDay(cellDate, today) ? "bg-brand text-white" : inMonth ? "text-[#646464]" : "text-[#A9A9A9]"}">
                ${cellDate.getDate()}
            </span>

            <div class="flex flex-1 flex-col gap-0.5 overflow-hidden">
                ${visible.map(eventChip).join("")}
                ${overflow > 0 ? `<span class="text-[11px] text-[#878787]">+${overflow} more</span>` : ""}
            </div>

        </button>

        `;

    }

    body.innerHTML = `

    <div class="grid grid-cols-7 border-t border-l border-[#A9A9A9]/20">

        ${DAY_NAMES.map((day) => `
            <div class="border-b border-r border-[#A9A9A9]/20 bg-black/[0.02] px-2 py-2 text-center text-xs font-bold uppercase text-[#878787]">
                ${day}
            </div>
        `).join("")}

        ${cellsHtml}

    </div>

    `;

    bindCellClicks();
}

function eventChip(event)
{
    const meta = KIND_META[event.kind] || KIND_META.reminder;

    // Announcements link straight into their course tab. The cell itself is a
    // <button> (an <a> can't nest in one), so the chip is a span that
    // navigates on click — see bindCellClicks().
    const link = event.kind === "announcement" && event.url
        ? ` role="link" data-calendar-chip-url="${escapeHtml(event.url)}"`
        : "";

    return `
    <span${link} class="truncate rounded px-1.5 py-0.5 text-[11px] font-semibold ${meta.chip} ${link ? "cursor-pointer hover:underline" : ""}" title="${escapeHtml(event.title)}">
        ${escapeHtml(event.title)}
    </span>
    `;
}

function bindCellClicks()
{
    document.querySelectorAll("[data-calendar-chip-url]").forEach((chip) => {

        chip.addEventListener("click", (e) => {
            e.stopPropagation();
            startPageProgress();
            window.location.href = chip.dataset.calendarChipUrl;
        });

    });

    document.querySelectorAll("[data-calendar-cell]").forEach((cell) => {

        cell.addEventListener("click", () => {
            openDateMenu(cell.dataset.calendarCell);
        });

    });
}

/*
|--------------------------------------------------------------------------
| Day view
|--------------------------------------------------------------------------
*/

function renderDay()
{
    const body = document.getElementById("calendar-body");

    const dateStr = isoDate(cursor);

    const events = (eventsByDate.get(dateStr) || [])
        .slice()
        .sort((a, b) => a.time.localeCompare(b.time));

    body.innerHTML = `

    <div class="flex flex-col gap-4 p-6">

        ${isReadOnly() ? "" : `
        <button
            type="button"
            data-calendar-add="${dateStr}"
            class="inline-flex w-fit items-center gap-2 rounded-4xl bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-dark">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
            </svg>
            Add to this day
        </button>
        `}

        ${events.length === 0 ? `
            <div class="flex flex-col items-center justify-center gap-1 py-10 text-center">
                <span class="text-sm font-bold text-[#646464]">Nothing on this day</span>
                <span class="text-xs text-[#878787]">${isReadOnly() ? "Assessments and announcements will show up here." : "Assessments, announcements and reminders will show up here."}</span>
            </div>
        ` : events.map(dayRow).join("")}

    </div>

    `;

    document.querySelector("[data-calendar-add]")
        ?.addEventListener("click", (e) => { openDateMenu(e.currentTarget.dataset.calendarAdd); });

    bindEventRowClicks();
}

function dayRow(event)
{
    const meta = KIND_META[event.kind] || KIND_META.reminder;

    return `

    <div
        data-calendar-event="${event.kind}:${event.id}"
        class="group flex cursor-pointer items-start gap-4 rounded-2xl border border-[#A9A9A9]/50 p-4 transition hover:border-brand/50 hover:bg-brand/5">

        <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${meta.dot}"></span>

        <div class="flex min-w-0 flex-1 flex-col">
            <div class="flex items-center gap-2">
                <span class="font-bold text-[#646464]">${escapeHtml(event.title)}</span>
                <span class="text-xs text-[#878787]">${escapeHtml(event.time)}</span>
            </div>
            ${event.course ? `<span class="text-xs text-[#878787]">${escapeHtml(event.course)}</span>` : ""}
            ${event.detail ? `<span class="mt-1 text-sm text-[#646464]">${escapeHtml(event.detail)}</span>` : ""}
        </div>

    </div>

    `;
}

function bindEventRowClicks()
{
    document.querySelectorAll("[data-calendar-event]").forEach((row) => {

        row.addEventListener("click", () => {

            const [kind, id] = row.dataset.calendarEvent.split(":");

            const event = findEvent(kind, Number(id));

            if (event?.kind === "announcement" && event.url) {
                startPageProgress();
                window.location.href = event.url;
                return;
            }

            if (event) {
                openEventDetail(event);
            }

        });

    });
}

function findEvent(kind, id)
{
    for (const events of eventsByDate.values()) {
        const match = events.find((e) => e.kind === kind && e.id === id);
        if (match) return match;
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| Year view
|--------------------------------------------------------------------------
*/

function renderYear()
{
    const body = document.getElementById("calendar-body");

    const today = parseDate(root.dataset.today);

    let monthsHtml = "";

    for (let m = 0; m < 12; m++) {

        const first = new Date(cursor.getFullYear(), m, 1);

        const gridStart = new Date(first);
        gridStart.setDate(gridStart.getDate() - mondayIndex(first));

        let daysHtml = "";

        for (let i = 0; i < 42; i++) {

            const cellDate = new Date(gridStart);
            cellDate.setDate(cellDate.getDate() + i);

            const inMonth = cellDate.getMonth() === m;

            const dateStr = isoDate(cellDate);

            const hasEvents = inMonth && eventsByDate.has(dateStr);

            daysHtml += `

            <button
                type="button"
                data-calendar-mini-day="${dateStr}"
                ${inMonth ? "" : "tabindex=\"-1\" aria-hidden=\"true\""}
                class="relative flex h-6 w-6 items-center justify-center rounded-full text-[10px] ${inMonth ? "text-[#646464]" : "invisible pointer-events-none"} ${sameDay(cellDate, today) && inMonth ? "bg-brand text-white" : ""}">
                ${inMonth ? cellDate.getDate() : ""}
                ${hasEvents ? '<span class="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-[#946200]"></span>' : ""}
            </button>

            `;

        }

        monthsHtml += `

        <div class="flex flex-col gap-2 rounded-2xl border border-[#A9A9A9]/50 p-4">

            <button
                type="button"
                data-calendar-mini-month="${m}"
                class="text-left text-sm font-bold text-brand transition hover:underline">
                ${MONTH_NAMES[m]}
            </button>

            <div class="grid grid-cols-7 gap-1">
                ${daysHtml}
            </div>

        </div>

        `;

    }

    body.innerHTML = `<div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">${monthsHtml}</div>`;

    document.querySelectorAll("[data-calendar-mini-month]").forEach((button) => {
        button.addEventListener("click", () => {
            cursor = new Date(cursor.getFullYear(), Number(button.dataset.calendarMiniMonth), 1);
            view = "month";
            setActiveViewButton("month");
            fetchAndRender();
        });
    });

    document.querySelectorAll("[data-calendar-mini-day]").forEach((button) => {
        button.addEventListener("click", () => {
            cursor = parseDate(button.dataset.calendarMiniDay);
            view = "day";
            setActiveViewButton("day");
            fetchAndRender();
        });
    });
}

/*
|--------------------------------------------------------------------------
| Modal shell
|--------------------------------------------------------------------------
*/

function bindModal()
{
    document.querySelector("[data-calendar-modal-close]")
        ?.addEventListener("click", closeModal);

    document.getElementById("calendar-modal")
        ?.addEventListener("click", (e) => {
            if (e.target.id === "calendar-modal") closeModal();
        });
}

function openModal(title)
{
    document.querySelector("[data-calendar-modal-title]").textContent = title;
    document.getElementById("calendar-modal").classList.remove("hidden");
}

function closeModal()
{
    destroyComposerEditor();
    document.getElementById("calendar-modal").classList.add("hidden");
    document.querySelector("[data-calendar-modal-body]").innerHTML = "";
}

function modalBody()
{
    return document.querySelector("[data-calendar-modal-body]");
}

/*
|--------------------------------------------------------------------------
| Date-click menu
|--------------------------------------------------------------------------
| Instructor: create actions + any existing events that day (assessments
| get an inline "Edit deadline" shortcut). Student: personal reminders
| only, plus a read-only list of what's already on the day.
*/

function openDateMenu(dateStr)
{
    const events = eventsByDate.get(dateStr) || [];

    const changesContent = canChangeContent();

    const actionButton = (action, label, iconPath) => `
        <button
            type="button"
            data-calendar-action="${action}"
            data-calendar-action-date="${dateStr}"
            class="flex items-center gap-3 rounded-2xl border border-[#A9A9A9]/50 p-4 text-left transition hover:border-brand/50 hover:bg-brand/5">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand/10 text-brand">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">${iconPath}</svg>
            </span>
            <span class="font-semibold text-[#646464]">${label}</span>
        </button>
    `;

    const plusIcon = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />';

    const actions = isReadOnly() ? "" : [
        changesContent ? actionButton("add-assessment", "Add Assessment", plusIcon) : "",
        canAnnounce() && dateStr >= isoDate(new Date()) ? actionButton("add-announcement", "Add Announcement", plusIcon) : "",
        actionButton("add-reminder", "Add Reminder", plusIcon),
    ].join("");

    const nothingToday = isReadOnly() && events.length === 0
        ? '<p class="text-sm text-[#646464]">Nothing on this day.</p>'
        : "";

    const assessmentEvents = events.filter((e) => e.kind === "assessment");

    const editLinks = changesContent && assessmentEvents.length > 0 ? `

        <div class="flex flex-col gap-2">
            <span class="text-xs font-bold uppercase text-[#878787]">Already due this day</span>
            ${assessmentEvents.map((event) => `
                <button
                    type="button"
                    data-calendar-edit-deadline="${event.id}"
                    data-calendar-edit-offering="${event.offering_id}"
                    class="flex items-center justify-between rounded-2xl border border-[#A9A9A9]/50 px-4 py-3 text-left text-sm transition hover:border-brand/50 hover:bg-brand/5">
                    <span class="truncate font-semibold text-[#646464]">${escapeHtml(event.title)}</span>
                    <span class="shrink-0 text-xs text-brand">Edit deadline →</span>
                </button>
            `).join("")}
        </div>

    ` : "";

    const readOnlyList = !changesContent && events.length > 0 ? `

        <div class="flex flex-col gap-2">
            <span class="text-xs font-bold uppercase text-[#878787]">On this day</span>
            ${events.map((event) => `
                <div class="rounded-2xl border border-[#A9A9A9]/50 px-4 py-3 text-sm">
                    <span class="font-semibold text-[#646464]">${escapeHtml(event.title)}</span>
                    <span class="block text-xs text-[#878787]">${escapeHtml(event.time)}${event.course ? " · " + escapeHtml(event.course) : ""}</span>
                </div>
            `).join("")}
        </div>

    ` : "";

    openModal(formatDateLong(dateStr));

    modalBody().innerHTML = `<div class="flex flex-col gap-3">${actions}${editLinks}${readOnlyList}${nothingToday}</div>`;

    modalBody().querySelectorAll("[data-calendar-action]").forEach((button) => {
        button.addEventListener("click", () => {
            openForm(button.dataset.calendarAction, button.dataset.calendarActionDate);
        });
    });

    modalBody().querySelectorAll("[data-calendar-edit-deadline]").forEach((button) => {
        button.addEventListener("click", () => {
            const event = assessmentEvents.find((e) => e.id === Number(button.dataset.calendarEditDeadline));
            if (event) openEditDeadlineForm(event);
        });
    });
}

function openEventDetail(event)
{
    if (event.kind === "assessment" && canChangeContent()) {
        openEditDeadlineForm(event);
        return;
    }

    openModal(event.title);

    modalBody().innerHTML = `
        <div class="flex flex-col gap-1 text-sm">
            ${event.course ? `<span class="text-[#878787]">${escapeHtml(event.course)}</span>` : ""}
            <span class="text-[#878787]">${escapeHtml(event.time)}</span>
            ${event.detail ? `<p class="mt-2 text-[#646464]">${escapeHtml(event.detail)}</p>` : ""}
        </div>
        ${event.kind === "reminder" ? `
            <button
                type="button"
                data-calendar-delete-reminder="${event.id}"
                class="self-start text-sm font-semibold text-red-600 transition hover:underline">
                Delete reminder
            </button>
        ` : ""}
    `;

    document.querySelector("[data-calendar-delete-reminder]")?.addEventListener("click", async (e) => {

        const id = e.currentTarget.dataset.calendarDeleteReminder;

        try {
            await fetch(`${apiBase}/reminders/${id}`, { method: "DELETE", headers: defaultHeaders() });
        } catch (error) {
            console.error(error);
        }

        closeModal();
        fetchAndRender();

    });
}

/*
|--------------------------------------------------------------------------
| Forms
|--------------------------------------------------------------------------
*/

function openForm(action, dateStr)
{
    if (action === "add-assessment") return openAssessmentForm(dateStr);
    if (action === "add-announcement") return openAnnouncementForm(dateStr);
    if (action === "add-reminder") return openReminderForm(dateStr);
}

function courseOptions()
{
    return courses.map((c) => `<option value="${c.offering_id}">${escapeHtml(c.course_code)} - ${escapeHtml(c.section_code)}</option>`).join("");
}

function feedback(message, isError = false)
{
    return `<p class="text-sm ${isError ? "text-red-600" : "text-emerald-600"}" data-calendar-form-feedback>${escapeHtml(message)}</p>`;
}

/* ---- Add Assessment (instructor) ---------------------------------------- */

function openAssessmentForm(dateStr)
{
    if (courses.length === 0) {
        openModal("Add Assessment");
        modalBody().innerHTML = feedback("You aren't teaching any sections yet.", true);
        return;
    }

    openModal("Add Assessment");

    modalBody().innerHTML = `

        <form data-calendar-form="assessment" class="flex flex-col gap-4">

            <label class="flex flex-col gap-1 text-sm">
                Course
                <select name="offering_id" data-calendar-course-select required class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                    ${courseOptions()}
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                Module
                <select name="module_id" data-calendar-module-select required class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                    <option>Loading…</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                Learning unit
                <select name="learning_unit_id" data-calendar-unit-select required class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2"></select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                Type
                <select name="assessment_type_id" required class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                    ${assessmentTypes.map((t) => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join("")}
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                Title
                <input type="text" name="title" required maxlength="150" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2" placeholder="e.g. Quiz 3 - Loops">
            </label>

            <div class="grid grid-cols-2 gap-4">

                <label class="flex flex-col gap-1 text-sm">
                    Due date
                    <input type="date" name="due_date" required value="${dateStr}" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                </label>

                <label class="flex flex-col gap-1 text-sm">
                    Due time
                    <input type="time" name="due_time" required value="23:59" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                </label>

            </div>

            <label class="flex flex-col gap-1 text-sm">
                Points
                <input type="number" name="total_points" required min="0" step="1" value="100" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
            </label>

            <p class="text-xs text-[#878787]">Created as a draft — publish it from the Assessments tab when it's ready.</p>

            <button type="submit" class="rounded-4xl bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-dark">
                Create
            </button>

        </form>

    `;

    loadModulesInto(courses[0].offering_id);

    modalBody().querySelector("[data-calendar-course-select]")
        .addEventListener("change", (e) => { loadModulesInto(e.target.value); });

    modalBody().querySelector('[data-calendar-form="assessment"]')
        .addEventListener("submit", submitAssessmentForm);
}

async function loadModulesInto(offeringId)
{
    const moduleSelect = modalBody().querySelector("[data-calendar-module-select]");

    const unitSelect = modalBody().querySelector("[data-calendar-unit-select]");

    moduleSelect.innerHTML = `<option>Loading…</option>`;

    unitSelect.innerHTML = "";

    if (!modulesCache[offeringId]) {

        try {

            const response = await fetch(`${coursesApiBase}/${offeringId}/modules/picker`, { headers: defaultHeaders() });

            const data = await response.json();

            modulesCache[offeringId] = data.modules || [];

        }

        catch (error) {

            console.error(error);

            moduleSelect.innerHTML = `<option>Unable to load modules</option>`;

            return;

        }

    }

    const modules = modulesCache[offeringId];

    if (modules.length === 0) {
        moduleSelect.innerHTML = `<option value="">No modules yet</option>`;
        return;
    }

    moduleSelect.innerHTML = modules.map((m) => `<option value="${m.id}">${escapeHtml(m.title)}</option>`).join("");

    fillUnits(modules[0]);

    moduleSelect.addEventListener("change", (e) => {
        const module = modules.find((m) => String(m.id) === e.target.value);
        if (module) fillUnits(module);
    });

}

function fillUnits(module)
{
    const unitSelect = modalBody().querySelector("[data-calendar-unit-select]");

    if (!unitSelect) return;

    unitSelect.innerHTML = module.learning_units.length === 0
        ? `<option value="">No units yet</option>`
        : module.learning_units.map((u) => `<option value="${u.id}">${escapeHtml(u.title)}</option>`).join("");
}

async function submitAssessmentForm(e)
{
    e.preventDefault();

    const form = e.currentTarget;

    const offeringId = form.offering_id.value;

    const moduleId = form.module_id.value;

    const unitId = form.learning_unit_id.value;

    if (!moduleId || !unitId) {
        return;
    }

    const submitButton = form.querySelector('button[type="submit"]');

    submitButton.disabled = true;

    const body = new FormData();

    body.append("assessment_type_id", form.assessment_type_id.value);
    body.append("title", form.title.value);
    body.append("due_date", `${form.due_date.value} ${form.due_time.value}:00`);
    body.append("total_points", form.total_points.value);

    try {

        const response = await fetch(
            `${coursesApiBase}/${offeringId}/modules/${moduleId}/learning-units/${unitId}/assessments`,
            { method: "POST", headers: defaultHeaders(), body },
        );

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || "Unable to create the assessment.");
        }

        closeModal();

        fetchAndRender();

    }

    catch (error) {

        console.error(error);

        form.insertAdjacentHTML("afterbegin", feedback(error.message || "Something went wrong.", true));

        submitButton.disabled = false;

    }
}

/* ---- Add Announcement (instructor) --------------------------------------- */

function openAnnouncementForm(dateStr)
{
    openModal("Post Announcement");

    const template = document.getElementById("calendar-announcement-composer");

    if (courses.length === 0 || !template) {
        modalBody().innerHTML = feedback("You aren't teaching any sections yet.", true);
        return;
    }

    modalBody().innerHTML = `

        <form data-calendar-form="announcement" class="flex flex-col gap-4">

            <label class="flex flex-col gap-1 text-sm">
                Course
                <select name="offering_id" required class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                    ${courseOptions()}
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                Date
                <input type="date" name="date" required value="${dateStr}" min="${isoDate(new Date())}" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
            </label>

        </form>

    `;

    const form = modalBody().querySelector('[data-calendar-form="announcement"]');

    form.appendChild(template.content.cloneNode(true));

    const composer = form.querySelector("[data-announcement-composer]");
    const subjectInput = composer.querySelector("[data-composer-subject]");
    const submitButton = composer.querySelector("[data-composer-submit]");

    // The page's own composer, minus discarding (the modal closes instead).
    composer.querySelector("[data-composer-dismiss]")?.classList.add("hidden");
    composer.querySelector("#announcement-editor")?.classList.add("min-h-[180px]");

    initializeComposerEditor("announcement-editor");
    initializeComposerToolbar(composer);

    const updateState = () => {
        submitButton.disabled = subjectInput.value.trim() === "";
    };

    subjectInput.addEventListener("input", updateState);
    getComposerEditor()?.on("update", updateState);

    const emojiPanel = composer.querySelector("[data-composer-emoji-panel]");

    composer.querySelector("[data-composer-emoji-toggle]")?.addEventListener("click", (event) => {

        event.preventDefault();

        const wasHidden = emojiPanel.classList.contains("hidden");

        emojiPanel.classList.toggle("hidden", !wasHidden);

        if (wasHidden) {
            mountEmojiPicker(emojiPanel, (emoji) => {
                getComposerEditor()?.chain().focus().insertContent(emoji).run();
            });
        }

    });

    // Attachments: same endpoint, limit and chips as the course page, uploaded
    // against whichever course is chosen in the dropdown.
    const attachmentsBox = composer.querySelector("[data-composer-attachments]");
    const fileInput = composer.querySelector("[data-composer-file-input]");
    const attachments = [];

    form._attachments = attachments;

    const renderAttachments = () => {

        attachmentsBox.innerHTML = "";

        attachments.forEach((file, index) => {

            const chip = document.createElement("div");
            chip.className = "flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs text-gray-600";

            const name = document.createElement("span");
            name.className = "max-w-[140px] truncate";
            name.textContent = file.original_filename;

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "text-[#767676] hover:text-red-500";
            remove.textContent = "×";
            remove.addEventListener("click", () => {
                attachments.splice(index, 1);
                renderAttachments();
            });

            chip.append(name, remove);
            attachmentsBox.appendChild(chip);

        });

    };

    composer.querySelector("[data-composer-attach]")?.addEventListener("click", () => fileInput?.click());

    fileInput?.addEventListener("change", async () => {

        for (const file of Array.from(fileInput.files ?? [])) {

            if (attachments.length >= 10) {
                alert("You can attach up to 10 files.");
                break;
            }

            try {

                const body = new FormData();
                body.append("file", file);

                const response = await fetch(`${coursesApiBase}/${form.offering_id.value}/announcements/attachments`, {
                    method: "POST",
                    headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
                    body,
                });

                const result = await response.json();

                if (!result.success) {
                    if (!csrfExpired(result)) alert(result.message || "Unable to upload that file.");
                    continue;
                }

                attachments.push(result);
                renderAttachments();

            } catch (error) {
                console.error(error);
                alert("Unable to upload that file.");
            }

        }

        fileInput.value = "";

    });

    form.addEventListener("submit", submitAnnouncementForm);
    submitButton.addEventListener("click", () => form.requestSubmit());

    updateState();
    subjectInput.focus();
}

async function submitAnnouncementForm(e)
{
    e.preventDefault();

    const form = e.currentTarget;

    const submitButton = form.querySelector("[data-composer-submit]");

    submitButton.disabled = true;

    try {

        const response = await fetch(`${apiBase}/announcements`, {
            method: "POST",
            headers: jsonHeaders(),
            body: JSON.stringify({
                offering_id: form.offering_id.value,
                date: form.date.value,
                subject: form.querySelector("[data-composer-subject]").value.trim(),
                message: JSON.stringify(serializeMessage(getComposerEditor())),
                attachment_file_ids: (form._attachments ?? []).map((file) => file.file_id),
            }),
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || "Unable to post the announcement.");
        }

        closeModal();

        fetchAndRender();

    }

    catch (error) {

        console.error(error);

        form.insertAdjacentHTML("afterbegin", feedback(error.message || "Something went wrong.", true));

        submitButton.disabled = false;

    }
}

/* ---- Add Reminder (anyone) ------------------------------------------------ */

function openReminderForm(dateStr)
{
    openModal("Add Reminder");

    modalBody().innerHTML = `

        <form data-calendar-form="reminder" class="flex flex-col gap-4">

            <label class="flex flex-col gap-1 text-sm">
                Title
                <input type="text" name="title" required maxlength="150" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2" placeholder="e.g. Bring rubric to class">
            </label>

            <div class="grid grid-cols-2 gap-4">

                <label class="flex flex-col gap-1 text-sm">
                    Date
                    <input type="date" name="remind_date" required value="${dateStr}" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                </label>

                <label class="flex flex-col gap-1 text-sm">
                    Time
                    <input type="time" name="remind_time" required value="09:00" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                </label>

            </div>

            ${courses.length > 0 ? `
                <label class="flex flex-col gap-1 text-sm">
                    Course (optional)
                    <select name="offering_id" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                        <option value="">None</option>
                        ${courseOptions()}
                    </select>
                </label>
            ` : ""}

            <label class="flex flex-col gap-1 text-sm">
                Note (optional)
                <textarea name="note" rows="3" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2"></textarea>
            </label>

            <button type="submit" class="rounded-4xl bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-dark">
                Add
            </button>

        </form>

    `;

    modalBody().querySelector('[data-calendar-form="reminder"]')
        .addEventListener("submit", submitReminderForm);
}

async function submitReminderForm(e)
{
    e.preventDefault();

    const form = e.currentTarget;

    const submitButton = form.querySelector('button[type="submit"]');

    submitButton.disabled = true;

    try {

        const response = await fetch(`${apiBase}/reminders`, {
            method: "POST",
            headers: jsonHeaders(),
            body: JSON.stringify({
                title: form.title.value,
                note: form.note.value,
                remind_at: `${form.remind_date.value} ${form.remind_time.value}:00`,
                offering_id: form.offering_id ? form.offering_id.value : null,
            }),
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || "Unable to add the reminder.");
        }

        closeModal();

        fetchAndRender();

    }

    catch (error) {

        console.error(error);

        form.insertAdjacentHTML("afterbegin", feedback(error.message || "Something went wrong.", true));

        submitButton.disabled = false;

    }
}

/* ---- Edit Deadline (instructor) ------------------------------------------ */

function openEditDeadlineForm(event)
{
    openModal(`Edit deadline — ${event.title}`);

    const [datePart, timePart] = event.date && event.time
        ? [event.date, to24Hour(event.time)]
        : ["", ""];

    modalBody().innerHTML = `

        <form data-calendar-form="edit-deadline" class="flex flex-col gap-4">

            <div class="grid grid-cols-2 gap-4">

                <label class="flex flex-col gap-1 text-sm">
                    Due date
                    <input type="date" name="due_date" required value="${datePart}" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                </label>

                <label class="flex flex-col gap-1 text-sm">
                    Due time
                    <input type="time" name="due_time" required value="${timePart}" class="rounded-lg border border-[#A9A9A9]/50 px-3 py-2">
                </label>

            </div>

            <button type="submit" class="rounded-4xl bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-dark">
                Save
            </button>

        </form>

    `;

    modalBody().querySelector('[data-calendar-form="edit-deadline"]')
        .addEventListener("submit", (e) => submitEditDeadlineForm(e, event));
}

/** "11:59 PM" -> "23:59", what a native time input needs as its value. */
function to24Hour(label)
{
    const [time, meridiem] = label.split(" ");

    let [hours, minutes] = time.split(":").map(Number);

    if (meridiem === "PM" && hours !== 12) hours += 12;
    if (meridiem === "AM" && hours === 12) hours = 0;

    return `${pad2(hours)}:${pad2(minutes)}`;
}

async function submitEditDeadlineForm(e, event)
{
    e.preventDefault();

    const form = e.currentTarget;

    const submitButton = form.querySelector('button[type="submit"]');

    submitButton.disabled = true;

    try {

        const response = await fetch(`${coursesApiBase}/${event.offering_id}/assessments/${event.id}`, {
            method: "PATCH",
            headers: jsonHeaders(),
            body: JSON.stringify({
                due_date: `${form.due_date.value} ${form.due_time.value}:00`,
            }),
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || "Unable to update the deadline.");
        }

        closeModal();

        fetchAndRender();

    }

    catch (error) {

        console.error(error);

        form.insertAdjacentHTML("afterbegin", feedback(error.message || "Something went wrong.", true));

        submitButton.disabled = false;

    }
}

/*
|--------------------------------------------------------------------------
| Misc
|--------------------------------------------------------------------------
*/

function formatDateLong(dateStr)
{
    const date = parseDate(dateStr);

    return `${MONTH_NAMES[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
}

function escapeHtml(value)
{
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
}
