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
        // Below lg the widgets split into phone tabs (components/phone-tabs); the first tab listed shows first.
        'phoneTab' => [
            'key-metrics'           => 'today',
            'upcoming-tasks'        => 'today',
            'reminders'             => 'today',
            'profile-card'          => 'courses',
            'my-courses'            => 'courses',
            'quick-access'          => 'courses',
            'college-announcements' => 'updates',
            'announcements'         => 'updates',
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
        'phoneTab' => [
            'grading-queue'         => 'grade',
            'at-risk-students'      => 'grade',
            'profile-card'          => 'sections',
            'teaching-load'         => 'sections',
            'class-progress'        => 'sections',
            'content-status'        => 'sections',
            'quick-access'          => 'sections',
            'college-announcements' => 'updates',
        ],
    ],

    // The chairperson has its own bespoke layout below (flat cards, a merged
    // Needs Attention list, quick access as a top strip), not the generic
    // 12-column grid every other role shares: only its phone tabs are here.
    'chairperson' => [
        'phoneTab' => [
            'needs-attention'       => 'attention',
            'quick-access'          => 'program',
            'profile-card'          => 'program',
            'program-snapshot'      => 'program',
            'instructor-workload'   => 'program',
            'college-announcements' => 'updates',
            'announcements'         => 'updates',
            'audit-highlights'      => 'updates',
        ],
    ],
];

$layout = $layouts[$userRole] ?? $layouts['student'];

$identity = $layout['identity'] ?? [];
$cells = $layout['cells'] ?? [];
// Only a role with its own entry gets phone tabs.
$phoneTab = isset($layouts[$userRole]) ? ($layout['phoneTab'] ?? []) : [];

// Only "Today" can say something needs doing now; no widget tracks unseen updates, so Updates gets no dot.
$dueNow = array_filter(
    $widgets['upcoming-tasks']['tasks'] ?? [],
    static fn (array $task) => in_array($task['urgency'] ?? '', ['overdue', 'today'], true)
);

// The instructor's only "something needs doing" signal is submissions waiting in the grading queue.
// The chairperson's is the merged Needs Attention list.
$phoneTabs = match ($userRole) {
    'instructor' => [
        ['key' => 'grade',    'label' => 'To grade', 'dot' => ($widgets['grading-queue']['queue'] ?? []) !== []],
        ['key' => 'sections', 'label' => 'Sections', 'dot' => false],
        ['key' => 'updates',  'label' => 'Updates',  'dot' => false],
    ],
    'chairperson' => [
        ['key' => 'attention', 'label' => 'Attention', 'dot' => ($widgets['needs-attention']['items'] ?? []) !== []],
        ['key' => 'program',   'label' => 'Program',   'dot' => false],
        ['key' => 'updates',   'label' => 'Updates',   'dot' => false],
    ],
    default => [
        ['key' => 'today',   'label' => 'Today',   'dot' => $dueNow !== []],
        ['key' => 'courses', 'label' => 'Courses', 'dot' => false],
        ['key' => 'updates', 'label' => 'Updates', 'dot' => false],
    ],
};

