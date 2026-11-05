<?php
/**
 * Message reports, for the institutional administrator: the list by status, or one report with the
 * snapshot taken when it was reported (the message and up to five before it) and the decision buttons.
 * This is the only place chat text is shown outside a conversation, so every piece of it is escaped.
 *
 * @var string                                   $status  open|dismissed|actioned
 * @var list<array<string, mixed>>               $reports rows of ReportReviewService::list()
 * @var array<string, mixed>|null                $report  ReportReviewService::view(), when one report is open
 */
$reasons = ['harassment' => 'Harassment', 'spam' => 'Spam', 'inappropriate' => 'Inappropriate', 'other' => 'Other'];
$types   = ['course' => 'Course chat', 'group' => 'Staff group', 'direct' => 'Direct message'];
$tabs    = ['open' => 'Open', 'dismissed' => 'Dismissed', 'actioned' => 'Actioned'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto mt-5 flex w-full max-w-4xl flex-col gap-5 rounded-[28px] border border-white/90 bg-white/60 p-5 shadow-[0_8px_30px_rgba(0,40,120,0.12)] backdrop-blur sm:p-7" data-messages-reports>

    <div class="flex items-center justify-between gap-3">
        <h1 class="font-display text-xl font-bold text-brand"><?= $report === null ? 'Message reports' : 'Message report' ?></h1>
        <a href="<?= esc($report === null ? site_url('messages') : site_url('messages/reports')) ?>" class="text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
            <?= $report === null ? 'Back to Messages' : 'Back to reports' ?>
        </a>
    </div>

    <?php if ($report === null): ?>

        <nav class="flex gap-2" aria-label="Report status">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?= esc(site_url('messages/reports?status=' . $key)) ?>"
                   class="rounded-full border px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $key === $status ? 'border-brand bg-brand text-white' : 'border-gray-300 text-[#646464] hover:bg-gray-50' ?>"
                   <?= $key === $status ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($reports === []): ?>
            <?= view('components/empty-state', ['icon' => 'inbox', 'title' => 'No ' . $status . ' reports', 'text' => 'Reports from people in your institution appear here.'], ['saveData' => false]) ?>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white shadow-sm">
                <?php foreach ($reports as $row): ?>
                    <li>
                        <a href="<?= esc(site_url('messages/reports/' . (int) $row['id'])) ?>" class="flex flex-col gap-0.5 px-5 py-3 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50">
                            <span class="text-sm font-semibold text-gray-900"><?= esc($reasons[$row['reason']] ?? $row['reason']) ?> · <?= esc($row['conversation_name']) ?></span>
                            <span class="text-xs text-[#646464]">Reported by <?= esc($row['reporter_name']) ?> · <?= esc(date('M j, Y g:i A', strtotime((string) $row['created_at']))) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
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
                        <p class="text-xs text-[#767676]">
                            <span class="font-semibold text-[#3D3D3D]"><?= esc($line['sender_name']) ?></span>
                            · <?= esc(date('M j, Y g:i A', strtotime((string) $line['created_at']))) ?><?= $reported ? ' · reported' : '' ?>
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
                <?php elseif ($report['conversation_type'] === 'direct'): ?>
                    <p class="text-xs text-[#646464]">Direct messages are never removed. Dismiss the report or mark it as actioned.</p>
                <?php elseif ($report['removed']): ?>
                    <p class="text-xs text-[#646464]">This message was already removed.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
