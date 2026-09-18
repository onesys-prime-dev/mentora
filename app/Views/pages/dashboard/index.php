<?php
/**
 * Renders a widget partial only if the service actually produced
 * data for this slug (i.e. the current user's role has access to it).
 *
 * Note: CodeIgniter's $this->include($view, $options) does NOT accept
 * arbitrary data via $options — that array is only for cache settings.
 * setVar() is the correct way to inject a variable into the partial.
 */
$renderWidget = function (string $slug) use ($widgets) {
    if (! isset($widgets[$slug])) {
        return '';
    }

    $this->setVar('data', $widgets[$slug]);

    return $this->include('pages/dashboard/partials/' . $slug);
};

$userRole = esc($user->getGroups()[0]);

/**
 * Per-role grid plan.
 *
 * `identity` is the left column: which widgets stack in it and how many grid
 * rows it occupies. `cells` are the remaining widgets in reading order, each
 * with its own width. Every row must total 12 columns.
 *
 *   student     row 1  identity 3 + 5 + 4          row 2  5 + 4 + 3
 *   instructor  row 1  identity 3 (spans both) + 5 + 4
 *               row 2  3 + 3 + 3
 *
 * The service decides whether a role may SEE a widget; this decides where the
 * widget goes. A slug registered in the service but absent here never renders.
 */
$layouts = [
    'student' => [
        'identity' => [
            'span'    => 'lg:col-span-3',
            'widgets' => ['profile-card', 'key-metrics'],
        ],
        'cells' => [
            ['slug' => 'upcoming-tasks', 'span' => 'lg:col-span-5'],
            ['slug' => 'my-courses',     'span' => 'lg:col-span-4'],
            ['slug' => 'announcements',  'span' => 'lg:col-span-5'],
            ['slug' => 'reminders',      'span' => 'lg:col-span-4'],
            ['slug' => 'quick-access',   'span' => 'lg:col-span-3'],
        ],
    ],

    /*
     * Instructors get no metric tiles — three counters that repeat what the
     * widgets beside them already say. The identity column spans both rows
     * instead, so Teaching Load takes the full height of the left side and
     * the section list gets room to breathe.
     */
    'instructor' => [
        'identity' => [
            'span'    => 'lg:col-span-3 lg:row-span-2',
            'widgets' => ['profile-card', 'teaching-load'],
        ],
        'cells' => [
            ['slug' => 'grading-queue',    'span' => 'lg:col-span-5'],
            ['slug' => 'at-risk-students', 'span' => 'lg:col-span-4'],
            ['slug' => 'class-progress',   'span' => 'lg:col-span-3'],
            ['slug' => 'content-status',   'span' => 'lg:col-span-3'],
            ['slug' => 'quick-access',     'span' => 'lg:col-span-3'],
        ],
    ],
];

$layout = $layouts[$userRole] ?? $layouts['student'];

$identity = $layout['identity'];
$cells = $layout['cells'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-5 lg:h-full lg:min-h-0">

    <div class="flex justify-between items-center shrink-0">

        <div class="flex items-center gap-3 text-3xl font-display">
            <span class="font-bold">A.Y 2026-2027</span>
            <span class="">-</span>
            <span class="">1st Semester</span>
        </div>

        <?= $this->include('components/search') ?>

    </div>

    <!--
        12-column dashboard grid, two equal rows.

        From `lg` up the grid takes whatever height the shell leaves it
        (flex-1 + min-h-0) and splits it between two equal rows, so the page
        itself never scrolls — a card with more content than fits scrolls
        inside its own body instead. Each cell carries min-h-0 so its card
        can actually shrink rather than forcing the row taller.

        Below `lg` the rows are dropped and the cards stack and scroll
        normally; two fixed rows would squash six stacked cards.
    -->
    <div class="grid grid-cols-12 gap-5 lg:flex-1 lg:min-h-0 lg:grid-rows-2">

        <!--
            Identity column. The first widget is the profile strip and sizes
            to its content; whatever follows takes the rest of the column.
        -->
        <div class="col-span-12 <?= $identity['span'] ?> min-h-0 flex flex-col gap-4">

            <?php foreach ($identity['widgets'] as $position => $slug): ?>

                <div class="<?= $position === 0 ? 'shrink-0' : 'flex-1 min-h-0' ?>">
                    <?= $renderWidget($slug) ?>
                </div>

            <?php endforeach; ?>

        </div>

        <?php foreach ($cells as $cell): ?>

            <div class="col-span-12 <?= $cell['span'] ?> min-h-0">
                <?= $renderWidget($cell['slug']) ?>
            </div>

        <?php endforeach; ?>

    </div>

</div>

<?= $this->endSection() ?>
