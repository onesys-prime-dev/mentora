<?php
/**
 * The To grade tab: work waiting to be graded across every section, one row per assessment, oldest first.
 * Grade → opens the existing Grade work page with the way back to this queue (filters and all).
 *
 * @var list<array{offering_id: int, assessment_id: int, label: string, detail: string, late: int, href: string}> $queue
 * @var array{qsection: ?int, type: ?string, late: bool}                                                          $filters
 */
$filtered = $filters['qsection'] !== null || $filters['type'] !== null || $filters['late'];
?>

<section class="flex flex-col gap-3" aria-labelledby="grades-to-grade">

    <h2 id="grades-to-grade" class="sr-only">To grade</h2>

    <?php if ($queue === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'check',
            'title' => 'Nothing waiting to grade',
            'text'  => $filtered ? 'Nothing matches these filters. Pick "All sections", "All types" or turn off "Late only" to see everything.' : 'Work your students hand in shows here until it is graded.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <ul class="flex flex-col gap-3">
            <?php foreach ($queue as $row): ?>
                <li class="flex flex-col gap-3 rounded-2xl border border-[#E3E6EE] bg-white px-4 py-3 transition hover:border-brand/30 hover:shadow-sm sm:flex-row sm:items-center sm:justify-between"
                    data-to-grade-row data-offering-id="<?= (int) $row['offering_id'] ?>" data-assessment-id="<?= (int) $row['assessment_id'] ?>">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="text-sm font-bold text-[#1A1C1E] wrap-break-word"><?= esc($row['label']) ?></span>
                        <span class="text-xs text-[#646464]"><?= esc($row['detail']) ?><?php if ($row['late'] > 0): ?> · <span class="font-semibold text-[#946200]"><?= (int) $row['late'] ?> late</span><?php endif; ?></span>
                    </div>
                    <a href="<?= esc($row['href']) ?>"
                       class="inline-flex min-h-9 shrink-0 items-center justify-center rounded-full bg-brand px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2"
                       data-grade-link>Grade →<span class="sr-only"> <?= esc($row['label']) ?></span></a>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

</section>
