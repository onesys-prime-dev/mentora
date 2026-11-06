<?php
/**
 * The Program tab's Sections list (spec §8, "Phase 4 layout"): the finalization tracker with "Not finalized only",
 * the table of the program's sections (sortable, 25 a page, scrolling sideways inside its card on a phone with the
 * section column pinned), Nudge and Download program, then the chosen section's grid, read-only, under an action
 * bar with Edit as chairperson and Open in course →.
 *
 * Each row's section link carries what the page script needs to open that section without a reload (data-section-id,
 * data-label, data-slug, data-state, data-has-scheme, data-offering-status, data-note) and what the nudge words its
 * message from (data-instructors: who it may go to, never the chairperson themselves; data-waiting, data-oldest-days,
 * data-term-end). The action bar carries the same for the section shown below, which may be on another page or
 * filtered out of this one: Nudge reads it from there. The grid container stays empty:
 * the page script fetches the grid, read-only. Rendering never opens the roster, so it never creates a scheme.
 *
 * Every state is said in a word beside its dot; a not-published section shows no average (its numbers are not
 * final enough to compare).
 *
 * The dean's College view (area "dean", spec §9 "Phase 5 layout") is this list over the college's sections:
 * the strip in place of the tracker ("Finalized {f} of {n} · Signed off {s} · Override requests waiting: {o}", the
 * last two linking to Sign-off and Approvals only where stripLinks gives an address), a Signed off column, Download
 * college, and never Edit as chairperson: the grid is always read-only. Without courses.view (canOpen false) the
 * dean's course API would refuse the grid, so neither the grid nor Open in course is drawn.
 *
 * @var string                                                                $area
 * @var string                                                                $base
 * @var int|null                                                              $termId
 * @var array{rows: list<array<string, mixed>>, total: int, tracker?: array<string, int>, strip?: array<string, int>} $program
 * @var array{q: string, nf: bool, sort: ?string, dir: ?string, page: int}    $state
 * @var string|null                                                           $termEnd  the term's last day, Y-m-d
 * @var array<string, mixed>|null                                             $selected the section whose grid is shown
 * @var bool                                                                  $canManage Edit as chairperson
 * @var bool                                                                  $canNudge
 * @var Closure                                                               $link     this list's links: its tab and the term, then what each is for
 * @var array<string, mixed>                                                  $listQuery the address that names this list, for the pager
 * @var bool                                                                  $canOpen  the grid and Open in course (the course API's permission)
 * @var array{signed_off?: ?string, requests?: ?string}                       $stripLinks the dean's strip links; null is plain text
 * @var int|null                                                              $programId the dean's program picked; null is every program
 */

$dean    = $area === 'dean';
$tracker = $dean ? $program['strip'] : $program['tracker'];
$rows    = $program['rows'];
$sort    = $state['sort'] ?? 'section';
$dir     = $state['dir'] ?? 'asc';

// What the list's links keep: the search, the filter and the sort; a row keeps the page as well.
$keep     = ['q' => $state['q'], 'nf' => $state['nf'] ? 1 : null, 'sort' => $state['sort'], 'dir' => $state['dir']];
$pageKeep = $keep + ['page' => $state['page'] > 1 ? $state['page'] : null];

$label = static fn (array $row): string => $row['course_code'] . '-' . $row['section_code'];

// How a state looks: its dot and its word.
$look = static fn (string $state): array => match ($state) {
    'ready'     => ['dot' => 'bg-[#2E7D4F]', 'word' => 'Ready', 'class' => 'text-[#2E7D4F]'],
    'waiting'   => ['dot' => 'bg-[#D99A00]', 'word' => 'Waiting', 'class' => 'text-[#946200]'],
    'blocked'   => ['dot' => 'bg-[#D99A00]', 'word' => 'Blocked', 'class' => 'text-[#946200]'],
    'finalized' => ['dot' => 'bg-gray-400', 'word' => 'Finalized', 'class' => 'text-[#646464]'],
    default     => ['dot' => 'bg-gray-300', 'word' => 'Not published', 'class' => 'text-[#646464]'],
};

