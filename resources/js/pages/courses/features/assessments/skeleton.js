import { clampRows, rememberRows, rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Assessments Skeleton
|--------------------------------------------------------------------------
|
| Shown on every page load of the tab, same as Overview/Modules — mirrors
| the list page's layout.
|
*/

/** Remembers each sidebar section's row count (Drafts, Ready for Grading, Up Next, Feedback). */
export function rememberSidebarSections(root) {

    root.querySelectorAll("[data-sidebar-section]").forEach((section) => {
        rememberRows(`assessments-${section.dataset.sidebarSection}`, section.querySelectorAll('[role="link"]').length);
    });

}

const isInstructor = () => window.coursesConfig?.role === "instructor";

/** Remembers how many rows the list had, so the next page-load skeleton can match it. */
export function rememberAssessmentCount(count) {
    rememberRows("assessments", count);
}

export function renderAssessmentsSkeleton() {

    const metric = `
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 px-5 py-5">
            <div class="flex items-start justify-between">
                <div class="flex flex-col gap-2">
                    <div class="h-3 w-24 rounded bg-gray-200"></div>
                    <div class="h-7 w-20 rounded bg-gray-200"></div>
                </div>
                <div class="h-6 w-6 rounded bg-gray-200"></div>
            </div>
            <div class="h-3 w-32 rounded bg-gray-200"></div>
        </div>`;

    const card = `
        <div class="flex items-center justify-between rounded-2xl border border-gray-200 px-8 py-6">
            <div class="flex items-center gap-5">
                <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                <div class="flex flex-col gap-2">
                    <div class="h-5 w-64 rounded bg-gray-200"></div>
                    <div class="h-3 w-80 rounded bg-gray-200"></div>
                </div>
            </div>
            <div class="h-7 w-7 rounded-full bg-gray-200"></div>
        </div>`;

    const sideRow = `
        <div class="flex items-center gap-3 rounded-2xl border border-gray-200 px-5 py-4">
            <div class="h-8 w-8 rounded-full bg-gray-200"></div>
            <div class="flex flex-1 flex-col gap-2">
                <div class="h-4 w-40 rounded bg-gray-200"></div>
                <div class="h-3 w-28 rounded bg-gray-200"></div>
            </div>
        </div>`;

    // Sidebar sections follow the role: an instructor's Drafts and Ready for
    // Grading, a student's Up Next and Feedback. Each section remembers its
    // own row count (1-3), like the assessments list.
    const section = (name, withBadge) => `
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="h-5 w-5 rounded bg-gray-200"></div>
                    <div class="h-6 w-32 rounded bg-gray-200"></div>
                    ${withBadge ? '<div class="h-5 w-7 rounded-full bg-gray-200"></div>' : ""}
                </div>
                ${withBadge ? '<div class="h-4 w-14 rounded bg-gray-200"></div>' : ""}
            </div>
            <div class="flex flex-col gap-2">${sideRow.repeat(rememberedRows(`assessments-${name}`))}</div>
        </div>`;

    const sidebarSections = () => isInstructor()
        ? section("drafts", true) + section("ready", false)
        : section("upnext", false) + section("feedback", false);

    return `
        <div class="flex animate-pulse flex-col gap-10 p-10" aria-hidden="true">

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                ${metric.repeat(4)}
            </div>

            <div class="flex gap-10">

                <div class="flex w-full flex-col gap-5">
                    <div class="flex items-center justify-between">
                        <div class="h-8 w-48 rounded bg-gray-200"></div>
                        <div class="flex gap-4">
                            <div class="h-10 w-44 rounded-full bg-gray-200"></div>
                            <div class="h-10 w-64 rounded-full bg-gray-200"></div>
                            ${isInstructor() ? '<div class="h-10 w-40 rounded-full bg-gray-200"></div>' : ""}
                        </div>
                    </div>
                    <div class="flex flex-col gap-3">${card.repeat(rememberedRows("assessments"))}</div>
                </div>

                <div class="flex w-2/5 shrink-0 flex-col gap-10">
                    ${sidebarSections()}
                </div>

            </div>

        </div>`;

}

/** Child pages (assessment view, editor, quiz builder, quiz attempt): header + body + side panel. */
export function renderAssessmentDetailSkeleton() {

    return `
        <div class="flex animate-pulse flex-col gap-8 p-10" aria-hidden="true">

            <div class="flex items-center gap-4">
                <div class="h-10 w-10 rounded-lg bg-gray-200"></div>
                <div class="flex flex-col gap-2">
                    <div class="h-7 w-72 rounded bg-gray-200"></div>
                    <div class="h-3 w-40 rounded bg-gray-200"></div>
                </div>
            </div>

            <div class="flex gap-10">

                <div class="flex w-full flex-col gap-4 rounded-2xl border border-gray-200 p-8">
                    <div class="h-5 w-40 rounded bg-gray-200"></div>
                    <div class="h-4 w-full rounded bg-gray-200"></div>
                    <div class="h-4 w-full rounded bg-gray-200"></div>
                    <div class="h-4 w-2/3 rounded bg-gray-200"></div>
                    <div class="mt-4 h-32 w-full rounded-xl bg-gray-200"></div>
                </div>

                <div class="flex w-2/5 shrink-0 flex-col gap-4 rounded-2xl border border-gray-200 p-6">
                    <div class="h-5 w-32 rounded bg-gray-200"></div>
                    <div class="h-4 w-48 rounded bg-gray-200"></div>
                    <div class="h-4 w-40 rounded bg-gray-200"></div>
                    <div class="h-10 w-full rounded-full bg-gray-200"></div>
                </div>

            </div>

        </div>`;

}

/** Grade Work: student list on the left, scoring panel on the right. */
export function renderGradeWorkSkeleton() {

    const student = `
        <div class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3">
            <div class="h-9 w-9 rounded-full bg-gray-200"></div>
            <div class="flex flex-1 flex-col gap-2">
                <div class="h-4 w-32 rounded bg-gray-200"></div>
                <div class="h-3 w-20 rounded bg-gray-200"></div>
            </div>
        </div>`;

    return `
        <div class="flex animate-pulse gap-8 p-10" aria-hidden="true">

            <div class="flex w-1/4 shrink-0 flex-col gap-3">
                <div class="h-6 w-28 rounded bg-gray-200"></div>
                ${student.repeat(5)}
            </div>

            <div class="flex w-full flex-col gap-5">
                <div class="h-8 w-64 rounded bg-gray-200"></div>
                <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 p-8">
                    <div class="h-4 w-full rounded bg-gray-200"></div>
                    <div class="h-4 w-5/6 rounded bg-gray-200"></div>
                    <div class="h-40 w-full rounded-xl bg-gray-200"></div>
                </div>
                <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 p-6">
                    <div class="h-5 w-32 rounded bg-gray-200"></div>
                    <div class="h-10 w-full rounded-xl bg-gray-200"></div>
                    <div class="h-10 w-40 rounded-full bg-gray-200"></div>
                </div>
            </div>

        </div>`;

}

/** Just the assessment-list rows — shown inside the list while a filter or search is applied. */
export function renderAssessmentListSkeleton(count = 3) {

    const card = `
        <div class="flex items-center justify-between rounded-2xl border border-gray-200 px-8 py-6">
            <div class="flex items-center gap-5">
                <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                <div class="flex flex-col gap-2">
                    <div class="h-5 w-64 rounded bg-gray-200"></div>
                    <div class="h-3 w-80 rounded bg-gray-200"></div>
                </div>
            </div>
            <div class="h-7 w-7 rounded-full bg-gray-200"></div>
        </div>`;

    return `<div class="flex animate-pulse flex-col gap-3" aria-hidden="true" data-assessments-list-skeleton>${card.repeat(clampRows(count))}</div>`;

}
