<?php

$content = $currentAssessment['instructions'] ?? ['version' => 1, 'blocks' => []];
?>

<div 
    data-instructions-editor
    class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">

    <!-- Toolbar -->
    <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 px-3 py-3 sm:px-5">

        <!-- Style -->

        <select
            data-style-select
            aria-label="Text style"
            class="h-9 w-44 rounded-lg border border-gray-300 bg-white px-3 text-base font-medium text-gray-700 outline-none transition focus:border-brand sm:text-sm pointer-coarse:h-11">

            <option value="p">
                Normal Text
            </option>

            <option value="h1">
                Heading 1
            </option>

            <option value="h2">
                Heading 2
            </option>

        </select>

        <div class="mx-2 h-6 w-px bg-gray-300"></div>

        <!-- Bold -->

        <button
            type="button"
            data-action="bold"
            aria-label="Bold"
            title="Bold"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M7 5h6a3 3 0 010 6H7zm0 6h7a3 3 0 010 6H7z"/>

            </svg>

        </button>

        <!-- Italic -->

        <button
            type="button"
            data-action="italic"
            aria-label="Italic"
            title="Italic"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M14 4h4M6 20h4M10 20L14 4"/>

            </svg>

        </button>

        <!-- Underline -->

        <button
            type="button"
            data-action="underline"
            aria-label="Underline"
            title="Underline"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M8 4v6a4 4 0 008 0V4"/>

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M5 20h14"/>

            </svg>

        </button>

        <div class="mx-2 h-6 w-px bg-gray-300"></div>

        <!-- Align Left -->

        <button
            type="button"
            data-action="justifyLeft"
            aria-label="Align left"
            title="Align left"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>

            </svg>

        </button>

        <!-- Align Center -->

        <button
            type="button"
            data-action="justifyCenter"
            aria-label="Align center"
            title="Align center"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>

            </svg>

        </button>

        <!-- Align Right -->

        <button
            type="button"
            data-action="justifyRight"
            aria-label="Align right"
            title="Align right"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path d="M4 6h16M10 10h10M4 14h16M10 18h10"/>

            </svg>

        </button>

        <!-- Justify -->

        <button
            type="button"
            data-action="justifyFull"
            aria-label="Justify"
            title="Justify"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path d="M4 6h16M4 10h16M4 14h16M4 18h16"/>

            </svg>

        </button>

        <div class="mx-2 h-6 w-px bg-gray-300"></div>

        <!-- Bullet -->

        <button
            type="button"
            data-action="insertUnorderedList"
            aria-label="Bulleted list"
            title="Bulleted list"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path d="M9 6h11M9 12h11M9 18h11"/>
                <circle cx="5" cy="6" r="1"/>
                <circle cx="5" cy="12" r="1"/>
                <circle cx="5" cy="18" r="1"/>

            </svg>

        </button>

        <!-- Numbered -->

        <button
            type="button"
            data-action="insertOrderedList"
            aria-label="Numbered list"
            title="Numbered list"
            class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path d="M10 6h10M10 12h10M10 18h10"/>
                <path d="M4 6h2v4"/>
                <path d="M4 16c0-1 2-2 2-3s-1-1-2-1"/>
                <path d="M4 18h2"/>

            </svg>

        </button>

        <!-- Spacer -->

        <div class="ml-auto flex items-center gap-1">

            <!-- Undo -->

            <button
                type="button"
                data-editor-undo
                aria-label="Undo"
                title="Undo"
                class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

                <svg xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M9 14L4 9m0 0l5-5M4 9h10a6 6 0 010 12h-1"/>

                </svg>

            </button>

            <!-- Redo -->

            <button
                type="button"
                data-editor-redo
                aria-label="Redo"
                title="Redo"
                class="flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-brand hover:text-white pointer-coarse:min-h-11 pointer-coarse:min-w-11">

                <svg xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 14l5-5m0 0l-5-5m5 5H10a6 6 0 000 12h1"/>

                </svg>

            </button>

        </div>

    </div>

    <!-- Writing Canvas -->

    <div
        id="instructions-editor"
        data-instructions='<?= esc(json_encode($content), "attr") ?>'
        class="mx-auto bg-white px-5 py-6 cursor-text sm:px-10 sm:py-10">
    </div>

    <!-- Footer -->
    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3">

        <button
            type="button"
            data-cancel-instructions
            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 pointer-coarse:min-h-11">

            Cancel

        </button>

        <button
            type="button"
            data-save-instructions
            class="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark pointer-coarse:min-h-11">

            Save Changes

        </button>

    </div>

</div>