import { rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Resources Skeleton
|--------------------------------------------------------------------------
*/

export function renderResourcesSkeleton() {

    return `

    <div class="p-10 animate-pulse flex flex-col gap-6" aria-hidden="true">

        <div class="h-8 w-40 rounded bg-gray-200"></div>

        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="h-10 w-full max-w-sm rounded-xl bg-gray-200"></div>
            <div class="flex gap-2">
                <div class="h-10 w-32 rounded-xl bg-gray-200"></div>
                <div class="h-10 w-32 rounded-xl bg-gray-200"></div>
            </div>
        </div>

        <div class="h-4 w-48 rounded bg-gray-200"></div>

        <div class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white">
            ${Array.from({ length: rememberedRows("resources") }, renderRow).join("")}
        </div>

    </div>

    `;

}

/** Below-the-breadcrumb skeleton: folder/resource rows only, header/toolbar/breadcrumb stay put. */
export function renderResourcesContentSkeleton() {

    return `

    <div class="animate-pulse flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white" aria-hidden="true">
        ${Array.from({ length: rememberedRows("resources") }, renderRow).join("")}
    </div>

    `;

}

function renderRow() {

    return `

    <div class="flex items-center gap-4 px-5 py-4">
        <div class="h-6 w-6 rounded bg-gray-200"></div>
        <div class="flex flex-1 flex-col gap-2">
            <div class="h-4 w-64 rounded bg-gray-200"></div>
            <div class="h-3 w-32 rounded bg-gray-200"></div>
        </div>
    </div>

    `;

}
