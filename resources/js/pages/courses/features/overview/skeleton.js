import { rememberedRows } from "../../../../shared/spa/skeleton-rows.js";

/*
|--------------------------------------------------------------------------
| Overview Skeleton
|--------------------------------------------------------------------------
*/

export function renderOverviewSkeleton() {

    // Mirrors overview/index.php: the metric cards run across the top (an
    // instructor sees four, a student three), then a main column and a
    // side column of course facts.
    const metricCount = window.coursesConfig?.role === "instructor" ? 4 : 3;

    return `

    <div class="p-10 animate-pulse flex flex-col gap-8">

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 ${metricCount === 4 ? "lg:grid-cols-4" : "lg:grid-cols-3"}">

            ${Array.from({ length: metricCount }, renderMetricCard).join("")}

        </div>

        <div class="grid grid-cols-1 gap-8 xl:grid-cols-5">

            <div class="flex min-w-0 flex-col gap-8 xl:col-span-3">

                ${renderLeadCard()}

                ${renderProgressCard()}

                ${renderUpcomingDeadlinesCard()}

            </div>

            <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">

                ${renderDescriptionCard()}

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">

                    ${renderInfoCard()}
                    ${renderInfoCard()}
                    ${renderInfoCard()}
                    ${renderInfoCard()}

                </div>

                ${renderRecentActivityCard()}

            </div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Lead Card
|--------------------------------------------------------------------------
| "Continue where you left off" (student) / "Needs Your Attention"
| (instructor): the first card in the main column.
*/

function renderLeadCard() {

    return `

    <div class="rounded-2xl border border-gray-200 p-6 space-y-5">

        <div class="flex justify-between">

            <div class="h-4 w-52 rounded bg-gray-200"></div>

            <div class="h-6 w-16 rounded-full bg-gray-200"></div>

        </div>

        <div class="space-y-3">

            <div class="h-4 w-40 rounded bg-gray-200"></div>

            <div class="h-7 w-72 rounded bg-gray-200"></div>

        </div>

        <div class="h-9 w-28 rounded-full bg-gray-200"></div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Metric Card
|--------------------------------------------------------------------------
*/

function renderMetricCard() {

    return `

    <div class="flex items-center gap-4 rounded-2xl border border-gray-200 p-6">

        <div class="h-12 w-12 shrink-0 rounded-full bg-gray-200"></div>

        <div class="flex flex-1 flex-col gap-2">

            <div class="h-3 w-24 rounded bg-gray-200"></div>

            <div class="h-5 w-20 rounded bg-gray-200"></div>

            <div class="h-3 w-32 rounded bg-gray-200"></div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Description
|--------------------------------------------------------------------------
*/

function renderDescriptionCard() {

    return `

    <div class="rounded-2xl border border-gray-200 p-6 space-y-5">

        <div class="flex items-center gap-3">

            <div class="h-5 w-5 rounded-full bg-gray-200"></div>

            <div class="h-5 w-44 rounded bg-gray-200"></div>

        </div>

        <div class="space-y-3">

            <div class="h-4 w-full rounded bg-gray-200"></div>

            <div class="h-4 w-full rounded bg-gray-200"></div>

            <div class="h-4 w-4/5 rounded bg-gray-200"></div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Information Card
|--------------------------------------------------------------------------
*/

function renderInfoCard() {

    return `

    <div class="rounded-2xl border border-gray-200 p-6">

        <div class="flex gap-4">

            <div class="h-13 w-13 rounded-full bg-gray-200"></div>

            <div class="flex flex-col gap-3 flex-1">

                <div class="h-3 w-20 rounded bg-gray-200"></div>

                <div class="h-5 w-36 rounded bg-gray-200"></div>

                <div class="h-4 w-48 rounded bg-gray-200"></div>

            </div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Progress
|--------------------------------------------------------------------------
*/

function renderProgressCard() {

    return `

    <div class="rounded-2xl bg-gray-300 p-6 space-y-8">

        <div class="space-y-5">

            <div class="flex justify-between">

                <div class="h-7 w-40 rounded bg-white/40"></div>

                <div class="h-10 w-16 rounded bg-white/40"></div>

            </div>

            <div class="h-3 rounded-full bg-white/30">

                <div class="h-3 w-1/2 rounded-full bg-white/60"></div>

            </div>

        </div>

        <div class="flex justify-between">

            <div class="h-5 w-24 rounded bg-white/40"></div>

            <div class="h-5 w-24 rounded bg-white/40"></div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Upcoming Deadlines
|--------------------------------------------------------------------------
*/

function renderUpcomingDeadlinesCard() {

    return `

    <div class="rounded-2xl border border-gray-200 p-6 space-y-6">

        <div class="flex justify-between">

            <div class="h-7 w-44 rounded bg-gray-200"></div>

            <div class="h-4 w-16 rounded bg-gray-200"></div>

        </div>

        ${Array.from({ length: rememberedRows("overview-deadlines") }, renderDeadline).join("")}

    </div>

    `;

}

function renderDeadline() {

    return `

    <div class="flex gap-5">

        <div class="h-12 w-12 rounded-xl bg-gray-200"></div>

        <div class="flex flex-col gap-3 flex-1">

            <div class="h-5 w-48 rounded bg-gray-200"></div>

            <div class="h-4 w-20 rounded bg-gray-200"></div>

        </div>

    </div>

    `;

}

/*
|--------------------------------------------------------------------------
| Recent Activity
|--------------------------------------------------------------------------
*/

function renderRecentActivityCard() {

    return `

    <div class="rounded-2xl border border-gray-200 p-6 space-y-6">

        <div class="h-7 w-40 rounded bg-gray-200"></div>

        ${Array.from({ length: rememberedRows("overview-activity") }, renderActivity).join("")}

    </div>

    `;

}

function renderActivity() {

    return `

    <div class="flex gap-5">

        <div class="flex flex-col items-center">

            <div class="h-3.5 w-3.5 rounded-full bg-gray-200"></div>

            <div class="mt-2 h-14 w-0.5 bg-gray-200"></div>

        </div>

        <div class="flex flex-col gap-3 flex-1">

            <div class="h-5 w-36 rounded bg-gray-200"></div>

            <div class="h-4 w-full rounded bg-gray-200"></div>

            <div class="h-3 w-20 rounded bg-gray-200"></div>

        </div>

    </div>

    `;

}
