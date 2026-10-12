<?php
/**
 * The institution's people.
 *
 * @var string                     $peopleView          people|invitations
 * @var list<array<string, mixed>> $peopleRows
 * @var int                        $peopleTotal
 * @var int                        $peoplePages
 * @var \App\Services\Users\UserFilters $peopleFilters
 * @var list<array<string, mixed>> $peopleInvitations
 * @var int                        $peopleOpen          invitations still open
 * @var list<string>               $peopleRoles         roles that can be given
 * @var array<string, string>      $peopleRoleLabels
 * @var array<string, mixed>       $peopleSeats         used / limit / percent / state
 * @var bool                       $peopleCanEdit
 */
$base    = 'institution/people';
$f       = $peopleFilters;
$primary = 'rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$second  = 'rounded-full border border-[#0040A6] bg-white px-5 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#F2F7FF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$link    = 'text-sm font-semibold text-[#0040A6] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50 rounded';
$menuBtn = 'block w-full rounded-lg px-3 py-2 text-left text-sm text-[#1A1C1E] hover:bg-[#F2F7FF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$menuBad = 'block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-[#C33939] hover:bg-[#FDEDEC] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50';

$json = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));
$url  = static fn (string $path): string => esc(site_url('institution/people' . $path));

// The institution is always set (the server decides it): only these narrow the list.
$filtered = $f->q !== null || $f->role !== null || $f->status !== null;

$query = $f->toQuery(["institution" => null]);
$sortUrl = static fn (string $column, string $next): string => site_url($base) . '?' . http_build_query(array_merge($query, ['sort' => $column, 'dir' => $next, 'page' => null]));

$roleOptions = ['' => 'Any role'];

foreach ($peopleRoles as $role) {
    $roleOptions[$role] = $peopleRoleLabels[$role] ?? $role;
}

$rows = [];

