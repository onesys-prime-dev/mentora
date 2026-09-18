/*
|--------------------------------------------------------------------------
| Modules Skeleton
|--------------------------------------------------------------------------
*/

export function renderModulesSkeleton() {

    return `

    <div class="p-10 animate-pulse flex flex-col gap-10">

        ${renderToolbar()}

        <div class="flex flex-col gap-5">

            ${renderModule()}

            ${renderModule()}

        </div>

    </div>

    `;

}

function renderToolbar() {

    return `

    <div class="flex justify-between items-center">

        <div class="h-8 w-56 rounded bg-gray-200"></div>

        <div class="flex gap-4">

            <div class="h-10 w-48 rounded-full bg-gray-200"></div>

            <div class="h-10 w-72 rounded-full bg-gray-200"></div>

            <div class="h-10 w-36 rounded-full bg-gray-200"></div>

        </div>

    </div>

    `;

}

function renderModule() {

    return `

    <div class="rounded-2xl border border-gray-200 bg-white">

        <!-- Header -->
        <div class="flex justify-between items-center p-6">

            <div class="flex gap-5 items-center flex-1">

                <div class="h-14 w-14 rounded-full bg-gray-200"></div>

                <div class="flex flex-col gap-3 flex-1">

                    <div class="h-3 w-24 rounded bg-gray-200"></div>

                    <div class="h-7 w-72 rounded bg-gray-200"></div>

                </div>

            </div>

            <div class="h-8 w-24 rounded-full bg-gray-200"></div>

        </div>

        <div class="px-25 pb-6 space-y-5">

            <div class="h-4 w-2/3 rounded bg-gray-200"></div>

            ${renderLearningUnit()}

            ${renderLearningUnit()}

            ${renderAddDivider()}

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