$columns = ['section' => 'Section', 'instructor' => 'Instructor', 'average' => 'Average', 'at_risk' => 'At risk', 'waiting' => 'Waiting', 'status' => 'Status']
    + ($dean ? ['signed_off' => 'Signed off'] : []);

// The At risk column counts grade standing only; the At risk list also has students falling behind on work.
$explain = ['at_risk' => 'Students failing or with an Incomplete'];

// Edit as chairperson: the chairperson's only, where they may grade; the dean never edits here.
$canEdit = $canManage && ! $dean;

// Nudge and Edit as chairperson have nothing to do in a finalized section.
$selectedFinalized = $selected !== null && $selected['state'] === 'finalized';

$secondary = 'inline-flex min-h-9 items-center justify-center gap-2 rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55';
$primary   = 'inline-flex min-h-9 items-center justify-center gap-2 rounded-full bg-brand px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2';

// A section without a grading scheme: no grid (fetching it would create one), the way to the course instead.
$noScheme = static fn (string $href): string => '<div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center" data-no-scheme>'
    . icon('grades', 'h-10 w-10 text-gray-300')
    . '<span class="text-sm font-semibold text-[#646464]">' . esc("Grading isn't set up for this section yet.") . '</span>'
    . '<a href="' . esc($href) . '" class="' . $secondary . '" data-no-scheme-open>Open in course →</a>'
    . '</div>';

// The tracker's bar: finalized, ready and blocked as parts of the whole term (the dean's: signed off, then finalized).
$share = static fn (int $count): string => $tracker['total'] > 0 ? rtrim(rtrim(number_format($count / $tracker['total'] * 100, 2, '.', ''), '0'), '.') : '0';

// A count of the dean's strip: a link to the page where it is acted on, or plain text without the permission there.
$stripPart = static function (string $text, ?string $href, string $key, ?string $note = null): string {
    // $note: what the count covers, for a pointer and a screen reader, without changing the line's words.
    $noteAttrs = $note === null ? '' : ' title="' . esc($note, 'attr') . '"';

    if ($href === null) {
        return '<span data-strip-' . $key . $noteAttrs . '>' . esc($text) . '</span>';
    }

    if ($note !== null) {
        $noteAttrs .= ' aria-label="' . esc($text . ' (' . $note . ')', 'attr') . '"';
    }

    return '<a href="' . esc($href, 'attr') . '"' . $noteAttrs . ' class="rounded font-semibold text-brand underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-strip-' . $key . '>' . esc($text) . '</a>';
};
?>

