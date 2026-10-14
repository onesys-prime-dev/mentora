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

/**
 * The chairperson dashboard's own flat-card visual style lives in its own
 * partials/chairperson/ subfolder (see its partials' docblocks) rather
 * than forking the shared ones every other role still uses — this reads
 * from the same $widgets data, just a different template path.
 */
$renderChairWidget = function (string $slug) use ($widgets) {
    if (! isset($widgets[$slug])) {
        return '';
    }

    $this->setVar('data', $widgets[$slug]);

    return $this->include('pages/dashboard/partials/chairperson/' . $slug);
};

// A multi-role user's dashboard must match the role they're actually
// acting as, not just whichever group happens to sort first — otherwise
// a chairperson who also teaches would see the instructor layout.
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

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

    // Chairperson isn't in this map — it has its own bespoke layout below
    // (flat cards, a merged Needs Attention list, quick access as a top
    // strip), not the generic 12-column grid every other role shares.
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
            <?php if ($academic_term !== []): ?>

                <span class="font-bold">
                    A.Y <?= esc($academic_term['academic_year']) ?>
                </span>
                <span class="">-</span>
                <span class="">
                    <?= esc($academic_term['name']) ?>
                </span>

            <?php else: ?>

                <!--
                    No active term is a real state: between semesters, or
                    before one is opened. It is not an error to crash on.
                -->
                <span class="font-bold text-gray-600">
                    No active term
                </span>

            <?php endif; ?>
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
    <?php if ($userRole === 'chairperson'): ?>

        <?= $renderChairWidget('quick-access') ?>

        <!-- From lg up this fills the viewport (no page scroll); lists scroll inside their cards. -->
        <div class="flex flex-wrap lg:flex-nowrap gap-5 lg:flex-1 lg:min-h-0">

            <!-- Identity rail: profile + program snapshot + workload (takes the leftover height). -->
            <div class="w-full lg:w-80 shrink-0 flex flex-col gap-5 lg:min-h-0">
                <div class="shrink-0"><?= $renderChairWidget('profile-card') ?></div>
                <div class="shrink-0"><?= $renderChairWidget('program-snapshot') ?></div>
                <div class="lg:flex-1 lg:min-h-0"><?= $renderChairWidget('instructor-workload') ?></div>
            </div>

            <!-- Main column: one merged attention list, then announcements + recent actions side by side. -->
            <div class="flex-1 min-w-0 flex flex-col gap-5 lg:min-h-0">

                <div class="h-80 lg:h-auto lg:flex-3 lg:min-h-0"><?= $renderChairWidget('needs-attention') ?></div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 lg:flex-2 lg:min-h-0">
                    <div class="h-80 lg:h-auto min-w-0 lg:min-h-0"><?= $renderChairWidget('announcements') ?></div>
                    <div class="h-80 lg:h-auto min-w-0 lg:min-h-0"><?= $renderChairWidget('audit-highlights') ?></div>
                </div>

            </div>

        </div>

    <?php else: ?>

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

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
