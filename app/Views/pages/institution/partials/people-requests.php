<?php
/**
 * Correction requests waiting for a decision, with one Review dialog per request. Shared by People → Requests
 * (institutional admin) and the platform Users screen (system admin, platform-routed requests).
 *
 * @var list<array<string, mixed>>                                 $requests  CorrectionRequestService::pendingFor rows
 * @var array<int, array{person: array<string, mixed>, interactive: bool}> $avatars   AvatarData::forViewer
 * @var bool                                                       $canDecide whether Review is offered
 * @var string                                                     $base      the decisions' path, e.g. institution/people/requests
 */

use App\Services\Profiles\CorrectionRequestService;
use App\Services\Users\UserFilters;
use CodeIgniter\I18n\Time;

$ghost   = 'inline-flex items-center justify-center pointer-coarse:min-h-11 pointer-coarse:min-w-11 rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$value   = static fn (?string $v): string => $v === null || $v === '' ? '<span class="text-[#646464]">(empty)</span>' : esc($v);
$change  = static fn (array $r): string => '<s class="text-[#646464]">' . $value($r['old_value']) . '</s> <span aria-hidden="true">&rarr;</span><span class="sr-only">to</span> <b class="font-semibold text-[#1A1C1E]">' . $value($r['new_value']) . '</b>';

$rows    = [];
$dialogs = '';

foreach ($requests as $r) {
    $id     = (int) $r['id'];
    $name   = (string) $r['requester'];
    $label  = CorrectionRequestService::LABELS[$r['field']] ?? $r['field'];
    $avatar = $avatars[(int) $r['user_id']] ?? ['person' => ['id' => (int) $r['user_id'], 'name' => $name], 'interactive' => false];
    $who    = implode(' · ', array_filter([UserFilters::ROLE_LABELS[$r['role'] ?? ''] ?? null, $r['student_number']]));
    $sis    = $r['sis_linked'] ? ' <span class="ml-1 inline-flex rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-700">From SIS</span>' : '';
    $dialog = 'request-review-' . $id;

    $rows[] = [
        'id'      => $id,
        'label'   => $name,
        'cells'   => [
            'person' => ['html' => '<span class="flex items-center gap-3">' . view('components/avatar', ['person' => $avatar['person'], 'size' => 'sm', 'interactive' => $avatar['interactive']], ['saveData' => false])
                . '<span class="min-w-0"><span class="block font-medium">' . esc($name) . '</span>' . ($who !== '' ? '<span class="block text-xs text-[#646464]">' . esc($who) . '</span>' : '') . '</span></span>'],
            'change' => ['html' => esc($label) . ': ' . $change($r) . $sis],
            'sent'   => Time::parse((string) $r['created_at'])->humanize(),
        ],
        'actions' => $canDecide ? '<button type="button" class="' . $ghost . '" data-dialog-open="' . $dialog . '">Review</button>' : '',
    ];

    if (! $canDecide) {
        continue;
    }

    $url  = static fn (string $d): string => esc(site_url("{$base}/{$id}/{$d}"));
    $body = '<form method="post" action="' . $url('approve') . '" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . '<p class="text-sm text-[#3D3D3D]">' . $change($r) . '.'
        . ($r['reason'] !== null && $r['reason'] !== '' ? ' Reason given: &ldquo;' . esc($r['reason']) . '&rdquo;' : '') . '</p>'
        . ($r['sis_linked'] ? view('components/alert', ['tone' => 'warning', 'text' => 'This person is synced from your SIS. The next sync will undo this change unless it is fixed in the SIS too.'], ['saveData' => false]) : '')
        . view('components/form-field', ['name' => 'note', 'label' => 'Note to ' . $name . ' (needed if you reject)', 'type' => 'textarea', 'required' => true, 'maxlength' => CorrectionRequestService::TEXT_MAX, 'placeholder' => 'Optional when approving'], ['saveData' => false])
        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
        . '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="inline-flex items-center justify-center pointer-coarse:min-h-11 rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        // Reject posts elsewhere and needs the note; Approve skips the browser's check (the note is optional there).
        . '<button type="submit" formaction="' . $url('reject') . '" class="inline-flex items-center justify-center pointer-coarse:min-h-11 rounded-full border border-[#C33939] bg-white px-5 py-2 text-sm font-semibold text-[#C33939] transition hover:bg-[#FDEDEC]">Reject</button>'
        . '<button type="submit" formnovalidate class="inline-flex items-center justify-center pointer-coarse:min-h-11 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Approve change</button>'
        . '</div></form>';

    $dialogs .= view('components/dialog', ['id' => $dialog, 'title' => "Change {$name}'s " . strtolower($label) . '?', 'body' => $body, 'size' => 'md'], ['saveData' => false]);
}
?>

<?= view('components/data-table', [
    'caption'    => 'Correction requests',
    'columns'    => [
        ['key' => 'person', 'label' => 'Person'],
        ['key' => 'change', 'label' => 'Change'],
        ['key' => 'sent', 'label' => 'Sent', 'priority' => 2],
    ],
    'rows'       => $rows,
    'emptyIcon'  => 'check',
    'emptyTitle' => 'No requests waiting',
    'emptyText'  => 'When someone asks to correct an official detail, it appears here.',
], ['saveData' => false]) ?>

<?= $dialogs ?>
