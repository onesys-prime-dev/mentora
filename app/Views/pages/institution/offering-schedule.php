<?php
/**
 * A section's Class times: its weekly meetings (audit decisions 13.1, 13.2, 13.4).
 *
 * @var array<string, mixed>       $scheduleOffering  the section, its course and term
 * @var list<array<string, mixed>> $scheduleRows      day, start, end, room, mode (as saved, or as typed after an error)
 * @var bool                       $scheduleSisOwned  the SIS sends these times, so they are locked here
 * @var bool                       $scheduleReadOnly  the institution is read-only
 * @var bool                       $scheduleCanEdit
 * @var list<string>               $scheduleWarnings  the clashes found by the last save
 */

use App\Services\Institutions\ClassScheduleService;

$o       = $scheduleOffering;
$name    = $o['course_code'] . ' ' . $o['section_code'];
$action  = site_url('institution/offerings/' . $o['id'] . '/schedule');
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$control = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] placeholder:text-gray-400 '
    . 'focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';
$head    = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';
$modes   = ['onsite' => 'Onsite', 'online' => 'Online', 'hybrid' => 'Hybrid'];

// Three blank rows to add meetings to, after the ones there are.
$rows = $scheduleRows;

if ($scheduleCanEdit) {
    for ($i = 0; $i < 3; $i++) {
        $rows[] = ['day' => '', 'start' => '', 'end' => '', 'room' => '', 'mode' => 'onsite'];
    }
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Class times',
        'icon'        => 'calendar',
        'description' => $name . ' · ' . $o['course_title'] . ' · ' . $o['term_name'] . ' ' . $o['academic_year'] . '. The section meets at these times every week.',
        'backUrl'     => site_url('institution/offerings'),
        'backLabel'   => 'Back to offerings',
    ], ['saveData' => false]) ?>

    <?php if ($scheduleSisOwned): ?>
        <?= view('components/lock-note', ['kind' => 'sis', 'text' => ClassScheduleService::SIS_LOCK], ['saveData' => false]) ?>
    <?php elseif ($scheduleReadOnly): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif ($o['status'] !== 'active'): ?>
        <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'This section is ' . $o['status'] . ', so its class times cannot be changed.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if ($scheduleWarnings !== []): ?>
        <div data-schedule-warnings>
            <?= view('components/alert', [
                'tone'  => 'warning',
                'title' => 'Saved, but check these',
                'text'  => implode(' ', $scheduleWarnings),
            ], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <?= view('components/empty-state', ['icon' => 'calendar', 'title' => 'No class times yet', 'text' => 'This section has no weekly meetings set.'], ['saveData' => false]) ?>
    <?php else: ?>
        <form method="post" action="<?= esc($action) ?>" class="flex flex-col gap-4" data-schedule-form>
            <?= csrf_field() ?>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm">
                <?php if ($scheduleSisOwned): ?>
                    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 text-xs text-[#646464]" data-sis-managed>
                        <?= icon('lock', 'h-3 w-3') ?> From the SIS
                    </div>
                <?php endif; ?>
                <table class="w-full text-sm">
                    <caption class="sr-only">Class times of <?= esc($name) ?></caption>
                    <thead class="max-md:sr-only border-b border-gray-100 bg-gray-50/70">
                        <tr>
                            <th class="<?= $head ?>" scope="col">Day</th>
                            <th class="<?= $head ?>" scope="col">Starts</th>
                            <th class="<?= $head ?>" scope="col">Ends</th>
                            <th class="<?= $head ?>" scope="col">Room</th>
                            <th class="<?= $head ?>" scope="col">Delivery</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($rows as $i => $r): ?>
                            <?php
                            $n    = $i + 1;
                            $day  = (string) ($r['day'] ?? '');
                            $mode = (string) ($r['mode'] ?? 'onsite');
                            ?>
                            <tr class="max-md:flex max-md:flex-col max-md:gap-2 max-md:p-4" data-schedule-row>
                                <?php if ($scheduleCanEdit): ?>
                                    <td class="px-4 py-2 max-md:p-0">
                                        <select class="<?= $control ?>" name="rows[<?= $i ?>][day]" aria-label="Row <?= $n ?>: day">
                                            <option value="">Choose a day</option>
                                            <?php foreach (ClassScheduleService::DAYS as $key => $label): ?>
                                                <option value="<?= esc($key) ?>"<?= $day === $key ? ' selected' : '' ?>><?= esc($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="px-4 py-2 max-md:p-0"><input class="<?= $control ?>" type="time" name="rows[<?= $i ?>][start]" value="<?= esc((string) ($r['start'] ?? '')) ?>" aria-label="Row <?= $n ?>: starts"></td>
                                    <td class="px-4 py-2 max-md:p-0"><input class="<?= $control ?>" type="time" name="rows[<?= $i ?>][end]" value="<?= esc((string) ($r['end'] ?? '')) ?>" aria-label="Row <?= $n ?>: ends"></td>
                                    <td class="px-4 py-2 max-md:p-0"><input class="<?= $control ?>" type="text" name="rows[<?= $i ?>][room]" maxlength="50" autocomplete="off" placeholder="Rm 204" value="<?= esc((string) ($r['room'] ?? '')) ?>" aria-label="Row <?= $n ?>: room"></td>
                                    <td class="px-4 py-2 max-md:p-0">
                                        <select class="<?= $control ?>" name="rows[<?= $i ?>][mode]" aria-label="Row <?= $n ?>: delivery">
                                            <?php foreach ($modes as $key => $label): ?>
                                                <option value="<?= esc($key) ?>"<?= $mode === $key ? ' selected' : '' ?>><?= esc($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                <?php else: ?>
                                    <td class="px-4 py-3 font-medium max-md:p-0"><?= esc(ClassScheduleService::DAYS[$day] ?? $day) ?></td>
                                    <td class="px-4 py-3 max-md:p-0"><?= esc((string) $r['start']) ?></td>
                                    <td class="px-4 py-3 max-md:p-0"><?= esc((string) $r['end']) ?></td>
                                    <td class="px-4 py-3 max-md:p-0"><?= esc((string) ($r['room'] ?? '') !== '' ? (string) $r['room'] : '—') ?></td>
                                    <td class="px-4 py-3 max-md:p-0"><?= esc($modes[$mode] ?? $mode) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($scheduleCanEdit): ?>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-[#646464]">Leave a row empty to remove that meeting. Times are in the platform's time zone. A cancelled class is an announcement, not a change here.</p>
                    <button type="submit" class="<?= $primary ?>">Save class times</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
