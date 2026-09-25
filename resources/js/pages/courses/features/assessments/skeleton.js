/*
|--------------------------------------------------------------------------
| Assessments Skeleton
|--------------------------------------------------------------------------
|
| Shown only on the first load of the tab (when there's no previous screen
| to keep up while loading) — mirrors the list page's layout.
|
*/

export function renderAssessmentsSkeleton() {

    const metric = `
        <div class="flex items-center gap-4 rounded-2xl border border-gray-200 p-6">
            <div class="h-12 w-12 rounded-full bg-gray-200"></div>
            <div class="flex flex-1 flex-col gap-2">
                <div class="h-3 w-24 rounded bg-gray-200"></div>
                <div class="h-5 w-20 rounded bg-gray-200"></div>
                <div class="h-3 w-32 rounded bg-gray-200"></div>
            </div>
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

    return `
        <div class="flex animate-pulse flex-col gap-10 p-10" aria-hidden="true">

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                ${metric.repeat(3)}
            </div>

            <div class="flex gap-10">

                <div class="flex w-full flex-col gap-5">
                    <div class="flex items-center justify-between">
                        <div class="h-8 w-48 rounded bg-gray-200"></div>
                        <div class="flex gap-4">
                            <div class="h-10 w-44 rounded-full bg-gray-200"></div>
                            <div class="h-10 w-64 rounded-full bg-gray-200"></div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3">${card.repeat(4)}</div>
                </div>

                <div class="flex w-2/5 shrink-0 flex-col gap-10">
                    <div class="flex flex-col gap-4">
                        <div class="h-6 w-32 rounded bg-gray-200"></div>
                        ${sideRow.repeat(3)}
                    </div>
                    <div class="flex flex-col gap-4">
                        <div class="h-6 w-32 rounded bg-gray-200"></div>
                        ${sideRow.repeat(2)}
                    </div>
                </div>

            </div>

        </div>`;

}
