<?php
/**
 * The filters of a list, as controls that are links (so each choice is in the address): the To grade queue's
 * section, type and late work, or the At risk list's reason. Shared by the instructor's bar and the chairperson's
 * My sections; $list names the address parameters of the list on that page.
 *
 * @var string                     $for       to-grade or at-risk
 * @var array<string, string>      $list      the address parameters that open the list (the tab, and on the
 *                                            chairperson's page its sub-list)
 * @var list<array<string, mixed>> $sections
 * @var list<string>               $types
 * @var array<string, mixed>       $filters   the To grade filters: qsection, type, late
 * @var string|null                $reason
 * @var Closure                    $link
 * @var string                     $select    the classes of a select
 */
?>
<?php if ($for === 'to-grade'): ?>
                    <label class="sr-only" for="grades-qsection">Section</label>
                    <select id="grades-qsection" data-select-link data-queue-section class="<?= $select ?>">
                        <option value="<?= esc($link($list + ['type' => $filters['type'], 'late' => $filters['late'] ? 1 : null])) ?>">All sections</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= esc($link($list + ['qsection' => $s['offering_id'], 'type' => $filters['type'], 'late' => $filters['late'] ? 1 : null])) ?>" <?= $filters['qsection'] === $s['offering_id'] ? 'selected' : '' ?>><?= esc($s['course_code'] . '-' . $s['section_code']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="sr-only" for="grades-type">Type</label>
                    <select id="grades-type" data-select-link data-queue-type class="<?= $select ?>">
                        <option value="<?= esc($link($list + ['qsection' => $filters['qsection'], 'late' => $filters['late'] ? 1 : null])) ?>">All types</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= esc($link($list + ['qsection' => $filters['qsection'], 'type' => $type, 'late' => $filters['late'] ? 1 : null])) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php // A link that turns the filter on or off; the box and the hidden word say which it is. ?>
                    <a href="<?= esc($link($list + ['qsection' => $filters['qsection'], 'type' => $filters['type'], 'late' => $filters['late'] ? null : 1])) ?>"
                       class="inline-flex min-h-10 items-center gap-2 rounded-lg border bg-white px-3 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 <?= $filters['late'] ? 'border-brand text-brand-dark' : 'border-gray-300 text-[#1A1C1E] hover:border-brand/50' ?>"
                       data-queue-late>
                        <span class="flex h-4 w-4 items-center justify-center rounded border <?= $filters['late'] ? 'border-brand bg-brand' : 'border-gray-400 bg-white' ?>" aria-hidden="true">
                            <?php if ($filters['late']): ?><?= icon('check', 'h-3 w-3 text-white') ?><?php endif; ?>
                        </span>
                        Late only<span class="sr-only"><?= $filters['late'] ? ' (on)' : ' (off)' ?></span>
                    </a>
<?php else: ?>
                    <label class="sr-only" for="grades-reason">Reason</label>
                    <select id="grades-reason" data-select-link data-risk-reason class="<?= $select ?>">
                        <?php foreach ([null => 'All reasons', 'grade' => 'Grade', 'activity' => 'Activity', 'inc' => 'INC'] as $key => $label): ?>
                            <option value="<?= esc($link($list + ['reason' => $key])) ?>" <?= ($reason ?? '') === (string) $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
<?php endif; ?>
