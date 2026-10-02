<?php

$content = is_array($currentItem['content'] ?? null) ? $currentItem['content'] : [];

$url = trim((string) ($content['url'] ?? ''));

$description = trim((string) ($content['description'] ?? ''));

// Only http(s) links become clickable (the editor enforces this too).
$isSafeUrl = $url !== ''
    && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);

$host = $isSafeUrl ? preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) : '';
?>

<?php if ($isSafeUrl): ?>

    <section class="flex flex-col gap-5 rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">

        <div class="flex items-center gap-4">

            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#0040A6]/10">
                <?= icon('link', 'h-6 w-6 text-[#0040A6]') ?>
            </div>

            <div class="flex min-w-0 flex-col">
                <span class="text-sm font-semibold text-[#1A1C1E]"><?= esc($host) ?></span>
                <span class="truncate text-xs text-gray-400"><?= esc($url) ?></span>
            </div>

        </div>

        <?php if ($description !== ''): ?>

            <p class="text-sm leading-relaxed text-[#646464]">
                <?= nl2br(esc($description)) ?>
            </p>

        <?php endif; ?>

        <div>

            <a
                href="<?= esc($url) ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-xl bg-[#0040A6] px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#002068]">

                Open link

                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>

            </a>

        </div>

    </section>

<?php else: ?>

    <section class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-8 py-16 text-center">

        <?= icon('link', 'h-10 w-10') ?>

        <span class="text-sm font-semibold text-[#646464]">No link yet</span>

        <span class="text-xs text-[#767676]">This learning item doesn't have a link attached.</span>

    </section>

<?php endif; ?>
