<footer class="flex items-center justify-between py-4 border-t border-white/10">

    <button
        type="button"
        class="learning-item-link inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-gray-100 px-4 py-2.5 text-sm font-medium transition
            <?= $previousItem ? 'text-gray-500 hover:border-[#0040A6] hover:bg-[#0040A6]/10 hover:text-[#6EA8FF]' : 'cursor-not-allowed opacity-50' ?>"
        <?= $previousItem ? 'data-learning-item="' . $previousItem['id'] . '"' : 'disabled' ?>
    >
        <svg
            class="h-4 w-4"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M15 18l-6-6 6-6" />
        </svg>

        <span>Previous</span>
    </button>

    <button
        type="button"
        class="learning-item-link inline-flex items-center gap-2 rounded-lg bg-[#0040A6] px-5 py-2.5 text-sm font-semibold text-white transition
            <?= $nextItem ? 'hover:bg-[#0056E0]' : 'cursor-not-allowed opacity-50' ?>"
        <?= $nextItem ? 'data-learning-item="' . $nextItem['id'] . '"' : 'disabled' ?>
    >
        <span>Continue</span>

        <svg
            class="h-4 w-4"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M9 18l6-6-6-6" />
        </svg>
    </button>

</footer>