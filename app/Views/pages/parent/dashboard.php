<?php
/**
 * The parent's dashboard for one child.
 *
 * @var list<array{student_id: int, name: string, institution_id: int}> $children  every linked child (for the switcher)
 * @var array<string, mixed>                                           $dashboard ParentDashboardService::forChild()
 */
$child = $dashboard['child'];

$actions = '';

if (count($children) > 1) {
    $actions .= view('pages/parent/partials/child-switcher', ['children' => $children, 'selected' => (int) $child['id']], ['saveData' => false]);
}

$actions .= '<a href="' . esc(site_url('parent/children/' . (int) $child['id'] . '/calendar')) . '" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">View calendar</a>';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Parent',
        'icon'        => 'user',
        'description' => 'How ' . $child['name'] . ' is doing.',
        'actions'     => $actions,
    ], ['saveData' => false]) ?>

    <?= view('pages/parent/partials/attention', ['items' => $dashboard['attention']], ['saveData' => false]) ?>

    <section class="flex flex-col gap-3" aria-labelledby="parent-courses">
        <h2 id="parent-courses" class="text-sm font-bold text-brand">Courses this term</h2>

        <?php if ($dashboard['courses'] === []): ?>
            <?= view('components/empty-state', [
                'icon'  => 'hat',
                'title' => (strtok($child['name'], ' ') ?: 'Your child') . ' is not enrolled in any course this term',
                'text'  => 'Courses appear here once your child is enrolled.',
            ], ['saveData' => false]) ?>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <?php foreach ($dashboard['courses'] as $course): ?>
                    <?= view('pages/parent/partials/course-card', ['course' => $course, 'past' => false], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?= view('pages/parent/partials/deadlines', ['deadlines' => $dashboard['deadlines']], ['saveData' => false]) ?>

    <?php if ($dashboard['past'] !== []): ?>
        <?= view('pages/parent/partials/past-terms', ['past' => $dashboard['past']], ['saveData' => false]) ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