<?php if ($tracker['total'] > 0): ?>

    <?php // The finalization tracker (the dean's strip) counts the whole term, whatever the search or the filter. ?>
    <div class="flex flex-col gap-3 rounded-2xl border border-[#E3E6EE] px-4 py-3 sm:flex-row sm:items-center sm:justify-between" <?= $dean ? 'data-strip' : 'data-tracker' ?>>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <?php if ($dean): ?>
                <?php // Signed off is a part of finalized: the bar shows it, then the rest of what is finalized. ?>
                <p class="text-sm text-[#3D3D3D]" data-strip-line><strong class="font-bold text-[#1A1C1E]">Finalized <?= (int) $tracker['finalized'] ?> of <?= (int) $tracker['total'] ?></strong> · <?= $stripPart('Signed off ' . (int) $tracker['signed_off'], $stripLinks['signed_off'] ?? null, 'signed-off') ?> · <?= $stripPart('Override requests waiting: ' . (int) $tracker['requests_waiting'], $stripLinks['requests'] ?? null, 'requests', 'Counted for the sections of this term; Approvals lists every term') ?></p>
                <div class="flex h-2 w-full max-w-md overflow-hidden rounded-full bg-[#EEF1F8]" aria-hidden="true">
                    <span class="h-full bg-[#2E7D4F]" style="width: <?= $share(min($tracker['signed_off'], $tracker['finalized'])) ?>%"></span>
                    <span class="h-full bg-gray-400" style="width: <?= $share(max(0, $tracker['finalized'] - $tracker['signed_off'])) ?>%"></span>
                </div>
            <?php else: ?>
                <p class="text-sm text-[#3D3D3D]" data-tracker-line><strong class="font-bold text-[#1A1C1E]">Finalized <?= (int) $tracker['finalized'] ?> of <?= (int) $tracker['total'] ?></strong> · <?= (int) $tracker['ready'] ?> ready · <?= (int) $tracker['blocked'] ?> blocked</p>
                <div class="flex h-2 w-full max-w-md overflow-hidden rounded-full bg-[#EEF1F8]" aria-hidden="true">
                    <span class="h-full bg-gray-400" style="width: <?= $share($tracker['finalized']) ?>%"></span>
                    <span class="h-full bg-[#2E7D4F]" style="width: <?= $share($tracker['ready']) ?>%"></span>
                    <span class="h-full bg-[#D99A00]" style="width: <?= $share($tracker['blocked']) ?>%"></span>
                </div>
            <?php endif; ?>
        </div>

        <?php // A link that turns the filter on or off; the box and the hidden word say which it is. ?>
        <a href="<?= esc($link(array_merge($keep, ['nf' => $state['nf'] ? null : 1]))) ?>"
           class="inline-flex min-h-10 shrink-0 items-center gap-2 self-start rounded-lg border bg-white px-3 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 sm:self-auto <?= $state['nf'] ? 'border-brand text-brand-dark' : 'border-gray-300 text-[#1A1C1E] hover:border-brand/50' ?>"
           data-not-finalized>
            <span class="flex h-4 w-4 items-center justify-center rounded border <?= $state['nf'] ? 'border-brand bg-brand' : 'border-gray-400 bg-white' ?>" aria-hidden="true">
                <?php if ($state['nf']): ?><?= icon('check', 'h-3 w-3 text-white') ?><?php endif; ?>
            </span>
            Not finalized only<span class="sr-only"><?= $state['nf'] ? ' (on)' : ' (off)' ?></span>
        </a>

    </div>

<?php endif; ?>

<?php if ($rows === []): ?>

    <?php if ($tracker['total'] > 0): ?>
        <?= view('components/empty-state', [
            'icon'  => 'search',
            'title' => $state['q'] !== '' ? 'No sections match "' . $state['q'] . '"' : 'No sections match',
            'text'  => $state['nf'] ? 'Try another word, or turn off "Not finalized only".' : 'Try another word: a course code, a title or an instructor.',
        ], ['saveData' => false]) ?>
    <?php elseif ($dean): ?>
        <?= view('components/empty-state', [
            'icon'  => 'grades',
            'title' => $programId === null ? 'No sections in your college this term' : 'No sections in this program this term',
            'text'  => $programId === null ? 'Sections of your college show here once they are offered this term.' : 'Pick "All programs" to see every section of your college.',
        ], ['saveData' => false]) ?>
    <?php else: ?>
        <?= view('components/empty-state', [
            'icon'  => 'grades',
            'title' => 'No sections in your program this term',
            'text'  => 'Sections of the departments you chair show here once they are offered this term.',
        ], ['saveData' => false]) ?>
    <?php endif; ?>

