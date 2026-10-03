import { rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Discussions Skeleton
|--------------------------------------------------------------------------
|
| Page load shows the whole screen's shape; a filter, sort or search shows
| only the thread rows. Row count follows the shared 1-3 rule.
|
*/

function row() {

    return `
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white px-6 py-5">
            <div class="flex items-center gap-3">
                <div class="h-6 w-72 rounded bg-gray-200"></div>
                <div class="h-5 w-16 rounded-full bg-gray-200"></div>
            </div>
            <div class="h-4 w-full max-w-2xl rounded bg-gray-200"></div>
            <div class="flex items-center gap-4">
                <div class="h-3 w-28 rounded bg-gray-200"></div>
                <div class="h-3 w-16 rounded bg-gray-200"></div>
                <div class="h-3 w-32 rounded bg-gray-200"></div>
            </div>
        </div>`;

}

/** Just the thread rows, for filter/sort/search changes. */
export function renderDiscussionListSkeleton() {

    return `<div class="animate-pulse flex flex-col gap-3" aria-hidden="true" data-discussions-list-skeleton>${row().repeat(rememberedRows("discussions"))}</div>`;

}

export function renderDiscussionsSkeleton() {

    return `
        <div class="flex animate-pulse flex-col gap-6 p-10" aria-hidden="true">

            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-4">
                    <div class="h-6 w-6 rounded bg-gray-200"></div>
                    <div class="h-8 w-44 rounded bg-gray-200"></div>
                </div>
                <div class="h-4 w-96 max-w-full rounded bg-gray-200"></div>
            </div>

            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="h-10 w-full rounded-full bg-gray-200 lg:w-80"></div>
                <div class="flex gap-2">
                    <div class="h-10 w-40 rounded-full bg-gray-200"></div>
                    <div class="h-10 w-40 rounded-full bg-gray-200"></div>
                </div>
            </div>

            <div class="flex flex-col gap-3">${row().repeat(rememberedRows("discussions"))}</div>

        </div>`;

}

/**
 * Opening a thread: the breadcrumb is already known ("Discussions" is real,
 * so it stays usable), only what's below it is a placeholder.
 */
export function renderThreadSkeleton() {

    const reply = `
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="h-4 w-32 rounded bg-gray-200"></div>
                <div class="h-3 w-16 rounded bg-gray-200"></div>
            </div>
            <div class="h-4 w-full rounded bg-gray-200"></div>
            <div class="h-4 w-2/3 rounded bg-gray-200"></div>
        </div>`;

    return `
        <div class="flex flex-col gap-6 p-10" data-discussions-page>

            <nav aria-label="Breadcrumb">
                <ol class="flex items-center gap-2 text-sm">
                    <li><button type="button" data-discussions-breadcrumb class="rounded font-medium text-[#646464] transition hover:text-[#0040A6]">Discussions</button></li>
                    <li class="flex items-center gap-2 text-[#767676]">›<span class="inline-block h-4 w-40 animate-pulse rounded bg-gray-200"></span></li>
                </ol>
            </nav>

            <div class="animate-pulse flex flex-col gap-6" aria-hidden="true">

                <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white px-6 py-6">
                    <div class="h-8 w-96 max-w-full rounded bg-gray-200"></div>
                    <div class="h-4 w-48 rounded bg-gray-200"></div>
                    <div class="h-4 w-full rounded bg-gray-200"></div>
                    <div class="h-4 w-5/6 rounded bg-gray-200"></div>
                    <div class="h-8 w-56 rounded-full bg-gray-200"></div>
                </div>

                <div class="h-6 w-28 rounded bg-gray-200"></div>

                <div class="flex flex-col gap-3">${reply.repeat(rememberedRows("discussion-replies"))}</div>

            </div>

        </div>`;

}
