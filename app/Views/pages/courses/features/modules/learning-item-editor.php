<div class="flex">

    <?= $this->include('pages/courses/features/modules/partials/learning-item-panel') ?>

    <div class="flex min-w-0 flex-1 flex-col p-10">

        <div class="flex items-center justify-between border-b border-gray-200 pb-4">

            <?= $this->include('pages/courses/features/modules/partials/breadcrumb') ?>

            <div class="flex items-center gap-4">

                <!-- Save Status -->
                <div
                    data-editor-save-status
                    class="flex items-center gap-2 text-sm text-gray-500">

                    <div
                        data-save-indicator
                        class="h-2 w-2 rounded-full bg-emerald-500">
                    </div>

                    <span data-save-message>
                        All changes saved
                    </span>

                    <span class="text-gray-300">
                        •
                    </span>

                    <span data-save-time>
                        Saved just now
                    </span>

                </div>

                <div class="flex items-center gap-2 rounded-full bg-[#F4F8FF] px-3 py-1.5">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4 text-[#0040A6]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>

                    </svg>

                    <span class="text-xs font-semibold uppercase tracking-wide text-[#0040A6]">
                        Editing
                    </span>

                </div>

            </div>

        </div>

        <div class="flex flex-1">

            <section class="flex min-w-0 flex-1 py-6">

                <div class="flex min-w-0 flex-1 flex-col gap-5">

                    <!-- header -->
                    <?= $this->include('pages/courses/features/modules/partials/learning-item-header') ?>

                    <!-- contents -->
                    <?= $this->include('pages/courses/features/modules/learning-items/editor/' . $currentItem['item_type']) ?>

                </div>

            </section>

        </div>

        <?= $this->include('pages/courses/features/modules/partials/editor-actions') ?>

        <?= $this->include('pages/courses/features/modules/partials/modals/learning-item-delete') ?>

    </div>

</div>