<?php else: ?>

    <section class="flex flex-col gap-3" aria-labelledby="grades-program-sections">

        <h2 id="grades-program-sections" class="sr-only">Sections</h2>

        <?php // Positioned, so the card also clips what is absolutely placed inside it (a header's sr-only words): the
              // page itself never scrolls sideways on a phone. ?>
        <div class="relative overflow-x-auto rounded-2xl border border-[#E3E6EE]">
            <table class="w-full <?= $dean ? 'min-w-[52rem]' : 'min-w-[46rem]' ?> text-left text-sm" data-program-table>
                <caption class="sr-only"><?= $dean ? "The college's sections this term." : "The program's sections this term." ?><?= $canOpen ? ' Pick a section to open its gradebook below.' : '' ?></caption>
                <thead class="bg-[#F6F7FA] text-xs font-semibold uppercase tracking-wide text-[#646464]">
                    <tr>
                        <?php foreach ($columns as $key => $name): ?>
                            <?php
                            $sorted = $sort === $key;
                            $next   = $sorted && $dir === 'asc' ? 'desc' : 'asc';
                            ?>
                            <th scope="col" class="px-4 py-3 <?= $key === 'section' ? 'sticky left-0 z-[2] bg-[#F6F7FA]' : '' ?>"
                                data-column="<?= esc($key) ?>"
                                aria-sort="<?= $sorted ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a href="<?= esc($link(['q' => $state['q'], 'nf' => $state['nf'] ? 1 : null, 'sort' => $key, 'dir' => $next])) ?>"
                                   <?= isset($explain[$key]) ? 'title="' . esc($explain[$key], 'attr') . '"' : '' ?>
                                   class="inline-flex items-center gap-1 rounded transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $sorted ? 'text-brand' : '' ?>">
                                    <span data-column-label><?= esc($name) ?></span><?php if (isset($explain[$key])): ?><span class="sr-only"> (<?= esc($explain[$key]) ?>)</span><?php endif; ?><span class="w-2.5 text-[0.6rem]" aria-hidden="true"><?= $sorted ? ($dir === 'asc' ? '▲' : '▼') : '' ?></span>
                                </a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E3E6EE]">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $on   = $selected !== null && $row['offering_id'] === $selected['offering_id'];
                        $bg   = $on ? 'bg-[#F5F8FD]' : 'bg-white group-hover:bg-[#FAFBFD]';
                        $view = $look($row['state']);
                        $names = array_column($row['instructors'], 'name');
                        ?>
                        <tr class="group transition">
                            <th scope="row" class="sticky left-0 z-[1] px-4 py-3 align-top font-normal <?= $bg ?> <?= $on ? 'shadow-[inset_3px_0_0_#0040A6]' : '' ?>">
                                <a href="<?= esc($link($pageKeep + ['section' => $row['offering_id']])) ?>"
                                   class="flex flex-col gap-0.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"
                                   data-program-row
                                   data-section-id="<?= (int) $row['offering_id'] ?>"
                                   data-label="<?= esc($label($row), 'attr') ?>"
                                   data-slug="<?= esc($row['slug'], 'attr') ?>"
                                   data-state="<?= esc($row['state'], 'attr') ?>"
                                   data-note="<?= esc($row['note'], 'attr') ?>"
                                   data-has-scheme="<?= $row['has_scheme'] ? '1' : '0' ?>"
                                   data-offering-status="<?= esc($row['offering_status'], 'attr') ?>"
                                   data-instructors="<?= esc((string) json_encode($row['recipients'], JSON_UNESCAPED_UNICODE), 'attr') ?>"
                                   data-waiting="<?= (int) $row['waiting'] ?>"
                                   data-oldest-days="<?= $row['oldest_days'] === null ? '' : (int) $row['oldest_days'] ?>"
                                   data-term-end="<?= esc((string) $termEnd, 'attr') ?>"
                                   <?= $on ? 'aria-current="true"' : '' ?>>
                                    <span class="font-bold <?= $on ? 'text-brand' : 'text-[#1A1C1E] group-hover:text-brand' ?>"><?= esc($label($row)) ?></span>
                                    <span class="max-w-[13rem] truncate text-xs text-[#646464]" title="<?= esc($row['course_title'], 'attr') ?>"><?= esc($row['course_title']) ?></span>
                                </a>
                            </th>
                            <td class="px-4 py-3 align-top text-[#3D3D3D] <?= $bg ?>" data-cell="instructor"><?php if ($names === []): ?><span class="text-[#9AA0AC]" aria-hidden="true">—</span><span class="sr-only">No instructor</span><?php else: ?><?= esc(implode(', ', $names)) ?><?php endif; ?></td>
                            <td class="px-4 py-3 align-top tabular-nums text-[#1A1C1E] <?= $bg ?>" data-cell="average"><?= $row['state'] === 'not_published' || $row['average'] === null ? '—' : esc(number_format((float) $row['average'], 1) . '%') ?></td>
                            <td class="px-4 py-3 align-top tabular-nums <?= $row['at_risk'] > 0 ? 'font-semibold text-[#C33939]' : 'text-[#646464]' ?> <?= $bg ?>" data-cell="at_risk"><?= (int) $row['at_risk'] ?></td>
                            <td class="px-4 py-3 align-top tabular-nums <?= $bg ?>" data-cell="waiting"><?php if ($row['waiting'] > 0): ?><span class="rounded-full bg-[#FFF8E8] px-2 py-0.5 font-semibold text-[#946200]"><?= (int) $row['waiting'] ?></span><?php else: ?><span class="text-[#646464]">0</span><?php endif; ?></td>
                            <td class="px-4 py-3 align-top <?= $bg ?>" data-cell="status">
                                <span class="inline-flex items-center gap-2 whitespace-nowrap font-semibold <?= $view['class'] ?>"><span class="h-2.5 w-2.5 shrink-0 rounded-full <?= $view['dot'] ?>" aria-hidden="true"></span><?= esc($view['word']) ?></span>
                                <?php if ($row['state'] === 'blocked'): ?>
                                    <span class="mt-0.5 block max-w-[14rem] text-xs text-[#646464]"><?= esc($row['note']) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($dean): ?>
                                <td class="px-4 py-3 align-top <?= $row['signed_off'] ? 'font-semibold text-[#2E7D4F]' : 'text-[#646464]' ?> <?= $bg ?>" data-cell="signed_off"><?= $row['signed_off'] ? 'Yes' : '—' ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

            <div class="min-w-0 flex-1">
                <?php $pages = max(1, (int) ceil($program['total'] / \App\Services\Grades\ChairpersonProgramOverview::PER_PAGE)); ?>
                <?php if ($pages > 1): ?>
                    <?= view('components/pagination', [
                        'page'    => $state['page'],
                        'pages'   => $pages,
                        'baseUrl' => $base,
                        'total'   => $program['total'],
                        'query'   => array_filter($listQuery + $keep, static fn ($value): bool => $value !== null && $value !== ''),
                    ], ['saveData' => false]) ?>
                <?php else: ?>
                    <p class="text-sm text-[#646464]"><?= (int) $program['total'] ?> <?= $program['total'] === 1 ? 'section' : 'sections' ?></p>
                <?php endif; ?>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <?php if ($canNudge): ?>
                    <?php // Opens the nudge for the section below, filled from the action bar's data. Hidden for a
                          // finalized section (nothing to nudge about); the page script hides and shows it per row. ?>
                    <button type="button" class="<?= $secondary ?>" data-nudge <?= $selected === null || $selectedFinalized ? 'hidden' : '' ?>>Nudge</button>
                <?php endif; ?>
                <?php if ($dean): ?>
                    <a href="<?= site_url('dean/grades/download-college') . ($termId === null ? '' : '?term=' . (int) $termId) ?>" class="<?= $secondary ?>" data-download-college>Download college</a>
                <?php else: ?>
                    <a href="<?= site_url('chairperson/grades/download-program') . ($termId === null ? '' : '?term=' . (int) $termId) ?>" class="<?= $secondary ?>" data-download-program>Download program</a>
                <?php endif; ?>
            </div>

        </div>

    </section>

