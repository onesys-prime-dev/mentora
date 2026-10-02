import { rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Grades Skeleton
|--------------------------------------------------------------------------
|
| Shown on every page load of the tab, same as Overview/Modules — mirrors
| the summary cards and assessment list.
|
*/

export function renderGradesSkeleton() {

    const instructor = window.coursesConfig?.role === "instructor";

    const pageTitle = `
        <div class="flex items-center gap-4">
            <div class="h-6 w-6 rounded bg-gray-200"></div>
            <div class="h-8 w-32 rounded bg-gray-200"></div>
        </div>`;

    const metric = `
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 px-6 py-6">
            <div class="flex items-start justify-between">
                <div class="flex flex-col gap-2">
                    <div class="h-3 w-24 rounded bg-gray-200"></div>
                    <div class="h-8 w-28 rounded bg-gray-200"></div>
                </div>
                <div class="h-11 w-11 rounded-full bg-gray-200"></div>
            </div>
            <div class="h-3 w-36 rounded bg-gray-200"></div>
        </div>`;

    // Instructor rows end in a status chip ("2 Need Review"); a student's
    // end in their score.
    const row = `
        <div class="flex items-center justify-between rounded-2xl border border-gray-200 px-8 py-6">
            <div class="flex items-center gap-5">
                <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                <div class="flex flex-col gap-2">
                    <div class="h-5 w-56 rounded bg-gray-200"></div>
                    <div class="h-3 w-40 rounded bg-gray-200"></div>
                </div>
            </div>
            ${instructor
                ? '<div class="h-7 w-28 rounded-full bg-gray-200"></div>'
                : '<div class="h-6 w-20 rounded bg-gray-200"></div>'}
        </div>`;

    const listing = `
        <div class="flex flex-col gap-3">

            <div class="h-7 w-36 rounded bg-gray-200"></div>

            <div class="flex flex-col gap-3">
                <div class="h-3 w-24 rounded bg-gray-200"></div>
                ${row.repeat(rememberedRows("grades"))}
            </div>

        </div>`;

    if (instructor) {

        // gradebook toolbar → class summary → assessments to grade
        return `
            <div class="flex animate-pulse flex-col gap-10 p-10" aria-hidden="true">

                ${pageTitle}

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="h-7 w-32 rounded-full bg-gray-200"></div>
                        <div class="h-4 w-64 rounded bg-gray-200"></div>
                    </div>
                    <div class="flex gap-2">
                        <div class="h-10 w-32 rounded-xl bg-gray-200"></div>
                        <div class="h-10 w-36 rounded-xl bg-gray-200"></div>
                        <div class="h-10 w-44 rounded-xl bg-gray-200"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    ${metric.repeat(3)}
                </div>

                ${listing}

            </div>`;

    }

    // student summary → how the grade is calculated → per-assessment breakdown
    const category = `
        <div class="flex flex-col gap-2 rounded-2xl border border-gray-200 px-5 py-4">
            <div class="flex items-center justify-between">
                <div class="h-4 w-28 rounded bg-gray-200"></div>
                <div class="h-4 w-10 rounded-full bg-gray-200"></div>
            </div>
            <div class="h-6 w-16 rounded bg-gray-200"></div>
            <div class="h-3 w-24 rounded bg-gray-200"></div>
        </div>`;

    return `
        <div class="flex animate-pulse flex-col gap-10 p-10" aria-hidden="true">

            ${pageTitle}

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                ${metric.repeat(3)}
            </div>

            <div class="flex flex-col gap-4">
                <div class="h-3 w-48 rounded bg-gray-200"></div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    ${category.repeat(3)}
                </div>
            </div>

            ${listing}

        </div>`;

}

/** Gradebook roster: heading + a grid of student rows. */
export function renderGradebookSkeleton() {

    const row = `
        <div class="flex items-center gap-6 border-b border-gray-100 px-6 py-4">
            <div class="h-9 w-9 rounded-full bg-gray-200"></div>
            <div class="h-4 w-48 rounded bg-gray-200"></div>
            <div class="ml-auto flex gap-6">
                <div class="h-4 w-16 rounded bg-gray-200"></div>
                <div class="h-4 w-16 rounded bg-gray-200"></div>
                <div class="h-4 w-16 rounded bg-gray-200"></div>
            </div>
        </div>`;

    return `
        <div class="flex animate-pulse flex-col gap-6 p-10" aria-hidden="true">
            <div class="flex items-center justify-between">
                <div class="h-8 w-56 rounded bg-gray-200"></div>
                <div class="h-10 w-40 rounded-full bg-gray-200"></div>
            </div>
            <div class="rounded-2xl border border-gray-200">${row.repeat(7)}</div>
        </div>`;

}

/** Grading scheme editor: heading + category rows. */
export function renderSchemeSkeleton() {

    const category = `
        <div class="flex items-center justify-between rounded-2xl border border-gray-200 px-6 py-5">
            <div class="flex flex-col gap-2">
                <div class="h-5 w-48 rounded bg-gray-200"></div>
                <div class="h-3 w-32 rounded bg-gray-200"></div>
            </div>
            <div class="h-10 w-24 rounded-xl bg-gray-200"></div>
        </div>`;

    return `
        <div class="flex animate-pulse flex-col gap-6 p-10" aria-hidden="true">
            <div class="flex items-center justify-between">
                <div class="h-8 w-56 rounded bg-gray-200"></div>
                <div class="h-10 w-32 rounded-full bg-gray-200"></div>
            </div>
            <div class="flex flex-col gap-3">${category.repeat(4)}</div>
        </div>`;

}
