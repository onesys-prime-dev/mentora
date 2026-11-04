/*
|--------------------------------------------------------------------------
| Instructor Feed Skeleton (a parent's "From the instructor" tab)
|--------------------------------------------------------------------------
| Mirrors app/Views/pages/courses/skeletons/instructor.php.
*/

export function renderInstructorSkeleton() {

    return `

    <div class="p-10 animate-pulse flex flex-col gap-8" aria-hidden="true">

        <div class="h-8 w-56 rounded bg-gray-200"></div>

        <div class="h-10 w-48 rounded-full bg-gray-200"></div>

        <div class="flex flex-col gap-4">

            <div class="h-24 rounded-2xl border border-gray-200 bg-gray-100"></div>

            <div class="h-24 rounded-2xl border border-gray-200 bg-gray-100"></div>

            <div class="h-24 rounded-2xl border border-gray-200 bg-gray-100"></div>

        </div>

    </div>

    `;

}