<?php endif; ?>

<?php if ($canNudge): ?>
    <?= view('pages/grades/partials/chairperson/nudge-dialog', ['area' => $area], ['saveData' => false]) ?>
<?php endif; ?>

<?php if ($selected !== null): ?>
    <?php
    $id       = (int) $selected['offering_id'];
    $inCourse = site_url("{$area}/courses/{$id}/{$selected['slug']}/grades");
    ?>

    <section class="flex flex-col gap-4" aria-label="<?= esc('Gradebook of ' . $label($selected), 'attr') ?>" data-program-panel>

        <div class="flex flex-col gap-3 rounded-2xl border border-[#E3E6EE] px-4 py-3 md:flex-row md:items-center md:justify-between" data-program-actions data-section-id="<?= $id ?>"
             data-label="<?= esc($label($selected), 'attr') ?>"
             data-state="<?= esc($selected['state'], 'attr') ?>"
             data-instructors="<?= esc((string) json_encode($selected['recipients'], JSON_UNESCAPED_UNICODE), 'attr') ?>"
             data-waiting="<?= (int) $selected['waiting'] ?>"
             data-oldest-days="<?= $selected['oldest_days'] === null ? '' : (int) $selected['oldest_days'] ?>"
             data-term-end="<?= esc((string) $termEnd, 'attr') ?>">

            <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm text-[#3D3D3D]">
                <span class="font-bold text-[#1A1C1E]" data-action-label><?= esc($label($selected)) ?></span>
                <span class="min-w-0 truncate text-[#646464]" data-action-title><?= esc($selected['course_title']) ?></span>
                <span class="inline-flex items-center gap-1 rounded-full bg-[#F1F3F7] px-2.5 py-0.5 text-xs font-semibold text-[#646464]" data-readonly-label><?= icon('lock', 'h-3 w-3') ?>Read-only</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <?php if ($canEdit): ?>
                    <?php // Hidden where there is nothing to edit (no grading yet, a closed section, or a finalized one,
                          // where edits are refused); the page script shows and hides it as rows are opened, and asks
                          // before unlocking. ?>
                    <button type="button" class="<?= $primary ?>" data-edit-as-chair <?= ! $selected['has_scheme'] || $selected['offering_status'] !== 'active' || $selectedFinalized ? 'hidden' : '' ?>>Edit as chairperson</button>
                    <button type="button" class="<?= $secondary ?>" data-edit-done hidden>Done</button>
                <?php endif; ?>
                <?php if ($canOpen): ?>
                    <a href="<?= esc($inCourse) ?>" class="<?= $secondary ?>" data-open-in-course>Open in course →</a>
                <?php endif; ?>
            </div>

        </div>

        <?php if (! $canOpen): ?>

            <?php // The dean's course API asks courses.view: without it the grid cannot be fetched, so the page says why. ?>
            <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center" data-grid-unavailable>
                <?= icon('lock', 'h-10 w-10 text-gray-300') ?>
                <span class="text-sm font-semibold text-[#646464]"><?= esc("Opening a section's gradebook needs access to the college's courses.") ?></span>
                <span class="text-xs text-[#646464]">Ask your institution's administrator if you need it.</span>
            </div>

        <?php else: ?>

        <?php // Filled by the page script, read-only; until then the shape of what is coming. A section without a
              // scheme gets the sentence instead. ?>
        <div class="min-w-0" data-grades-grid data-api-base="<?= esc(site_url("{$area}/api/courses/{$id}"), 'attr') ?>" data-section-id="<?= $id ?>" data-readonly="1" data-has-scheme="<?= $selected['has_scheme'] ? '1' : '0' ?>">
            <?php if ($selected['has_scheme']): ?>
                <?= view('components/skeleton', ['rows' => 6, 'label' => 'Loading the gradebook'], ['saveData' => false]) ?>
            <?php else: ?>
                <?= $noScheme($inCourse) ?>
            <?php endif; ?>
        </div>

        <?php // What opening a row without a scheme draws; the script sets the link's address. ?>
        <template data-no-scheme-template><?= $noScheme('#') ?></template>

        <?php endif; ?>

    </section>

<?php endif; ?>
