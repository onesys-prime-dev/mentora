<?php
/**
 * The At risk tab: students across every section who are at risk for either reason, by grade (failing so far,
 * failed, INC) or by activity (the dashboard's rule), one row per student and section. Each reason is written
 * out; its colour only repeats it. The table scrolls sideways inside its card with the name column pinned.
 *
 * @var string                     $area
 * @var list<array<string, mixed>> $atRisk
 * @var string|null                $reason
 */
$chip = [
    'grade'    => 'bg-[#FDEDEC] text-[#C33939]',
    'inc'      => 'bg-[#FFF8E8] text-[#946200]',
    'activity' => 'bg-[#EEF1F8] text-[#3D3D3D]',
];
?>

<section class="flex flex-col gap-3" aria-labelledby="grades-at-risk">

    <h2 id="grades-at-risk" class="sr-only">At risk</h2>

    <?php if ($atRisk === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'check',
            'title' => 'No one is at risk',
            'text'  => $reason !== null ? 'No one is at risk for this reason. Pick "All reasons" to see everyone.' : 'Students failing so far, with an INC, or falling behind show here.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <div class="overflow-x-auto rounded-2xl border border-[#E3E6EE]">
            <table class="w-full min-w-[34rem] text-left text-sm">
                <thead class="bg-[#F6F7FA] text-xs font-semibold uppercase tracking-wide text-[#646464]">
                    <tr>
                        <th scope="col" class="sticky left-0 bg-[#F6F7FA] px-4 py-3">Student</th>
                        <th scope="col" class="px-4 py-3">Section</th>
                        <th scope="col" class="px-4 py-3">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E3E6EE]">
                    <?php foreach ($atRisk as $row): ?>
                        <tr class="bg-white" data-risk-row data-offering-id="<?= (int) $row['offering_id'] ?>" data-student-id="<?= (int) $row['student_id'] ?>">
                            <th scope="row" class="sticky left-0 bg-white px-4 py-3 font-semibold text-[#1A1C1E]"><?= esc($row['name']) ?></th>
                            <td class="px-4 py-3">
                                <a href="<?= esc(site_url("{$area}/courses/{$row['offering_id']}/{$row['slug']}/grades")) ?>" class="rounded font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"><?= esc($row['section']) ?></a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="flex flex-wrap gap-1.5">
                                    <?php foreach ($row['reasons'] as $r): ?>
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $chip[$r['kind']] ?? $chip['activity'] ?>" data-reason="<?= esc($r['kind'], 'attr') ?>"><?= esc($r['text']) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</section>
