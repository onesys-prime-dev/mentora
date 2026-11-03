<?php
/**
 * A course's small breakdown on the student's Grades page: one column per grading period (or per category when
 * the scheme has no periods) with the percentage under it, the categories behind a toggle, then the standing.
 * Fetched when the row is expanded, so it is a fragment: the page script wires the toggle.
 *
 * @var array<string, mixed>       $summary    GradeService::buildStudentGrades()['summary']
 * @var list<array<string, mixed>> $periods    periodBreakdown: name, weight, percentage, categories
 * @var list<array<string, mixed>> $categories categoryBreakdown: name, weight, percentage
 * @var string                     $courseUrl  the course's Grades tab
 */
helper('grade');

$columns = $periods !== [] ? $periods : $categories;
$shown   = static fn (mixed $value): string => $value === null ? 'not yet' : number_format((float) $value, 1) . '%';

// Colour only repeats the word on the chip; the icon is a second signal. The same look as the row's standing.
$tag  = (string) $summary['tag'];
$look = grade_tag_look($tag);

$incUntil = $tag === 'INC' && ! empty($summary['inc_until']) ? strtotime((string) $summary['inc_until']) : false;
$incText  = $tag === 'INC' ? 'INC' . ($incUntil !== false ? ' · finish by ' . date('M j, Y', $incUntil) : '') : $tag;
$reason   = $tag === 'INC' ? trim((string) ($summary['inc_reason'] ?? '')) : '';
?>

<div class="flex flex-col gap-4" data-grades-breakdown-root>

    <?php if ($columns === []): ?>

        <p class="text-sm text-[#646464]">Nothing is graded in this course yet.</p>

    <?php else: ?>

        <div class="overflow-x-auto rounded-xl border border-[#A9A9A9]/50">
            <table class="w-full min-w-[20rem] text-left text-sm" data-grades-breakdown>
                <thead class="bg-[#F6F7FA] text-xs font-semibold text-[#646464]">
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th scope="col" class="px-4 py-2.5"><?= esc((string) $column['name']) ?> <?= esc(grade_number($column['weight'])) ?>%</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr class="bg-white">
                        <?php foreach ($columns as $column): ?>
                            <?php if ($column['percentage'] === null): ?>
                                <td class="px-4 py-3 text-[#767676]"><?= esc($shown(null)) ?></td>
                            <?php else: ?>
                                <td class="px-4 py-3 font-bold text-[#1A1C1E]"><?= esc($shown($column['percentage'])) ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($periods !== []): ?>

            <div class="flex flex-col gap-3">
                <button type="button" data-categories-toggle aria-expanded="false"
                    class="self-start rounded text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Show categories</button>

                <div class="flex flex-col gap-3" data-categories hidden>
                    <?php foreach ($periods as $period): ?>
                        <div class="flex flex-col gap-1">
                            <span class="text-xs font-bold uppercase tracking-wide text-[#767676]"><?= esc((string) $period['name']) ?></span>
                            <?php if ($period['categories'] === []): ?>
                                <span class="text-sm text-[#767676]">No categories</span>
                            <?php else: ?>
                                <ul class="flex flex-col gap-0.5 text-sm text-[#1A1C1E]">
                                    <?php foreach ($period['categories'] as $category): ?>
                                        <li><?= esc((string) $category['name']) ?> (<?= esc(grade_number($category['weight'])) ?>%) — <?= esc($shown($category['percentage'])) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-[#646464]">
            <?= view('components/status-badge', ['label' => $incText, 'tone' => $look['tone'], 'icon' => $look['icon']], ['saveData' => false]) ?>
            <?php if ($reason !== ''): ?>
                <span><?= esc($reason) ?></span>
            <?php endif; ?>
            <span>Passing is <?= esc(grade_number($summary['passing_percentage'])) ?>%</span>
            <?php if (! empty($summary['is_adjusted'])): ?>
                <span>Adjusted by your instructor</span>
            <?php endif; ?>
        </div>

        <a href="<?= esc($courseUrl, 'attr') ?>" class="rounded text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Open course grades →</a>
    </div>

</div>