foreach ($peopleRows as $p) {
    $roleNames = array_map(static fn (string $r): string => $peopleRoleLabels[$r] ?? $r, $p['roles']);

    $badges = '';

    if ($p['elsewhere']) {
        $badges .= ' <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-[#646464]" data-elsewhere>Also at another institution</span>';
    }

    if ($p['sis']) {
        $badges .= ' <span class="ml-1 inline-flex items-center gap-1 text-[11px] font-semibold text-[#646464]" data-sis-locked>' . icon('lock', 'h-3 w-3') . 'SIS</span>';
    }

    $actions = '';

    if ($peopleCanEdit) {
        $label = esc($p['name']);
        $items = '';

        if (! $p['elsewhere']) {
            $items .= '<button type="button" class="' . $menuBtn . '" data-dialog-open="person-edit"'
                . ' data-dialog-title="Edit ' . $label . '" data-dialog-label="Save" data-dialog-action="' . $url('/' . $p['id']) . '"'
                . ' data-dialog-values="' . $json(['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'email' => $p['email']]) . '"'
                . ($p['sis'] ? ' data-dialog-locked="' . $json(['first_name', 'last_name', 'email']) . '"' : '') . '>Edit details</button>';

            $values = [];

            foreach ($peopleRoles as $role) {
                $values['role_' . $role] = in_array($role, $p['roles'], true);
            }

            $items .= '<button type="button" class="' . $menuBtn . '" data-dialog-open="person-roles"'
                . ' data-dialog-title="Roles of ' . $label . '" data-dialog-label="Save roles" data-dialog-action="' . $url('/' . $p['id'] . '/roles') . '"'
                . ' data-dialog-values="' . $json($values) . '">Change roles</button>';

            if ($p['active']) {
                $items .= '<button type="button" class="' . $menuBtn . '" data-dialog-open="person-confirm"'
                    . ' data-dialog-title="Send ' . $label . ' a password link?"'
                    . ' data-dialog-text="They get an email with a link to choose a new password. Their current password keeps working until they do."'
                    . ' data-dialog-label="Send link" data-dialog-action="' . $url('/' . $p['id'] . '/reset-password') . '">Send password link</button>';

                $items .= '<button type="button" class="' . $menuBtn . '" data-dialog-open="person-confirm"'
                    . ' data-dialog-title="Sign ' . $label . ' out everywhere?"'
                    . ' data-dialog-text="Every device they are signed in on is signed out. They can sign in again straight away."'
                    . ' data-dialog-label="Sign out" data-dialog-action="' . $url('/' . $p['id'] . '/sign-out') . '">Sign out everywhere</button>';
            }

            if ($p['active'] && ! $p['self'] && ! $p['last_admin']) {
                $items .= '<button type="button" class="' . $menuBad . '" data-dialog-open="person-confirm"'
                    . ' data-dialog-title="Deactivate ' . $label . '?"'
                    . ' data-dialog-text="They are signed out and cannot sign in. Their work, grades and history are kept. If they are a chairperson, that assignment ends. You can reactivate them later."'
                    . ' data-dialog-label="Deactivate" data-dialog-action="' . $url('/' . $p['id'] . '/deactivate') . '">Deactivate</button>';
            } elseif (! $p['active']) {
                $items .= '<button type="button" class="' . $menuBtn . '" data-dialog-open="person-confirm"'
                    . ' data-dialog-title="Reactivate ' . $label . '?"'
                    . ' data-dialog-text="They can sign in again. A student takes a seat of your plan."'
                    . ' data-dialog-label="Reactivate" data-dialog-action="' . $url('/' . $p['id'] . '/reactivate') . '">Reactivate</button>';
            }
        } else {
            $items .= '<p class="px-3 py-2 text-xs text-[#646464]">Their account is shared with another institution, so only their place in yours can change.</p>';
        }

        if ($p['elsewhere'] && ! $p['last_admin']) {
            $items .= '<button type="button" class="' . $menuBad . '" data-dialog-open="person-confirm"'
                . ' data-dialog-title="Remove ' . $label . ' from your institution?"'
                . ' data-dialog-text="They keep their account and their place in the other institution. They lose access to yours, and any chairperson assignment here ends."'
                . ' data-dialog-label="Remove" data-dialog-action="' . $url('/' . $p['id'] . '/remove') . '">Remove from my institution</button>';
        }

        $actions = '<details class="relative inline-block text-left" data-person-menu>'
            . '<summary class="' . $link . ' cursor-pointer list-none px-2 py-1" aria-label="Actions for ' . $label . '">Actions</summary>'
            . '<div class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-1 shadow-lg max-md:static max-md:w-full max-md:shadow-none">' . $items . '</div>'
            . '</details>';
    }

    $rows[] = [
        'id'      => $p['id'],
        'label'   => $p['name'],
        'cells'   => [
            'name'        => ['html' => '<span class="font-medium">' . esc($p['name']) . '</span>' . ($p['self'] ? ' <span class="text-xs text-[#646464]">(you)</span>' : '') . $badges . '<span class="block text-xs text-[#646464]">' . esc($p['email']) . '</span>'],
            'roles'       => implode(', ', $roleNames),
            'status'      => ['html' => view('components/status-badge', ['label' => $p['active'] ? 'Active' : 'Deactivated', 'tone' => $p['active'] ? 'success' : 'neutral'], ['saveData' => false])],
            'last_active' => $p['last_active'] ? date('j M Y', strtotime((string) $p['last_active'])) : 'Never',
        ],
        'actions' => $actions,
    ];
}

$invitationRows = [];

foreach ($peopleInvitations as $i) {
    $expired = $i['status'] === 'expired';
    $actions = '';

    if ($peopleCanEdit) {
        $name    = esc($i['first_name'] . ' ' . $i['last_name']);
        $actions = '<form class="inline" method="post" action="' . $url('/invitations/' . $i['id'] . '/resend') . '">' . csrf_field()
            . '<button type="submit" class="' . $link . '">Send again</button></form>';

        if (! $expired) {
            $actions .= ' <button type="button" class="ml-3 text-sm font-semibold text-[#C33939] hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50"'
                . ' data-dialog-open="person-confirm" data-dialog-title="Cancel the invitation for ' . $name . '?"'
                . ' data-dialog-text="The link in their email stops working. You can invite them again later."'
                . ' data-dialog-label="Cancel invitation" data-dialog-action="' . $url('/invitations/' . $i['id'] . '/revoke') . '">Cancel</button>';
        }
    }

    $invitationRows[] = [
        'id'      => $i['id'],
        'label'   => $i['first_name'] . ' ' . $i['last_name'],
        'cells'   => [
            'name'    => ['html' => '<span class="font-medium">' . esc($i['first_name'] . ' ' . $i['last_name']) . '</span><span class="block text-xs text-[#646464]">' . esc($i['email']) . '</span>'],
            'role'    => $peopleRoleLabels[$i['role']] ?? (string) $i['role'],
            'status'  => ['html' => view('components/status-badge', ['label' => $expired ? 'Expired' : 'Waiting', 'tone' => $expired ? 'warning' : 'info'], ['saveData' => false])],
            'expires' => date('j M Y', strtotime((string) $i['expires_at'])),
        ],
        'actions' => $actions,
    ];
}

$seatsText = $peopleSeats['limit'] === null ? 'Student seats: ' . number_format((int) $peopleSeats['used']) . ' in use (no limit on your plan).' : 'Student seats: ' . number_format((int) $peopleSeats['used']) . ' of ' . number_format((int) $peopleSeats['limit']) . ' in use.';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'People',
        'icon'        => 'user',
        'description' => 'Everyone in your institution: invite, change roles, and switch accounts on and off.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
        'actions'     => '<a class="' . $second . '" href="' . esc(site_url($base . '/export') . ($query === [] ? '' : '?' . http_build_query($query))) . '">Export CSV</a>'
            . ($peopleCanEdit ? ' <button type="button" class="' . $primary . '" data-dialog-open="person-invite" data-dialog-title="Invite a person" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Send invitation">Invite a person</button>' : ''),
    ], ['saveData' => false]) ?>

    <?php if (! $peopleCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/tabs', ['ariaLabel' => 'People', 'tabs' => [
        ['label' => 'People', 'href' => site_url($base), 'active' => $peopleView === 'people'],
        ['label' => 'Invitations', 'href' => site_url($base) . '?view=invitations', 'active' => $peopleView === 'invitations', 'count' => $peopleOpen],
        ['label' => 'Parents', 'href' => site_url($base . '/parents')],
    ]], ['saveData' => false]) ?>

    <?php if ($peopleView === 'people'): ?>

        <?= view('components/toolbar', [
            'action'   => site_url($base),
            'filtered' => $filtered,
            'fields'   => [
                ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Name or email', 'value' => (string) $f->q, 'span' => 'lg:col-span-2'],
                ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => $roleOptions, 'value' => (string) $f->role],
                ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'active' => 'Active', 'deactivated' => 'Deactivated'], 'value' => (string) $f->status],
            ],
        ], ['saveData' => false]) ?>

        <p class="text-sm text-[#646464]" aria-live="polite" data-people-total>
            <?= esc(number_format($peopleTotal)) ?> <?= $peopleTotal === 1 ? 'person' : 'people' ?><?= $filtered ? ' match' : '' ?>
        </p>

        <?php
        $bulk = $peopleCanEdit
            ? '<form method="post" action="' . $url('/export') . '" data-bulk-form class="inline">' . csrf_field() . '<button type="submit" class="' . $second . '">Export</button></form>'
                . '<form method="post" action="' . $url('/bulk/reactivate') . '" data-bulk-form class="inline">' . csrf_field() . '<button type="submit" class="' . $second . '">Reactivate</button></form>'
                . '<form method="post" action="' . $url('/bulk/deactivate') . '" data-bulk-form class="inline">' . csrf_field() . '<button type="submit" class="rounded-full border border-[#C33939] bg-white px-5 py-2 text-sm font-semibold text-[#C33939] transition hover:bg-[#FDEDEC]">Deactivate</button></form>'
            : '';
        ?>

        <?= view('components/data-table', [
            'caption'    => 'People',
            'columns'    => [
                ['key' => 'name', 'label' => 'Name', 'sortable' => true],
                ['key' => 'roles', 'label' => 'Role'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'last_active', 'label' => 'Last active', 'sortable' => true],
            ],
            'rows'       => $rows,
            'sort'       => $f->sort,
            'dir'        => $f->dir,
            'sortUrl'    => $sortUrl,
            'selectable' => $peopleCanEdit,
            'nounOne'    => 'person',
            'nounMany'   => 'people',
            'bulk'       => $bulk,
            'emptyIcon'  => 'user',
            'emptyTitle' => $filtered ? 'No one matches these filters' : 'No people yet',
            'emptyText'  => $filtered ? 'Try a different name, role or status.' : 'Invite the first person to get started.',
        ], ['saveData' => false]) ?>

        <?= view('components/pagination', ['page' => $f->page, 'pages' => $peoplePages, 'total' => $peopleTotal, 'baseUrl' => site_url($base), 'query' => $query], ['saveData' => false]) ?>

    <?php else: ?>

        <p class="text-sm text-[#646464]" aria-live="polite">Invitations that were sent and not used yet. The account is made when the person chooses a password.</p>

        <?= view('components/data-table', [
            'caption'    => 'Invitations',
            'columns'    => [
                ['key' => 'name', 'label' => 'Person'],
                ['key' => 'role', 'label' => 'Role'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'expires', 'label' => 'Link works until'],
            ],
            'rows'       => $invitationRows,
            'emptyIcon'  => 'user',
            'emptyTitle' => 'No open invitations',
            'emptyText'  => 'People you invite appear here until they choose a password.',
        ], ['saveData' => false]) ?>

    <?php endif; ?>