/** The phone-tab panel attributes for a widget slug ('' when this role has no phone tabs). */
$panel = static function (string $slug) use ($phoneTab, $phoneTabs): string {
    if (! isset($phoneTab[$slug])) {
        return '';
    }

    return ' data-phone-panel="' . $phoneTab[$slug] . '"' . ($phoneTab[$slug] === $phoneTabs[0]['key'] ? ' data-phone-active' : '');
};
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-5 lg:h-full lg:min-h-0">

    <div class="flex flex-wrap justify-between items-center gap-3 shrink-0">

        <div class="flex flex-wrap items-center gap-x-3 text-fluid-h1 lg:text-3xl font-display">
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

    <?php if ($phoneTab !== []): ?>
        <?= view('components/phone-tabs', ['label' => 'Dashboard sections', 'tabs' => $phoneTabs], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (in_array($userRole, ['student', 'instructor', 'chairperson'], true)): ?>
        <?php $collegeStrip = $this->include('pages/dashboard/partials/college-announcements'); ?>

        <?php // The strip prints nothing when there is nothing to say (strip_tags: CI_DEBUG wraps it in view comments); an empty panel would add a gap. ?>
        <?php if ($panel('college-announcements') !== '' && trim(strip_tags($collegeStrip)) !== ''): ?>
            <div class="shrink-0"<?= $panel('college-announcements') ?>><?= $collegeStrip ?></div>
        <?php else: ?>
            <?= $collegeStrip ?>
            <?php // The strip is the instructor's whole Updates tab: with no dean posts the tab still needs its empty copy. ?>
            <?php if (count(array_keys($phoneTab, 'updates', true)) === 1 && $panel('college-announcements') !== ''): ?>
                <div class="lg:hidden shrink-0 flex flex-col items-center gap-1 rounded-3xl border border-white/30 bg-white/20 px-4 py-10 text-center shadow-xl"<?= $panel('college-announcements') ?>>
                    <span class="text-sm font-medium text-gray-700">No announcements</span>
                    <span class="text-xs text-gray-600">Posts from your college will appear here.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

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

        <div<?= $panel('quick-access') ?>><?= $renderChairWidget('quick-access') ?></div>

        <!--
            From lg up this fills the viewport (no page scroll); lists scroll inside their cards.
            Below lg the rail and the main column dissolve (max-lg:contents) into one stack, so a card in a hidden
            tab leaves no gap; the cards size to their content (capped in their partials).
        -->
        <div class="flex max-lg:flex-col gap-5 lg:flex-1 lg:min-h-0">

            <!-- Identity rail: profile + program snapshot + workload (takes the leftover height). -->
            <div class="w-full lg:w-80 shrink-0 flex flex-col gap-5 lg:min-h-0 max-lg:contents">
                <div class="shrink-0"<?= $panel('profile-card') ?>><?= $renderChairWidget('profile-card') ?></div>
                <div class="shrink-0"<?= $panel('program-snapshot') ?>><?= $renderChairWidget('program-snapshot') ?></div>
                <div class="lg:flex-1 lg:min-h-0"<?= $panel('instructor-workload') ?>><?= $renderChairWidget('instructor-workload') ?></div>
            </div>

            <!-- Main column: one merged attention list, then announcements + recent actions side by side. -->
            <div class="flex-1 min-w-0 flex flex-col gap-5 lg:min-h-0 max-lg:contents">

                <div class="lg:flex-3 lg:min-h-0"<?= $panel('needs-attention') ?>><?= $renderChairWidget('needs-attention') ?></div>

                <?php // Both cards are Updates, so the pair is one panel (side by side from md). ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 lg:flex-2 lg:min-h-0"<?= $panel('announcements') ?>>
                    <div class="min-w-0 lg:min-h-0"><?= $renderChairWidget('announcements') ?></div>
                    <div class="min-w-0 lg:min-h-0"><?= $renderChairWidget('audit-highlights') ?></div>
                </div>

            </div>

        </div>

    <?php else: ?>

        <div class="grid grid-cols-12 gap-5 lg:flex-1 lg:min-h-0 lg:grid-rows-2">

            <!--
                Identity column. The first widget is the profile strip and sizes
                to its content; whatever follows takes the rest of the column.
                With phone tabs its two widgets sit in different tabs, so below lg the column dissolves
                (max-lg:contents) and each widget is its own full-width grid item, leaving no empty gap.
            -->
            <div class="col-span-12 <?= $identity['span'] ?> min-h-0 flex flex-col gap-4<?= $phoneTab !== [] ? ' max-lg:contents' : '' ?>">

                <?php foreach ($identity['widgets'] as $position => $slug): ?>

                    <div class="<?= $position === 0 ? 'shrink-0' : 'flex-1 min-h-0' ?><?= $phoneTab !== [] ? ' max-lg:col-span-12' : '' ?>"<?= $panel($slug) ?>>
                        <?= $renderWidget($slug) ?>
                    </div>

                <?php endforeach; ?>

            </div>

            <?php foreach ($cells as $cell): ?>

                <div class="col-span-12 <?= $cell['span'] ?> min-h-0"<?= $panel($cell['slug']) ?>>
                    <?= $renderWidget($cell['slug']) ?>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
