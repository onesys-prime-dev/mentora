<?php
/**
 * Role x permission matrix. Every cell is its own small form, so it works
 * without JavaScript: pressing a cell asks the server to flip that one
 * permission for that one role.
 *
 * @var array<string, string>       $roleTitles    role => display name
 * @var string                      $lockedRole
 * @var array<string, string>       $platformPerms permission => description
 * @var array<string, string>       $academicPerms permission => description
 * @var array<string, list<string>> $currentMatrix
 * @var array<string, list<string>> $defaultMatrix
 */
$sections = [
    'Platform' => $platformPerms,
    'Inside an institution' => $academicPerms,
];

$ctl = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-col rounded-3xl bg-white p-5 shadow-sm sm:p-8 lg:h-full lg:min-h-[28rem]" data-page-panel>

<?= view('components/page-head', ['title' => 'Roles and permissions', 'icon' => 'lock'], ['saveData' => false]) ?>
<p class="mt-2 max-w-3xl text-sm text-[#646464]">
    What each role may do. Changes apply on the next request and need your password. The system administrator role is locked,
    and every role keeps at least one permission. <span class="font-semibold text-amber-700">Edited</span> marks a cell that differs from the default.
</p>

<?php $duty = ['grades.approve' => 'approve grade overrides', 'grades.signoff' => 'sign off grades']; ?>
<?php foreach ($roleGaps ?? [] as $gap): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'warning', 'text' => $gap['institution'] . ': nobody can ' . $duty[$gap['permission']] . ' in ' . $gap['department'] . '. Its requests and sign-offs wait.'], ['saveData' => false]) ?></div>
<?php endforeach; ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6 flex flex-wrap items-center gap-3 rounded-lg bg-white p-3 shadow-sm" data-toolbar data-roles-filter>
    <input type="search" class="<?= $ctl ?> min-w-[14rem] flex-1" placeholder="Search permissions" aria-label="Search permissions" data-roles-search>
    <select class="<?= $ctl ?>" aria-label="Show" data-roles-section>
        <option value="">All permissions</option>
        <?php foreach (array_keys($sections) as $sectionTitle): ?>
            <option value="<?= esc($sectionTitle) ?>"><?= esc($sectionTitle) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="flex items-center gap-2 text-sm text-[#1A1C1E]"><input type="checkbox" class="h-4 w-4 rounded border-gray-300" data-roles-edited> Edited only</label>
    <span class="text-xs text-[#646464]" data-roles-count aria-live="polite"></span>
</div>

<?= view('components/permission-grid', [
    'roleTitles' => $roleTitles, 'sections' => $sections, 'lockedRole' => $lockedRole,
    'currentMatrix' => $currentMatrix, 'defaultMatrix' => $defaultMatrix, 'gridAction' => site_url('admin/roles/permissions'),
    'gridScrollable' => true,
], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    (function () {
        var box = document.querySelector('[data-roles-filter]');
        if (!box) { return; }
        var search = box.querySelector('[data-roles-search]');
        var section = box.querySelector('[data-roles-section]');
        var edited = box.querySelector('[data-roles-edited]');
        var count = box.querySelector('[data-roles-count]');
        var empty = document.querySelector('[data-roles-empty]');
        var bodies = document.querySelectorAll('[data-roles-section-body]');

        function apply() {
            var q = search.value.trim().toLowerCase();
            var total = 0;
            bodies.forEach(function (body) {
                var inSection = section.value === '' || section.value === body.getAttribute('data-roles-section-body');
                var shown = 0;
                body.querySelectorAll('[data-roles-row]').forEach(function (row) {
                    var show = inSection
                        && (q === '' || row.getAttribute('data-search').indexOf(q) !== -1)
                        && (!edited.checked || row.getAttribute('data-edited') === '1');
                    row.hidden = !show;
                    if (show) { shown++; }
                });
                body.hidden = shown === 0;
                total += shown;
            });
            count.textContent = total + (total === 1 ? ' permission' : ' permissions');
            empty.classList.toggle('hidden', total !== 0);
        }

        search.addEventListener('input', apply);
        section.addEventListener('change', apply);
        edited.addEventListener('change', apply);
        apply();
    })();
</script>
<?= $this->endSection() ?>