</div>

<?php if ($peopleCanEdit): ?>
    <?php
    $field = static fn (array $spec): string => view('components/form-field', $spec, ['saveData' => false]);

    $buttons = static fn (string $text): string => '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">' . esc($text) . '</button></div>';

    $form = static fn (string $inside, string $text): string => '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $inside . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons($text) . '</form>';

    // Built per dialog: each needs its own field ids.
    $person = static fn (): string => $field(['name' => 'first_name', 'label' => 'First name', 'required' => true, 'maxlength' => 100, 'attrs' => 'autocomplete="off"'])
        . $field(['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'maxlength' => 100, 'attrs' => 'autocomplete="off"'])
        . $field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'maxlength' => 254, 'attrs' => 'autocomplete="off"', 'help' => 'They sign in with this address.']);

    $roleSelect = ['' => 'Choose a role'];

    foreach ($peopleRoles as $role) {
        $roleSelect[$role] = $peopleRoleLabels[$role] ?? $role;
    }

    $roleBoxes = '<fieldset class="flex flex-col gap-2" data-field="roles"><legend class="text-xs font-medium text-gray-600">Roles</legend>';

    foreach ($peopleRoles as $role) {
        $roleBoxes .= '<label class="flex items-center gap-2 text-sm text-[#1A1C1E]"><input type="checkbox" name="role_' . esc($role) . '" value="1" class="h-4 w-4 rounded border-gray-300 text-[#0040A6] focus:ring-[#0040A6]/40">'
            . esc($peopleRoleLabels[$role] ?? $role) . '</label>';
    }

    $roleBoxes .= '<p class="text-xs text-[#646464]">Taking away the chairperson role ends their chairperson assignments. The last administrator keeps theirs.</p>'
        . '<p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p></fieldset>';
    ?>

    <?= view('components/dialog', ['id' => 'person-invite', 'title' => 'Invite a person', 'body' => $form(
        $person()
        . $field(['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => $roleSelect, 'required' => true, 'help' => $seatsText . ' A student invitation uses a seat.']),
        'Send invitation',
    )], ['saveData' => false]) ?>

    <?= view('components/dialog', ['id' => 'person-edit', 'title' => 'Edit details', 'body' => $form($person(), 'Save')], ['saveData' => false]) ?>

    <?= view('components/dialog', ['id' => 'person-roles', 'title' => 'Change roles', 'body' => $form($roleBoxes, 'Save roles')], ['saveData' => false]) ?>

    <?= view('components/confirm', [
        'id'           => 'person-confirm',
        'title'        => 'Are you sure?',
        'text'         => 'This changes the person.',
        'action'       => site_url($base),
        'confirmLabel' => 'Confirm',
        'tone'         => 'danger',
        'ajax'         => true,
    ], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
