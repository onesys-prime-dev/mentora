<?php
/**
 * The Gradebook tab: a card per section (scrolling sideways on a phone), the chosen section's action bar, and
 * an empty container for its grid, which the page script (resources/js/pages/grades/grades.js) fills from the
 * section's roster endpoint. Rendering the page never opens the roster, so it never creates a grading scheme.
 *
 * Each card says where its section stands in a word as well as a colour, and carries what the page script needs
 * to redraw the action bar when another card is picked without a reload (data-note, data-slug, data-state).
 * data-has-scheme="0" marks a section that has no grading scheme yet: its grid is never fetched (that would
 * create one), the grid area says so and sends the instructor to the course, where setting it up is a step of
 * its own.
 *
 * @var string                     $area
 * @var int|null                   $termId
 * @var list<array<string, mixed>> $sections
 * @var array<string, mixed>|null  $selected
 * @var array<string, int>         $summary
 * @var bool                       $canManage
 * @var Closure                    $link
 */

// How a state looks: its dot, and the word or badge that says the same thing without the colour.
$look = static fn (array $s): array => match ($s['state']) {
    'ready'         => ['dot' => 'bg-[#2E7D4F]', 'word' => 'Ready to finalize', 'class' => 'font-semibold text-[#2E7D4F]'],
    'waiting'       => ['dot' => 'bg-[#D99A00]', 'word' => $s['waiting'] . ' waiting', 'class' => 'rounded-full bg-[#FFF8E8] px-2 py-0.5 font-semibold text-[#946200]'],
    'blocked'       => ['dot' => 'bg-[#D99A00]', 'word' => $s['note'], 'class' => 'font-semibold text-[#946200]'],
    'finalized'     => ['dot' => 'bg-gray-400', 'word' => 'Finalized', 'class' => 'font-semibold text-[#646464]'],
    default         => ['dot' => 'bg-gray-400', 'word' => 'Not published yet', 'class' => 'font-semibold text-[#646464]'],
};

$secondary = 'inline-flex min-h-9 items-center gap-2 rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55';
$primary   = 'inline-flex min-h-9 items-center gap-2 rounded-full bg-brand px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2';

// A section without a grading scheme: no grid (fetching it would create one), the way to the course instead.
$noScheme = static fn (string $href): string => '<div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center" data-no-scheme>'
    . icon('grades', 'h-10 w-10 text-gray-300')
    . '<span class="text-sm font-semibold text-[#646464]">' . esc("Grading isn't set up for this section yet.") . '</span>'
    . '<a href="' . esc($href) . '" class="' . $secondary . '" data-no-scheme-open>Open in course →</a>'
    . '</div>';
?>

<?php if ($sections === []): ?>

    <?= view('components/empty-state', [
        'icon'  => 'grades',
        'title' => 'No sections this term',
        'text'  => 'When you are assigned to teach a section, its gradebook appears here.',
    ], ['saveData' => false]) ?>

