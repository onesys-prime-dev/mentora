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

$th  = 'sticky top-0 z-10 bg-gray-50 px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-[#646464]';
$ctl = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-col lg:h-full lg:min-h-[28rem]">

<?= view('components/page-head', ['title' => 'Roles and permissions', 'icon' => 'lock'], ['saveData' => false]) ?>
<p class="mt-2 max-w-3xl text-sm text-[#646464]">
    What each role may do. Changes apply on the next request and need your password. The system administrator role is locked,
    and every role keeps at least one permission. <span class="font-semibold text-amber-700">Edited</span> marks a cell that differs from the default.
</p>

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

<div class="mt-4 overflow-auto rounded-lg bg-white shadow-sm lg:min-h-0 lg:flex-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-table>
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
            <tr>
                <th class="sticky top-0 z-10 bg-gray-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]">Permission</th>
                <?php foreach ($roleTitles as $role => $roleTitle): ?>
                    <th class="<?= $th ?>">
                        <?= esc($roleTitle) ?>
                        <?php if ($role === $lockedRole): ?><span class="block text-[10px] font-normal normal-case text-[#646464]">locked</span><?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>

        <?php foreach ($sections as $sectionTitle => $permissions): ?>
            <tbody class="divide-y divide-gray-100" data-roles-section-body="<?= esc($sectionTitle) ?>">
                <tr class="bg-gray-50" data-roles-section-head><th class="px-3 py-1.5 text-left text-xs font-semibold text-brand" colspan="<?= count($roleTitles) + 1 ?>"><?= esc($sectionTitle) ?></th></tr>

                <?php foreach ($permissions as $permission => $description): ?>
                    <?php
                    $rowEdited = false;
                    foreach ($roleTitles as $role => $unused) {
                        $rowEdited = $rowEdited || in_array($permission, $currentMatrix[$role] ?? [], true) !== in_array($permission, $defaultMatrix[$role] ?? [], true);
                    }
                    ?>
                    <tr data-roles-row data-search="<?= esc(strtolower($permission . ' ' . $description)) ?>" data-edited="<?= $rowEdited ? '1' : '0' ?>">
                        <td class="px-3 py-2">
                            <span class="font-mono text-xs"><?= esc($permission) ?></span>
                            <span class="block text-xs text-[#646464]"><?= esc($description) ?></span>
                        </td>

                        <?php foreach ($roleTitles as $role => $roleTitle): ?>
                            <?php
                            $has       = in_array($permission, $currentMatrix[$role] ?? [], true);
                            $edited    = $has !== in_array($permission, $defaultMatrix[$role] ?? [], true);
                            $isLocked  = $role === $lockedRole;
                            $verb      = $has ? 'Revoke' : 'Grant';
                            ?>
                            <td class="px-3 py-2 text-center <?= $edited ? 'bg-amber-50' : '' ?>">
                                <?php if ($isLocked): ?>
                                    <span class="<?= $has ? 'text-gray-700' : 'text-gray-300' ?>" title="Locked"><?= $has ? '✓' : '—' ?></span>
                                <?php else: ?>
                                    <form data-dialog-form method="post" action="<?= site_url('admin/roles/permissions') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="role" value="<?= esc($role) ?>">
                                        <input type="hidden" name="permission" value="<?= esc($permission) ?>">
                                        <input type="hidden" name="granted" value="<?= $has ? '0' : '1' ?>">
                                        <button
                                            type="submit"
                                            class="h-7 w-7 rounded border <?= $has ? 'border-brand bg-brand text-white' : 'border-gray-300 bg-white text-gray-300' ?> hover:opacity-80"
                                            aria-label="<?= esc("{$verb} {$permission} for {$roleTitle}") ?>"
                                            title="<?= esc("{$verb} {$permission} for {$roleTitle}") ?>"><?= $has ? '✓' : '+' ?></button>
                                    </form>
                                    <?php if ($edited): ?><span class="text-[10px] font-semibold text-amber-700">Edited</span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        <?php endforeach; ?>
    </table>
    <p class="hidden px-3 py-10 text-center text-sm text-[#646464]" data-roles-empty>No permissions match.</p>
</div>

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
