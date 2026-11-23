<?php
/**
 * The course Groups tab (group activities, screen 1): the offering's group sets and, beside them, the chosen
 * set's groups. The set detail is drawn by resources/js/pages/courses/groups from the group-sets JSON.
 *
 * @var list<array<string, mixed>> $groupSets active sets with group_count, member_count, below_min
 * @var int    $poolSize   students who can be placed (enrolled or completed)
 * @var int    $maxSets
 * @var bool   $canEdit    false: no write control is drawn (dean, finalized grades, closed course, no permission)
 * @var string $lockReason why it is read-only, or ''
 */
$primary = 'inline-flex items-center justify-center gap-2 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 pointer-coarse:min-h-11';
$newSet  = '<button type="button" class="' . $primary . '" data-group-action="new-set">' . icon('users', 'h-4 w-4 text-current') . 'New group set</button>';
$count   = count($groupSets);
?>

<div class="flex flex-col gap-6 p-5 sm:p-8 lg:p-10" data-groups-page data-can-edit="<?= $canEdit ? '1' : '0' ?>" data-pool-size="<?= (int) $poolSize ?>">

    <?= view('components/page-header', [
        'icon'        => 'users',
        'title'       => 'Group sets',
        'description' => 'Reusable groupings of this course\'s students. ' . $count . ' of ' . $maxSets . ' sets used.',
        // In the empty state the first action is the empty state's own button: one primary per view.
        'actions'     => $canEdit && $count > 0 ? $newSet : '',
    ], ['saveData' => false]) ?>

    <?php if ($lockReason !== ''): ?>
        <?= view('components/lock-note', ['kind' => 'closed', 'text' => $lockReason], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($count === 0): ?>

        <?= view('components/empty-state', [
            'icon'   => 'users',
            'title'  => 'No group sets yet',
            'text'   => 'A group set splits this course\'s students into groups you can use again and again.',
            'action' => $canEdit ? $newSet : '',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-5">

            <div class="min-w-0 xl:col-span-2">
                <?= view('pages/courses/features/groups/partials/set-list', ['groupSets' => $groupSets, 'poolSize' => $poolSize], ['saveData' => false]) ?>
            </div>

            <section class="min-w-0 rounded-2xl border border-gray-200 bg-white p-4 sm:p-5 xl:col-span-3" aria-label="Group set detail" data-group-set-detail></section>

        </div>

    <?php endif; ?>

    <p class="sr-only" aria-live="polite" data-groups-live></p>

    <?php if ($canEdit): ?>
        <?= view('pages/courses/features/groups/partials/dialogs', [], ['saveData' => false]) ?>
    <?php endif; ?>

</div>
