import { rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Modules Skeleton
|--------------------------------------------------------------------------
*/

export function renderModulesSkeleton() {

    return `

    <div class="p-10 animate-pulse flex flex-col gap-10" aria-hidden="true">

        ${renderToolbar()}

        <div class="flex flex-col gap-5">

            ${Array.from({ length: rememberedRows("modules") }, renderModule).join("")}

        </div>

    </div>

    `;

}

function renderToolbar() {

    return `

    <div class="flex flex-col gap-3">

        <div class="flex items-center justify-between gap-4">

            <div class="flex items-center gap-4">
                <div class="h-6 w-6 rounded bg-gray-200"></div>
                <div class="h-8 w-56 rounded bg-gray-200"></div>
            </div>

            ${window.coursesConfig?.role === "instructor" ? '<div class="h-10 w-36 rounded-full bg-gray-200"></div>' : ""}

        </div>

        <div class="h-5 w-72 rounded bg-gray-200"></div>

        <div class="h-4 w-full max-w-150 rounded bg-gray-200"></div>

    </div>

    `;

}

function renderModule() {

    return `

    <div class="rounded-2xl border border-gray-200 bg-white">

        <!-- Header -->
        <div class="flex justify-between items-center p-6">

            <div class="flex gap-5 items-center flex-1">

                <div class="h-15 w-15 rounded-full bg-gray-200"></div>

                <div class="flex flex-col gap-3 flex-1">

                    <div class="h-3 w-24 rounded bg-gray-200"></div>

                    <div class="h-7 w-72 rounded bg-gray-200"></div>

                </div>

            </div>

            <div class="h-8 w-32 rounded-full bg-gray-200"></div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Single Module Page
|--------------------------------------------------------------------------
*/

export function renderModulePageSkeleton() {

    const instructor = window.coursesConfig?.role === "instructor";

    return `

    <div class="p-10 animate-pulse flex flex-col gap-8" aria-hidden="true">

        <!-- top bar -->
        <div class="flex items-start justify-between gap-5 pb-6 border-b border-black/10">

            <div class="flex items-center gap-4">

                <div class="h-10 w-10 rounded-lg bg-gray-200"></div>

                <div class="flex flex-col gap-2">
                    <div class="h-7 w-56 rounded bg-gray-200"></div>
                    <div class="h-4 w-32 rounded bg-gray-200"></div>
                </div>

            </div>

            ${instructor ? `
            <div class="flex items-center gap-2">
                <div class="h-9 w-32 rounded-full bg-gray-200"></div>
                <div class="h-9 w-9 rounded-lg bg-gray-200"></div>
                <div class="h-9 w-9 rounded-lg bg-gray-200"></div>
            </div>` : ""}

        </div>

        <!-- progress -->
        <div class="flex flex-col gap-2">
            <div class="h-4 w-40 rounded bg-gray-200"></div>
            <div class="h-2 w-full rounded-full bg-gray-200"></div>
        </div>

        <div class="h-4 w-2/3 rounded bg-gray-200"></div>

        ${instructor ? "" : `
        <!-- student: "continue where you left off" -->
        <div class="flex items-center gap-4 rounded-2xl border border-gray-200 px-6 py-5">
            <div class="h-10 w-10 rounded-full bg-gray-200"></div>
            <div class="flex flex-col gap-2">
                <div class="h-3 w-40 rounded bg-gray-200"></div>
                <div class="h-5 w-56 rounded bg-gray-200"></div>
            </div>
        </div>`}

        <!-- learning units -->
        <div class="flex flex-col gap-3">

            ${Array.from({ length: rememberedRows("module-units") }, renderLearningUnit).join("")}

        </div>

    </div>

    `;

}

function renderLearningUnit() {

    return `

    <div>

        <div class="flex items-center justify-between py-3">

            <div class="flex items-center gap-4">

                <div class="h-10 w-10 rounded bg-gray-200"></div>

                <div class="h-5 w-80 rounded bg-gray-200"></div>

            </div>

            <div class="h-6 w-20 rounded-full bg-gray-200"></div>

        </div>

        <div class="ml-5 border-l-2 border-gray-200 pl-8 py-4 space-y-3">

            ${renderLearningItem()}
            ${renderLearningItem()}
            ${renderLearningItem()}

            ${renderAddDivider()}

        </div>

    </div>

    `;

}

function renderLearningItem() {

    return `

    <div class="flex items-center justify-between rounded-2xl px-6 py-4">

        <div class="flex flex-col gap-2">

            <div class="h-5 w-64 rounded bg-gray-200"></div>

            <div class="h-3 w-20 rounded bg-gray-200"></div>

        </div>

        <div class="h-8 w-8 rounded bg-gray-200"></div>

    </div>

    `;

}

function renderAddDivider() {

    return `

    <div class="flex items-center gap-3 py-2">

        <div class="h-px flex-1 bg-gray-200"></div>

        <div class="h-10 w-10 rounded-full bg-gray-200"></div>

        <div class="h-px flex-1 bg-gray-200"></div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Learning Item View / Editor
|--------------------------------------------------------------------------
*/

export function renderLearningItemSkeleton() {

    return `

    <div class="flex animate-pulse" aria-hidden="true">

        <!-- left nav panel -->
        <div class="hidden w-72 shrink-0 flex-col gap-4 border-r border-gray-100 p-6 lg:flex">

            <div class="h-6 w-40 rounded bg-gray-200"></div>

            ${renderPanelUnit()}
            ${renderPanelUnit()}
            ${renderPanelUnit()}

        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-5 p-10">

            <!-- breadcrumb -->
            <div class="h-4 w-64 rounded bg-gray-200"></div>

            ${renderLearningItemBody()}

        </div>

    </div>

    `;

}

/**
 * Prev/Continue stays on the learning-item view and only swaps the header +
 * content + detail-panel + nav block — the left unit/item panel and the
 * breadcrumb above it aren't touched, so navigating between items doesn't
 * flash the whole page.
 */
export function renderLearningItemMainSkeleton() {

    return `<div class="animate-pulse" aria-hidden="true">${renderLearningItemBody()}</div>`;

}

function renderLearningItemBody() {

    return `

    <div class="flex items-start justify-between gap-4">

        <div class="flex flex-col gap-2">
            <div class="h-3 w-24 rounded bg-gray-200"></div>
            <div class="h-7 w-80 rounded bg-gray-200"></div>
        </div>

        <div class="h-9 w-24 rounded-lg bg-gray-200"></div>

    </div>

    <!-- content -->
    <div class="flex flex-col gap-3 rounded-2xl border border-gray-100 p-6">
        <div class="h-4 w-full rounded bg-gray-200"></div>
        <div class="h-4 w-full rounded bg-gray-200"></div>
        <div class="h-4 w-5/6 rounded bg-gray-200"></div>
        <div class="h-48 w-full rounded-xl bg-gray-200"></div>
    </div>

    <!-- prev/next nav -->
    <div class="mt-auto flex items-center justify-between pt-6">
        <div class="h-9 w-28 rounded-lg bg-gray-200"></div>
        <div class="h-9 w-28 rounded-lg bg-gray-200"></div>
    </div>

    `;

}

function renderPanelUnit() {

    return `

    <div class="flex flex-col gap-2">

        <div class="h-4 w-48 rounded bg-gray-200"></div>

        <div class="ml-4 flex flex-col gap-2">
            <div class="h-3 w-40 rounded bg-gray-200"></div>
            <div class="h-3 w-36 rounded bg-gray-200"></div>
            <div class="h-3 w-44 rounded bg-gray-200"></div>
        </div>

    </div>

    `;

}