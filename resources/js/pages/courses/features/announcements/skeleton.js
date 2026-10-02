import { rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Announcements Skeleton
|--------------------------------------------------------------------------
*/

/** Full-page skeleton — initial load / browser redirect into this tab only. */
export function renderAnnouncementsSkeleton() {

    return `

    <div class="p-10 animate-pulse flex flex-col gap-6">

        <div class="h-8 w-56 rounded bg-gray-200"></div>

        <div class="flex flex-col gap-6 lg:grid lg:grid-cols-[350px_1fr] lg:items-start lg:gap-8">

            ${renderSidebar()}

            <div class="flex min-w-0 flex-col gap-6">

                ${renderMainContents()}

            </div>

        </div>

    </div>

    `;

}

/**
 * Main-column-only skeleton — filter/sort/search changes and posting/
 * deleting an announcement re-skeleton just this part, leaving the header
 * and sidebar untouched so the page doesn't flash as a whole.
 */
export function renderAnnouncementsMainSkeleton() {

    return `<div class="animate-pulse flex flex-col gap-6">${renderMainContents()}</div>`;

}

function renderMainContents() {

    return `

    ${window.coursesConfig?.role === "instructor" ? '<div class="h-10 w-48 rounded-xl bg-gray-200"></div>' : ""}

    <div class="flex flex-col gap-5">

        ${Array.from({ length: rememberedRows("announcements") }, renderCard).join("")}

    </div>

    `;

}

function renderSidebar() {

    return `

    <div class="flex flex-col gap-6">

        <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5">

            <div class="h-3 w-32 rounded bg-gray-200"></div>

            <div class="h-10 w-full rounded-xl bg-gray-200"></div>

            <div class="flex gap-2">
                <div class="h-7 w-16 rounded-full bg-gray-200"></div>
                <div class="h-7 w-20 rounded-full bg-gray-200"></div>
            </div>

            <div class="h-10 w-full rounded-xl bg-gray-200"></div>

        </div>

        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5">

            <div class="h-3 w-20 rounded bg-gray-200"></div>

            <div class="h-5 w-full rounded bg-gray-200"></div>
            <div class="h-5 w-4/5 rounded bg-gray-200"></div>

        </div>

    </div>

    `;

}

function renderCard() {

    return `

    <div class="rounded-2xl border border-gray-200 bg-white p-6 flex flex-col gap-4">

        <div class="flex items-center gap-3">

            <div class="h-10 w-10 rounded-full bg-gray-200"></div>

            <div class="flex flex-col gap-2">
                <div class="h-4 w-40 rounded bg-gray-200"></div>
                <div class="h-3 w-24 rounded bg-gray-200"></div>
            </div>

        </div>

        <div class="h-6 w-1/2 rounded bg-gray-200"></div>

        <div class="flex flex-col gap-2">
            <div class="h-4 w-full rounded bg-gray-200"></div>
            <div class="h-4 w-full rounded bg-gray-200"></div>
            <div class="h-4 w-2/3 rounded bg-gray-200"></div>
        </div>

        <div class="flex items-center gap-2 border-t border-gray-100 pt-4">
            <div class="h-8 w-8 rounded-full bg-gray-200"></div>
            <div class="h-8 flex-1 rounded-2xl bg-gray-200"></div>
        </div>

    </div>

    `;

}
