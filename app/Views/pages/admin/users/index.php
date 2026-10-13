<?php
/**
 * People across every institution. Filters live in the address (GET), like the
 * audit log; ticking people enables the bulk buttons (resources/js/pages/admin-users).
 *
 * @var App\Services\Users\UserFilters $userFilters
 * @var list<array<string, mixed>>     $userRows
 * @var list<array<string, mixed>>     $userInstitutions
 * @var int                            $userSelf         the signed-in administrator's id
 */
use App\Services\Users\UserFilters;

$f = $userFilters;

$url = static function (string $path, array $query): string {
    $string = http_build_query($query);

    return site_url($path) . ($string === '' ? '' : '?' . $string);
};

$link     = static fn (array $override = []): string => $url('admin/users', $f->toQuery($override));
$sortLink = static function (string $column) use ($f, $link): string {
    $next = $f->sort === $column && $f->dir === 'asc' ? 'desc' : 'asc';

    return $link(['sort' => $column, 'dir' => $next, 'page' => null]);
};
$arrow = static fn (string $column): string => $f->sort === $column ? ($f->dir === 'asc' ? ' ▲' : ' ▼') : '';

$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Users', 'icon' => 'user', 'actions' => '<a class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . site_url('admin/users/new') . '">Invite a person</a>'], ['saveData' => false]) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<form method="get" action="<?= site_url('admin/users') ?>" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Search
        <input class="<?= $input ?>" type="search" name="q" value="<?= esc($f->q ?? '') ?>" placeholder="Name, email or username">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Institution
        <select class="<?= $input ?>" name="institution">
            <option value="">Any institution</option>
            <?php foreach ($userInstitutions as $institution): ?>
                <option value="<?= esc((string) $institution['id']) ?>" <?= $f->institutionId === (int) $institution['id'] ? 'selected' : '' ?>><?= esc($institution['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Role
        <select class="<?= $input ?>" name="role">
            <option value="">Any role</option>
            <?php foreach (UserFilters::ROLE_LABELS as $role => $roleLabel): ?>
                <option value="<?= esc($role) ?>" <?= $f->role === $role ? 'selected' : '' ?>><?= esc($roleLabel) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Status
        <select class="<?= $input ?>" name="status">
            <option value="">Any status</option>
            <option value="active" <?= $f->status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="deactivated" <?= $f->status === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
        </select>
    </label>
    <button type="submit" class="rounded bg-brand px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Apply</button>
    <?php if ($f->isFiltered()): ?>
        <a class="px-2 py-1.5 text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/users') ?>">Clear</a>
    <?php endif; ?>
    <a class="ml-auto px-2 py-1.5 text-sm font-semibold text-brand hover:underline" href="<?= esc($url('admin/users/export', $f->toQuery(['page' => null]))) ?>" data-export-filtered>Export these (CSV)</a>
</form>

<p class="mt-4 text-sm text-gray-600" aria-live="polite">
    <?= esc(number_format($userTotal)) ?> <?= $userTotal === 1 ? 'person' : 'people' ?><?= $f->isFiltered() ? ' match' : '' ?>
</p>

<form method="post" action="<?= site_url('admin/users/bulk') ?>" data-users-bulk>
    <?= csrf_field() ?>

    <div class="mt-2 flex flex-wrap items-center gap-3 rounded-t-lg bg-white px-3 py-2 shadow">
        <span class="text-sm text-gray-700" data-selected-count>No one selected</span>
        <button type="submit" name="action" value="deactivate" class="<?= $btn ?> border-amber-500 text-amber-700 hover:bg-amber-50" data-bulk-action>Deactivate</button>
        <button type="submit" name="action" value="reactivate" class="<?= $btn ?> border-green-600 text-green-700 hover:bg-green-50" data-bulk-action>Reactivate</button>
        <button type="submit" formaction="<?= site_url('admin/users/export') ?>" class="<?= $btn ?> border-gray-300 text-gray-700 hover:bg-gray-50" data-bulk-action>Export selected (CSV)</button>
    </div>

    <div class="overflow-x-auto rounded-b-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2"><input type="checkbox" data-select-all aria-label="Select everyone on this page"></th>
                    <th class="<?= $th ?>"><a href="<?= esc($sortLink('name')) ?>">Name<?= $arrow('name') ?></a></th>
                    <th class="<?= $th ?>"><a href="<?= esc($sortLink('email')) ?>">Email<?= $arrow('email') ?></a></th>
                    <th class="<?= $th ?>">Roles</th>
                    <th class="<?= $th ?>">Institutions</th>
                    <th class="<?= $th ?>">Status</th>
                    <th class="<?= $th ?>"><a href="<?= esc($sortLink('last_active')) ?>">Last active<?= $arrow('last_active') ?></a></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if ($userRows === []): ?>
                    <tr><td class="px-3 py-8 text-center text-gray-500" colspan="7">No one<?= $f->isFiltered() ? ' matches these filters' : ' yet' ?>.</td></tr>
                <?php endif; ?>

                <?php foreach ($userRows as $row): ?>
                    <?php
                    $name        = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $deactivated = (int) $row['active'] === 0 || $row['deactivated_at'] !== null;
                    ?>
                    <tr data-user="<?= esc((string) $row['id']) ?>" data-status="<?= $deactivated ? 'deactivated' : 'active' ?>">
                        <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="<?= esc((string) $row['id']) ?>" data-user-select aria-label="Select <?= esc($name !== '' ? $name : (string) $row['username']) ?>"></td>
                        <td class="px-3 py-2 font-medium">
                            <a class="text-brand hover:underline" href="<?= site_url('admin/users/' . $row['id']) ?>"><?= esc($name !== '' ? $name : (string) $row['username']) ?></a>
                            <?php if ((int) $row['id'] === $userSelf): ?><span class="ml-1 text-xs font-normal text-gray-500">(you)</span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-600"><?= esc((string) ($row['email'] ?? '—')) ?></td>
                        <td class="px-3 py-2"><?= esc(implode(', ', array_map(static fn (string $role): string => UserFilters::ROLE_LABELS[$role] ?? $role, $row['roles']))) ?></td>
                        <td class="px-3 py-2"><?= $row['institutions'] === [] ? '<span class="text-gray-500">—</span>' : esc(implode(', ', array_column($row['institutions'], 'name'))) ?></td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $deactivated ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>"><?= $deactivated ? 'Deactivated' : 'Active' ?></span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) ($row['last_active'] ?? ''), 0, 16) ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<nav class="mt-4 flex items-center justify-between text-sm" aria-label="Pages">
    <span class="text-gray-600">Page <?= esc((string) $f->page) ?> of <?= esc((string) $userPages) ?></span>
    <span class="flex gap-3">
        <?php if ($f->page > 1): ?>
            <a class="font-semibold text-brand hover:underline" href="<?= esc($link(['page' => $f->page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($f->page < $userPages): ?>
            <a class="font-semibold text-brand hover:underline" href="<?= esc($link(['page' => $f->page + 1])) ?>">Next</a>
        <?php endif; ?>
    </span>
</nav>

<?= $this->endSection() ?>
