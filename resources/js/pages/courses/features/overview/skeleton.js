/*
|--------------------------------------------------------------------------
| Overview Skeleton
|--------------------------------------------------------------------------
*/

export function renderOverviewSkeleton() {

    return `

    <div class="p-10 animate-pulse flex gap-8">

        <!-- ====================================================== -->
        <!-- Left Column -->
        <!-- ====================================================== -->

        <div class="flex flex-1 flex-col gap-6">

            ${renderDescriptionCard()}

            <div class="grid grid-cols-2 gap-6">

                ${renderInfoCard()}
                ${renderInfoCard()}

            </div>

            <div class="grid grid-cols-2 gap-6">

                ${renderInfoCard()}
                ${renderInfoCard()}

            </div>

        </div>

        <!-- ====================================================== -->
        <!-- Right Column -->
        <!-- ====================================================== -->

        <div class="w-[350px] flex flex-col gap-6">

            ${renderProgressCard()}

            ${renderUpcomingDeadlinesCard()}

            ${renderRecentActivityCard()}

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

        ${renderDeadline()}
        ${renderDeadline()}

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

        ${renderActivity()}
        ${renderActivity()}

    </div>

    `;

}

function renderActivity() {

    return `

    <div class="flex gap-6">

        <div class="flex flex-col items-center">

            <div class="h-4 w-4 rounded-full bg-gray-200"></div>

            <div class="mt-2 h-20 w-[2px] bg-gray-200"></div>

        </div>

        <div class="flex flex-col gap-3 flex-1">

            <div class="h-5 w-40 rounded bg-gray-200"></div>

            <div class="h-4 w-full rounded bg-gray-200"></div>

            <div class="h-4 w-3/4 rounded bg-gray-200"></div>

            <div class="h-3 w-24 rounded bg-gray-200 mt-2"></div>

        </div>

    </div>

    `;

}