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

$th = 'px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-600';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<h1 class="text-2xl font-bold text-brand">Roles and permissions</h1>
<p class="mt-2 max-w-3xl text-sm text-gray-600">
    What each role may do. Changes apply on the next request and need your password. The system administrator role is locked,
    and every role keeps at least one permission. <span class="font-semibold text-amber-700">Edited</span> marks a cell that differs from the default.
</p>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Permission</th>
                <?php foreach ($roleTitles as $role => $roleTitle): ?>
                    <th class="<?= $th ?>">
                        <?= esc($roleTitle) ?>
                        <?php if ($role === $lockedRole): ?><span class="block text-[10px] font-normal normal-case text-gray-500">locked</span><?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>

        <?php foreach ($sections as $sectionTitle => $permissions): ?>
            <tbody class="divide-y divide-gray-100">
                <tr class="bg-gray-50/60"><th class="px-3 py-1.5 text-left text-xs font-semibold text-brand" colspan="<?= count($roleTitles) + 1 ?>"><?= esc($sectionTitle) ?></th></tr>

                <?php foreach ($permissions as $permission => $description): ?>
                    <tr>
                        <td class="px-3 py-2">
                            <span class="font-mono text-xs"><?= esc($permission) ?></span>
                            <span class="block text-xs text-gray-500"><?= esc($description) ?></span>
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
                                    <form method="post" action="<?= site_url('admin/roles/permissions') ?>">
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
</div>

<?= $this->endSection() ?>
