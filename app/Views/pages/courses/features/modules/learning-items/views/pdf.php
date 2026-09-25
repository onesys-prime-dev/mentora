<?php

$resource = $currentItem['resource'] ?? null;
$file = $resource['file'] ?? null;

?>

<?php if ($file): ?>

    <section class="mx-auto w-full max-w-6xl overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div
            id="pdf-viewer"
            data-url="<?= esc($file['url']) ?>"
            data-download-url="<?= esc(file_url((int) $file['id'], true)) ?>"
            data-allow-download="<?= ($currentItem['content']['allow_download'] ?? false) ? 'true' : 'false' ?>">
        </div>

    </section>

<?php else: ?>

    <section class="rounded-2xl border border-dashed border-gray-300 bg-gray-50">

        <div class="flex min-h-[32rem] flex-col items-center justify-center px-8 py-16 text-center">

            <div class="flex h-20 w-20 items-center justify-center rounded-full bg-red-50">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-10 w-10 text-red-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M7 3h8l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm2 9h6m-6 4h6"/>

                </svg>

            </div>

            <h3 class="mt-6 text-xl font-semibold text-gray-900">
                No PDF Available
            </h3>

            <p class="mt-2 max-w-md text-sm leading-6 text-gray-500">
                This learning material doesn't have a PDF attached yet.
                Please contact your instructor if you believe this resource
                should be available.
            </p>

        </div>

    </section>

<?php endif; ?>