<?php else: ?>

    <section class="flex flex-col gap-3" aria-labelledby="grades-your-sections">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="grades-your-sections" class="text-base font-bold text-[#1A1C1E]">Your sections</h2>

            <?php if ($canManage): ?>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= esc(site_url("{$area}/grades/download") . '?term=' . (int) $termId) ?>" class="<?= $secondary ?>" data-download-all>Download all</a>
                    <?php if ($summary['ready'] > 0): ?>
                        <button type="button" class="<?= $primary ?>" data-finalize-ready data-dialog-open="finalize-ready-dialog">
                            Finalize ready sections
                            <span class="rounded-full bg-white/25 px-2 py-0.5 text-xs font-bold" data-ready-count><?= (int) $summary['ready'] ?></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <nav aria-label="Your sections" class="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pt-1 pb-2" data-section-cards>
            <?php foreach ($sections as $s): ?>
                <?php
                $on   = $selected !== null && $s['offering_id'] === $selected['offering_id'];
                $view = $look($s);
                ?>
                <a href="<?= esc($link(['section' => $s['offering_id']])) ?>"
                   class="flex w-48 shrink-0 snap-start flex-col gap-1 rounded-2xl border px-4 py-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 <?= $on ? 'border-brand bg-[#F5F8FD] ring-1 ring-brand' : 'border-[#E3E6EE] bg-white hover:border-brand/40 hover:shadow-md' ?>"
                   data-section-card="<?= (int) $s['offering_id'] ?>"
                   data-label="<?= esc($s['course_code'] . '-' . $s['section_code'], 'attr') ?>"
                   data-slug="<?= esc($s['slug'], 'attr') ?>"
                   data-state="<?= esc($s['state'], 'attr') ?>"
                   data-offering-status="<?= esc($s['offering_status'], 'attr') ?>"
                   data-note="<?= esc($s['note'], 'attr') ?>"
                   data-has-scheme="<?= $s['has_scheme'] ? '1' : '0' ?>"
                   <?= $on ? 'aria-current="true"' : '' ?>>
                    <span class="flex items-center gap-2 text-sm font-bold text-[#1A1C1E]">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full <?= $view['dot'] ?>" aria-hidden="true"></span>
                        <?= esc($s['course_code'] . '-' . $s['section_code']) ?>
                    </span>
                    <span class="truncate text-xs text-[#646464]" title="<?= esc($s['course_title'], 'attr') ?>"><?= esc($s['course_title']) ?></span>
                    <span class="text-xs text-[#646464]"><?= (int) $s['students'] ?> <?= $s['students'] === 1 ? 'student' : 'students' ?></span>
                    <span class="mt-1 w-fit text-xs <?= $view['class'] ?>" data-card-state><?= esc($view['word']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

    </section>

    <?php if ($canManage && $summary['ready'] > 0): ?>
        <?php
        // Finalize ready sections: the confirm names what will be finalized; the page script posts it and
        // shows what was done and what was skipped in the same dialog.
        $readyCards = array_filter($sections, static fn (array $s): bool => $s['state'] === 'ready');
        $label      = static fn (array $s): string => $s['course_code'] . '-' . $s['section_code'];
        $toFinalize = array_map($label, array_filter($readyCards, static fn (array $s): bool => $s['offering_status'] === 'active'));
        $closed     = array_map($label, array_filter($readyCards, static fn (array $s): bool => $s['offering_status'] !== 'active'));

        $text = $toFinalize === []
            ? 'Every ready section is closed, so nothing can be finalized.'
            : 'Finalize the grades of ' . implode(', ', $toFinalize) . '? Each section is checked again first; one with new work waiting is skipped. Students then see their final grades, and changing them means reopening the section with a reason.';

        if ($toFinalize !== [] && $closed !== []) {
            $text .= ' ' . implode(', ', $closed) . (count($closed) === 1 ? ' is' : ' are') . ' closed and stays as it is.';
        }
        ?>
        <?= view('components/confirm', [
            'id'           => 'finalize-ready-dialog',
            'title'        => 'Finalize ready sections',
            'text'         => $text,
            'action'       => site_url("{$area}/grades/finalize-ready"),
            'confirmLabel' => 'Finalize',
            'fields'       => '<input type="hidden" name="term" value="' . (int) $termId . '">',
        ], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($selected !== null): ?>
        <?php
        $id       = (int) $selected['offering_id'];
        $reports  = site_url("{$area}/api/courses/{$id}/grades/report");
        $inCourse = site_url("{$area}/courses/{$id}/{$selected['slug']}/grades");
        ?>

        <section class="flex flex-col gap-4" aria-label="<?= esc('Gradebook of ' . $selected['course_code'] . '-' . $selected['section_code'], 'attr') ?>">

            <div class="flex flex-col gap-3 rounded-2xl border border-[#E3E6EE] px-4 py-3 md:flex-row md:items-center md:justify-between" data-section-actions data-section-id="<?= $id ?>">

                <p class="text-sm text-[#3D3D3D]">
                    <span class="font-bold text-[#1A1C1E]" data-action-label><?= esc($selected['course_code'] . '-' . $selected['section_code']) ?></span>
                    · <span data-action-note><?= esc($selected['note']) ?></span>
                </p>

                <div class="flex flex-wrap items-center gap-2">

                    <?php if ($canManage): ?>
                        <details class="relative" data-menu data-download-menu>
                            <summary class="<?= $secondary ?> cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                Download <span aria-hidden="true">&#9662;</span>
                            </summary>
                            <div class="absolute left-0 z-20 mt-2 flex w-64 flex-col rounded-2xl border border-gray-200 bg-white p-1.5 shadow-lg md:left-auto md:right-0">
                                <a href="<?= esc($reports . '/class?format=xlsx') ?>" class="flex flex-col rounded-lg px-3 py-2 text-sm text-[#1A1C1E] transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-download-link="class">
                                    <span>Class sheet (Excel)</span>
                                    <span class="text-xs text-[#646464]">Every student's scores and grade, to read, print and file.</span>
                                </a>
                                <a href="<?= esc($reports . '/summary?format=pdf') ?>" class="flex flex-col rounded-lg px-3 py-2 text-sm text-[#1A1C1E] transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-download-link="summary">
                                    <span>Class summary (PDF)</span>
                                    <span class="text-xs text-[#646464]">The grade distribution on one page.</span>
                                </a>
                            </div>
                        </details>

                        <?php // Kept for a finalized section too (hidden), so the page script can show it on a card switch. Hidden
                              // without a scheme as well: its dialog reads the checklist, which would create one. ?>
                        <button type="button" class="<?= $secondary ?>" data-open-finalize <?= $selected['state'] === 'finalized' || $selected['offering_status'] !== 'active' || ! $selected['has_scheme'] ? 'hidden' : '' ?>>Finalize</button>
                    <?php endif; ?>

                    <a href="<?= esc($inCourse) ?>" class="<?= $secondary ?>" data-open-in-course>Open in course →</a>

                </div>

            </div>

            <?php // Filled by the page script; until then (and while a card switch loads) the shape of what is coming. A
                  // section without a scheme gets the sentence instead (section-switch.js draws the same one on a switch). ?>
            <div class="min-w-0" data-grades-grid data-api-base="<?= esc(site_url("{$area}/api/courses/{$id}"), 'attr') ?>" data-section-id="<?= $id ?>" data-has-scheme="<?= $selected['has_scheme'] ? '1' : '0' ?>">
                <?php if ($selected['has_scheme']): ?>
                    <?= view('components/skeleton', ['rows' => 6, 'label' => 'Loading the gradebook'], ['saveData' => false]) ?>
                <?php else: ?>
                    <?= $noScheme($inCourse) ?>
                <?php endif; ?>
            </div>

            <?php // What a card switch draws for a section without a scheme; the script sets the link's address. ?>
            <template data-no-scheme-template><?= $noScheme('#') ?></template>

        </section>

        <?php if ($canManage): ?>
            <?= view('pages/courses/features/grades/partials/modals/grade-finalize', [], ['saveData' => false]) ?>
        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>
