<?php
/**
 * Message reports, for the institutional administrator: the list by status, or one report with the
 * snapshot taken when it was reported (the message and up to five before it) and the decision buttons.
 * This is the only place chat text is shown outside a conversation, so every piece of it is escaped.
 *
 * @var string                                   $status  open|dismissed|actioned
 * @var list<array<string, mixed>>               $cases   ReportReviewService::cases(): one card per reported person
 * @var array<string, mixed>|null                $report  ReportReviewService::view(), when one report is open
 */
$reasons = ['harassment' => 'Harassment', 'spam' => 'Spam', 'inappropriate' => 'Inappropriate', 'other' => 'Other'];
$types   = ['course' => 'Course chat', 'group' => 'Staff group', 'direct' => 'Direct message'];
$tabs    = ['open' => 'Open', 'dismissed' => 'Dismissed', 'actioned' => 'Actioned'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto mt-5 flex h-[calc(100dvh-8.3rem)] min-h-[440px] w-full max-w-4xl flex-col gap-5 rounded-[28px] border border-gray-200 bg-white p-5 shadow-[0_8px_30px_rgba(0,40,120,0.12)] sm:p-7" data-messages-reports>

    <div class="flex items-center justify-between gap-3">
        <h1 class="font-display text-xl font-bold text-brand"><?= $report === null ? 'Message reports' : 'Message report' ?></h1>
        <a href="<?= esc($report === null ? site_url('messages') : site_url('messages/reports')) ?>" class="text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
            <?= $report === null ? 'Back to Messages' : 'Back to reports' ?>
        </a>
    </div>

    <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto pr-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

    <?php if ($report === null): ?>

        <nav class="flex gap-2" aria-label="Report status">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?= esc(site_url('messages/reports?status=' . $key)) ?>"
                   class="rounded-full border px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $key === $status ? 'border-brand bg-brand text-white' : 'border-gray-300 text-[#646464] hover:bg-gray-50' ?>"
                   <?= $key === $status ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($cases === []): ?>
            <?= view('components/empty-state', ['icon' => 'inbox', 'title' => 'No ' . $status . ' reports', 'text' => 'Reports from people in your institution appear here.'], ['saveData' => false]) ?>
        <?php else: ?>
            <?php
            $btn       = 'rounded-full px-4 py-1.5 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 pointer-coarse:min-h-11';
            $durations = ['1d' => '1 day', '7d' => '7 days', '30d' => '30 days', 'lifted' => 'Until I lift it'];
            ?>
            <?php foreach ($cases as $case): ?>
                <?php
                $uid   = (int) $case['user_id'];
                $hours = $case['pause'] === null ? 0 : max(1, (int) ceil((strtotime((string) $case['pause']['ends_at']) - time()) / 3600));
                $count = count($case['reports']);
                ?>
                <section aria-labelledby="case-<?= $uid ?>" class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" data-report-case="<?= $uid ?>">
                    <div class="flex flex-wrap items-center gap-3">
                        <?php if (($case['avatar'] ?? null) !== null): ?>
                            <?= view('components/avatar', ['person' => $case['avatar']['person'], 'size' => 'sm', 'decorative' => true], ['saveData' => false]) ?>
                        <?php endif; ?>
                        <div class="flex min-w-0 flex-col">
                            <h2 id="case-<?= $uid ?>" class="text-sm font-bold text-brand"><?= esc($case['name']) ?> · <?= esc(ucfirst(str_replace('_', ' ', $case['role']))) ?></h2>
                            <p class="text-xs text-[#646464]"><?= $count ?> <?= $status === 'open' ? 'open ' : '' ?>report<?= $count === 1 ? '' : 's' ?></p>
                        </div>
                        <?php if ($case['pause'] !== null): ?>
                            <span class="rounded-full border border-[#F5D78E] bg-[#FFF8E6] px-2 py-0.5 text-xs font-semibold text-[#3D3D3D]">Paused · <?= $hours ?>h left</span>
                        <?php endif; ?>
                        <?php if ($case['restriction'] !== null): ?>
                            <span class="rounded-full border border-gray-300 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-[#3D3D3D]">Restricted · <?= $case['restriction']['kind'] === 'no_send' ? 'no sending' : 'no new conversations' ?> · <?= $case['restriction']['ends_at'] === null ? 'until lifted' : 'until ' . esc(date('M j', strtotime((string) $case['restriction']['ends_at']))) ?></span>
                            <?php if ($status === 'open'): ?>
                                <form method="post" action="<?= esc(site_url('messages/reports/person/' . $uid . '/lift')) ?>" class="contents">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="rounded-full border border-gray-300 px-3 py-0.5 text-xs font-semibold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Lift restriction</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <ul class="flex flex-col divide-y divide-gray-100 rounded-xl border border-gray-100">
                        <?php foreach ($case['reports'] as $row): ?>
                            <li>
                                <a href="<?= esc(site_url('messages/reports/' . (int) $row['id'])) ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50 pointer-coarse:min-h-11">
                                    <?php if (($row['reporter_avatar'] ?? null) !== null): ?>
                                        <?php // Inside the row link, so never a button; the legal name beside it is the text. ?>
                                        <?= view('components/avatar', ['person' => $row['reporter_avatar']['person'], 'size' => 'sm', 'decorative' => true], ['saveData' => false]) ?>
                                    <?php endif; ?>
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <span class="text-sm font-semibold text-gray-900"><?= esc($reasons[$row['reason']] ?? $row['reason']) ?> · <?= esc($row['conversation_name']) ?></span>
                                        <span class="text-xs text-[#646464]">Reported by <?= esc($row['reporter_name']) ?> · <?= esc(date('M j, Y g:i A', strtotime((string) $row['created_at']))) ?></span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($status === 'open'): ?>
                        <form method="post" action="<?= esc(site_url('messages/reports/person/' . $uid . '/review')) ?>" class="flex flex-wrap items-center gap-2">
                            <?= csrf_field() ?>
                            <button type="submit" name="decision" value="dismiss" class="<?= $btn ?> border border-gray-300 text-gray-800 hover:bg-gray-50 focus-visible:ring-brand/50">Dismiss all</button>
                            <button type="button" data-dialog-open="remove-<?= $uid ?>" class="<?= $btn ?> bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-700/50">Remove reported messages</button>
                            <button type="button" data-dialog-open="restrict-<?= $uid ?>" class="<?= $btn ?> bg-brand text-white hover:bg-brand-dark focus-visible:ring-brand/50">Restrict…</button>
                        </form>
                        <p class="text-xs text-[#646464]">Each report can still be opened and decided on its own.</p>

                        <?= view('components/confirm', [
                            'id' => 'remove-' . $uid, 'title' => 'Remove reported messages', 'tone' => 'danger', 'confirmLabel' => 'Remove',
                            'text' => 'The reported messages are blanked for everyone in those chats, including direct messages, and the reports are marked actioned.',
                            'action' => site_url('messages/reports/person/' . $uid . '/review'), 'fields' => '<input type="hidden" name="decision" value="remove">',
                        ], ['saveData' => false]) ?>

                        <?php ob_start(); ?>
                        <form id="restrict-form-<?= $uid ?>" method="post" action="<?= esc(site_url('messages/reports/person/' . $uid . '/restrict')) ?>" class="flex flex-col gap-4">
                            <?= csrf_field() ?>
                            <fieldset class="flex flex-col gap-2">
                                <legend class="mb-1 text-sm font-semibold text-[#1A1C1E]">What stops</legend>
                                <label class="flex items-center gap-2 pointer-coarse:min-h-11"><input type="radio" name="kind" value="no_new" checked class="accent-[#0040A6]"> No new conversations</label>
                                <label class="flex items-center gap-2 pointer-coarse:min-h-11"><input type="radio" name="kind" value="no_send" class="accent-[#0040A6]"> No sending at all (course chats they teach still work)</label>
                            </fieldset>
                            <div class="flex flex-col gap-1.5">
                                <label for="restrict-for-<?= $uid ?>" class="text-sm font-semibold text-[#1A1C1E]">For</label>
                                <select id="restrict-for-<?= $uid ?>" name="duration" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-[#1A1C1E] pointer-coarse:min-h-11 focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 sm:text-sm">
                                    <?php foreach ($durations as $value => $label): ?>
                                        <option value="<?= esc($value) ?>"<?= $value === '7d' ? ' selected' : '' ?>><?= esc($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <?php $body = ob_get_clean(); ?>
                        <?= view('components/dialog', [
                            'id'     => 'restrict-' . $uid, 'title' => 'Restrict ' . $case['name'], 'size' => 'sm', 'body' => $body,
                            'footer' => '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Cancel</button>'
                                . '<button type="submit" form="restrict-form-' . $uid . '" class="rounded-full bg-brand px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Restrict</button>',
                        ], ['saveData' => false]) ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>

        <div class="flex flex-col gap-1 text-sm text-[#3D3D3D]">
            <p><span class="font-semibold">Reason:</span> <?= esc($reasons[$report['reason']] ?? $report['reason']) ?></p>
            <p><span class="font-semibold">Where:</span> <?= esc($types[$report['conversation_type']] ?? $report['conversation_type']) ?></p>
            <p><span class="font-semibold">Status:</span> <?= esc($tabs[$report['status']] ?? $report['status']) ?><?= $report['removed'] ? ' · the message was removed' : '' ?></p>
            <?php if ($report['note'] !== null && $report['note'] !== ''): ?>
                <p class="mt-1 break-words rounded-lg bg-gray-50 px-3 py-2"><span class="font-semibold">Reporter's note:</span> <?= nl2br(esc($report['note']), false) ?></p>
            <?php endif; ?>
        </div>

        <section aria-label="What was reported" class="flex flex-col gap-2">
            <h2 class="text-sm font-bold text-gray-900">The messages as they were when reported</h2>
            <?php if ($report['snapshot'] === []): ?>
                <p class="text-sm text-[#646464]">The messages could not be read.</p>
            <?php else: ?>
                <?php foreach ($report['snapshot'] as $line): ?>
                    <?php $reported = (int) $line['id'] === (int) $report['reported_id']; ?>
                    <article class="rounded-xl border px-4 py-2 <?= $reported ? 'border-brand bg-brand-tint' : 'border-gray-200 bg-white' ?>" <?= $reported ? 'aria-label="The reported message"' : '' ?>>
                        <p class="flex items-center gap-2 text-xs text-[#767676]">
                            <?php if (($line['avatar'] ?? null) !== null): ?>
                                <?= view('components/avatar', ['person' => $line['avatar']['person'], 'size' => 'xs', 'interactive' => $line['avatar']['interactive'], 'decorative' => true], ['saveData' => false]) ?>
                            <?php endif; ?>
                            <span><span class="font-semibold text-[#3D3D3D]"><?= esc($line['sender_name']) ?></span>
                            · <?= esc(date('M j, Y g:i A', strtotime((string) $line['created_at']))) ?><?= $reported ? ' · reported' : '' ?></span>
                        </p>
                        <p class="mt-1 break-words text-sm text-[#1A1C1E]"><?= nl2br(esc($line['body']), false) ?></p>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php if ($report['status'] === 'open'): ?>
            <form method="post" action="<?= esc(site_url('messages/reports/' . (int) $report['id'] . '/review')) ?>" class="flex flex-wrap items-center gap-3">
                <?= csrf_field() ?>
                <button type="submit" name="decision" value="dismiss" class="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Dismiss</button>
                <button type="submit" name="decision" value="action" class="rounded-full bg-brand px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Mark as actioned</button>
                <?php if ($report['can_remove']): ?>
                    <button type="submit" name="decision" value="remove" class="rounded-full bg-red-700 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-700/50">Remove the message and mark as actioned</button>
                <?php elseif ($report['removed']): ?>
                    <p class="text-xs text-[#646464]">This message was already removed.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>

    <?php endif; ?>

    </div>
</div>

<?= $this->endSection() ?>
