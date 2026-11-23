<?php
/**
 * The Assessments tab for a parent: what was given, when it is due and where
 * it stands, by type. A status word only: no score, no attempt, nothing to
 * open. A group activity also names the child's group and, once graded, the child's own grade and its parts
 * (group activities 4F); never another student's.
 *
 * @var array<string, list<array{title: string, due: string, status: string, tone: string, group: ?array<string, mixed>}>> $groups by assessment type
 */
$tones  = ['success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'info' => 'info'];
$points = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
?>

<div class="p-4 sm:p-10 flex flex-col gap-8">

    <?= view('components/page-header', ['icon' => 'assessments', 'title' => 'Assessments'], ['saveData' => false]) ?>

    <?php if ($groups === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'assessments',
            'title' => 'No assessments yet',
            'text'  => 'Quizzes and assignments appear here once the instructor publishes them.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <?php foreach ($groups as $type => $rows): ?>
            <section class="flex flex-col gap-3" aria-label="<?= esc($type) ?>" data-parent-assessments="<?= esc($type) ?>">
                <h3 class="text-sm font-bold text-brand"><?= esc($type) ?></h3>

                <ul class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-[#A9A9A9]/50 bg-white">
                    <?php foreach ($rows as $row): ?>
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-5">
                            <span class="min-w-0 flex-1 font-semibold text-[#1A1C1E]"><?= esc($row['title']) ?></span>
                            <span class="text-sm text-[#646464]"><?= esc($row['due']) ?></span>
                            <?= view('components/status-badge', ['label' => $row['status'], 'tone' => $tones[$row['tone']] ?? 'neutral'], ['saveData' => false]) ?>

                            <?php if (!empty($row['group'])): ?>
                                <?php $group = $row['group']; $grade = $group['grade']; ?>
                                <div class="flex w-full flex-col gap-1.5 text-sm text-[#646464]" data-parent-group>
                                    <span class="wrap-anywhere">Group: <?= esc($group['name']) ?> (<?= esc(implode(', ', $group['members'])) ?>)</span>

                                    <?php if ($grade !== null && $grade['excused']): ?>
                                        <span><?= view('components/status-badge', ['label' => 'Excused', 'tone' => 'neutral'], ['saveData' => false]) ?></span>
                                    <?php elseif ($grade !== null): ?>
                                        <span><?= view('components/status-badge', ['label' => 'Grade ' . $points($grade['final']) . ' / ' . $points($grade['total']), 'tone' => 'success'], ['saveData' => false]) ?></span>
                                        <span class="text-xs">
                                            <?= esc(implode(' + ', array_filter([
                                                $grade['group'] !== null ? 'Group part ' . $points($grade['group']) : null,
                                                $grade['individual'] !== null ? 'individual part ' . $points($grade['individual']) : null,
                                            ]))) ?><?php if ($grade['adjustment'] != 0.0): ?> · Adjustment <?= esc(($grade['adjustment'] > 0 ? '+' : '') . $points($grade['adjustment'])) ?><?= $grade['reason'] !== '' ? ': ' . esc($grade['reason']) : '' ?><?php endif; ?>
                                        </span>
                                        <span class="text-xs">Other students' scores and adjustments are not shown.</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>

</div>
