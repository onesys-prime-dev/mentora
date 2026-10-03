<?php

$content = $currentItem['content'] ?? [];
$resource = $currentItem['resource'] ?? null;

$source = !empty($resource['file'])
    ? 'upload'
    : 'link';

?>

<div
    data-video-editor
    class="flex flex-col gap-6">

    <!-- ========================================================= -->
    <!-- Video Source -->
    <!-- ========================================================= -->

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div class="border-b border-gray-200 px-6 py-4">

            <h2 class="text-base font-semibold text-[#1A1C1E]">
                Video Source
            </h2>

            <p class="mt-1 text-sm text-gray-500">
                Choose whether this lesson uses an uploaded video or an external video link.
            </p>

        </div>

        <div class="flex gap-6 p-6">

            <label class="flex cursor-pointer items-center gap-3">

                <input
                    type="radio"
                    name="video-source"
                    value="upload"
                    data-video-source
                    <?= $source === 'upload' ? 'checked' : '' ?>

                    class="h-4 w-4 border-gray-300 text-[#0040A6] focus:ring-[#0040A6]">

                <span class="font-medium text-gray-700">
                    Upload Video
                </span>

            </label>

            <label class="flex cursor-pointer items-center gap-3">

                <input
                    type="radio"
                    name="video-source"
                    value="link"
                    data-video-source
                    <?= $source === 'link' ? 'checked' : '' ?>

                    class="h-4 w-4 border-gray-300 text-[#0040A6] focus:ring-[#0040A6]">

                <span class="font-medium text-gray-700">
                    External Link
                </span>

            </label>

        </div>

    </div>

    <!-- ========================================================= -->
    <!-- Upload -->
    <!-- ========================================================= -->

    <div
        data-video-upload-panel
        class="<?= $source === 'upload' ? '' : 'hidden' ?> rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div class="border-b border-gray-200 px-6 py-4">

            <h2 class="font-semibold text-[#1A1C1E]">
                Upload Video
            </h2>

        </div>

        <div class="p-6">

            <label
                class="flex min-h-[180px] cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-gray-300 bg-gray-50 transition hover:border-[#0040A6] hover:bg-[#0040A6]/5">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="mb-4 h-10 w-10 text-[#767676]"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 11v8m0-8l-3 3m3-3l3 3"/>

                </svg>

                <span class="font-medium text-gray-700">
                    Click to upload a video
                </span>

                <span class="mt-1 text-sm text-gray-500">
                    MP4, MOV or WEBM
                </span>

                <input
                    type="file"
                    accept="video/*"
                    data-video-file
                    class="hidden">

                <p
                    data-video-file-name
                    class="mt-4 text-sm text-gray-500">

                    No file selected.

                </p>

            </label>

        </div>

    </div>

    <!-- ========================================================= -->
    <!-- External Link -->
    <!-- ========================================================= -->

    <div
        data-video-link-panel
        class="<?= $source === 'link' ? '' : 'hidden' ?> rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div class="border-b border-gray-200 px-6 py-4">

            <h2 class="font-semibold text-[#1A1C1E]">
                External Video
            </h2>

        </div>

        <div class="space-y-2 p-6">

            <label class="block text-sm font-medium text-gray-700">

                Video URL

            </label>

            <input
                type="url"
                data-video-url
                value="<?= esc($resource['resource_url'] ?? '') ?>"
                placeholder="https://youtube.com/watch?v=..."
                class="w-full rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]">

            <p class="text-sm text-gray-500">
                Supports YouTube and Vimeo links.
            </p>

        </div>

    </div>

    <!-- ========================================================= -->
    <!-- Preview -->
    <!-- ========================================================= -->

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div class="border-b border-gray-200 px-6 py-4">

            <h2 class="font-semibold text-[#1A1C1E]">
                Preview
            </h2>

        </div>

        <div
            data-video-preview
            data-existing-video="<?= esc($resource['file']['url'] ?? '') ?>"
            class="aspect-video overflow-hidden bg-neutral-900">

            <div class="flex h-full items-center justify-center text-[#767676]">

                Video preview will appear here.

            </div>

        </div>

    </div>

    <!-- ========================================================= -->
    <!-- Playback -->
    <!-- ========================================================= -->

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div class="border-b border-gray-200 px-6 py-4">

            <h2 class="font-semibold text-[#1A1C1E]">
                Playback
            </h2>

        </div>

        <div class="grid gap-5 p-6 sm:grid-cols-2">

            <label class="flex items-center gap-3">

                <input
                    type="checkbox"
                    data-video-autoplay
                    <?= !empty($content['autoplay']) ? 'checked' : '' ?>

                    class="h-4 w-4 rounded border-gray-300 text-[#0040A6] focus:ring-[#0040A6]">

                <span>Autoplay</span>

            </label>

            <label class="flex items-center gap-3">

                <input
                    type="checkbox"
                    data-video-controls
                    <?= !empty($content['show_controls']) ? 'checked' : '' ?>

                    class="h-4 w-4 rounded border-gray-300 text-[#0040A6] focus:ring-[#0040A6]">

                <span>Show Controls</span>

            </label>

        </div>

    </div>

</div>