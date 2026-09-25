<div class="relative flex items-center">

    <button
        type="button"
        data-learning-item-actions
        title="Item actions"
        class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition-all duration-200 hover:bg-white hover:text-[#0040A6] focus:outline-none focus:ring-2 focus:ring-[#0040A6]/20 cursor-pointer">

        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-5 w-5"
            viewBox="0 0 24 24"
            fill="currentColor">

            <circle cx="12" cy="5" r="1.8"/>
            <circle cx="12" cy="12" r="1.8"/>
            <circle cx="12" cy="19" r="1.8"/>

        </svg>

    </button>

    <div
        class="absolute right-0 top-10 z-30 hidden w-44 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl"
        data-learning-item-actions-panel>

        <div class="py-2">

            <button
                type="button"
                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 transition hover:bg-gray-50 hover:text-[#0040A6] cursor-pointer"
                data-learning-item-edit-button
                data-learning-item="<?= esc($learning_item['id']) ?>">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>

                </svg>

                Edit

            </button>

            <button
                type="button"
                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 transition hover:bg-gray-50 hover:text-[#0040A6] cursor-pointer"
                data-learning-item-dependencies
                data-learning-item-id="<?= esc($learning_item['id']) ?>"
                data-learning-item-title="<?= esc($learning_item['title']) ?>">

                <?= icon('lock', 'h-4 w-4 text-current') ?>

                Dependencies

            </button>

        </div>

    </div>

</div>
