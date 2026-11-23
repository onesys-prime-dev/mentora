/*
|--------------------------------------------------------------------------
| Groups skeletons
|--------------------------------------------------------------------------
| The tab's loading shape mirrors app/Views/pages/courses/skeletons/groups.php
| (tests/js/pages/skeleton-parity.test.js holds them together). The detail
| shape is drawn inside the set detail while one set loads.
*/

export function renderGroupsSkeleton() {

    return `

    <div class="p-5 sm:p-8 lg:p-10 animate-pulse flex flex-col gap-6" aria-hidden="true">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-2">
                <div class="h-8 w-40 rounded bg-gray-200"></div>
                <div class="h-4 w-72 max-w-full rounded bg-gray-200"></div>
            </div>
            <div class="h-10 w-40 rounded-full bg-gray-200"></div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <div class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white xl:col-span-2">
                ${Array.from({ length: 3 }, () => `
                    <div class="flex items-center gap-4 p-4">
                        <div class="h-4 flex-1 rounded bg-gray-200"></div>
                        <div class="h-5 w-20 rounded-full bg-gray-200"></div>
                    </div>`).join("")}
            </div>
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 xl:col-span-3">
                ${renderDetailShape()}
            </div>
        </div>

    </div>

    `;

}

function renderDetailShape() {

    return `
        <div class="h-5 w-48 rounded bg-gray-200"></div>
        <div class="h-20 rounded-xl bg-gray-200"></div>
        <div class="h-20 rounded-xl bg-gray-200"></div>`;

}

/** One set loading inside the detail card. */
export function renderDetailSkeleton(label = "Loading the group set…") {

    return `<div class="flex animate-pulse flex-col gap-3" role="status" aria-busy="true" data-detail-skeleton><span class="sr-only">${label}</span>${renderDetailShape()}</div>`;

}
