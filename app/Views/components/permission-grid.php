<?php
/**
 * The role x permission grid of a Roles page: the system administrator's
 * (the platform) and the institution administrator's (their institution).
 * Every cell is its own small form, so it works without JavaScript: pressing
 * a cell asks the server to flip that one permission for that one role. A
 * permission a role is not offered (decision D2) is a muted dash; the locked
 * role shows its permissions without controls.
 *
 * @var array<string, string>                $roleTitles    role => display name
 * @var array<string, array<string, string>> $sections      section title => [permission => description]
 * @var string                               $lockedRole    a role shown without controls ('' for none)
 * @var array<string, list<string>>          $currentMatrix what each role has now
 * @var array<string, list<string>>          $defaultMatrix what each role has without these changes ("Edited" marks a difference)
 * @var string                               $gridAction    where a cell posts
 * @var bool                                 $gridReadOnly  every cell shown without controls (a read-only institution)
 * @var bool                                 $gridScrollable the system administrator's page: a scrolling area with sticky headers,
 *                                                          and the markers its search, section and "Edited only" filters read
 */
$scroll = ! empty($gridScrollable);
$sticky = $scroll ? 'sticky top-0 z-10 bg-gray-50 ' : '';
$th     = $sticky . 'px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-[#646464]';
?>
<?php if ($scroll): ?>
<div class="mt-4 overflow-auto rounded-lg bg-white shadow-sm lg:min-h-0 lg:flex-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" tabindex="0" role="region" aria-label="Roles and permissions" data-table>
<?php else: ?>
<div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
<?php endif; ?>
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="<?= $scroll ? '' : 'bg-gray-50' ?>">
            <tr>
                <th class="<?= $sticky ?>px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]">Permission</th>
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
                <tr class="<?= $scroll ? 'bg-gray-50' : 'bg-gray-50/60' ?>" data-roles-section-head><th class="px-3 py-1.5 text-left text-xs font-semibold text-brand" colspan="<?= count($roleTitles) + 1 ?>"><?= esc($sectionTitle) ?></th></tr>

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
                            $isLocked  = $role === $lockedRole || ! empty($gridReadOnly);
                            $verb      = $has ? 'Revoke' : 'Grant';
                            ?>
                            <td class="px-3 py-2 text-center <?= $edited ? 'bg-amber-50' : '' ?>">
                                <?php if ($isLocked): ?>
                                    <span class="<?= $has ? 'text-gray-700' : 'text-gray-300' ?>" title="Locked"><?= $has ? '✓' : '—' ?></span>
                                <?php elseif (! in_array($permission, \App\Services\Admin\PermissionMatrix::applicable($role), true)): ?>
                                    <span class="text-gray-300" title="Not used by this role">—</span>
                                <?php else: ?>
                                    <form data-dialog-form method="post" action="<?= esc($gridAction, 'attr') ?>">
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
    <?php if ($scroll): ?><p class="hidden px-3 py-10 text-center text-sm text-[#646464]" data-roles-empty>No permissions match.</p><?php endif; ?>
</div>
