<?php
/**
 * Messages: the shell resources/js/pages/messages fills in (layout "A · Filter chips" of the design). Two
 * cards: the list (chips, search, rows) and the thread (header, messages, composer). Below `md` only one
 * shows at a time; data-view on #messages-app says which. No message text is rendered here.
 *
 * @var string $openId a conversation id from the address, or ''
 */
$chips = ['all' => 'All', 'course' => 'Courses', 'direct' => 'Direct', 'group' => 'Groups'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div id="messages-app" class="group mx-auto w-full max-w-6xl" data-view="list"
     data-api="<?= esc(site_url('messages/api')) ?>"
     data-user-id="<?= esc((string) auth()->id()) ?>"
     data-open="<?= esc($openId) ?>"
     data-can-group="<?= ($canStartGroups ?? false) ? '1' : '0' ?>">

    <div class="grid -mb-8 lg:-mb-4 h-[calc(100vh-5.3rem)] min-h-[440px] gap-3 md:grid-cols-[380px_1fr]">

        <section data-pane="list" aria-label="Conversations" class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm group-data-[view=thread]:max-md:hidden">
            <div class="flex items-center justify-between px-4 pb-3 pt-4">
                <h1 class="font-display text-[17px] font-bold text-brand">Messages</h1>
                <span class="flex items-center gap-1.5">
                    <?php if ($canReview ?? false): ?>
                        <a href="<?= esc(site_url('messages/reports')) ?>" class="rounded-full border border-gray-300 px-3 py-1 text-[13px] font-semibold text-[#646464] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Reports</a>
                    <?php endif; ?>
                    <button type="button" data-role="new-group" data-dialog-open="messages-group" hidden class="rounded-full border border-brand px-3 py-1 text-[13px] font-semibold text-brand hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">+ New group</button>
                    <button type="button" data-role="prefs-open" data-dialog-open="messages-prefs" aria-label="Notification settings" class="rounded-full border border-gray-300 px-2.5 py-1 text-[13px] font-semibold text-[#646464] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Alerts</button>
                    <button type="button" data-role="new" data-dialog-open="messages-new" class="rounded-full bg-brand px-3 py-1 text-[13px] font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">+ New</button>
                </span>
            </div>

            <div class="px-4 pb-3">
                <label for="messages-search" class="sr-only">Search people or courses</label>
                <input id="messages-search" data-role="search" type="search" autocomplete="off" placeholder="Search people or courses" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
            </div>

            <div class="flex flex-nowrap gap-1.5 overflow-x-auto px-4 pb-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" role="group" aria-label="Filter conversations">
                <?php foreach ($chips as $key => $label): ?>
                    <button type="button" data-chip="<?= esc($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>" class="rounded-full border flex-none px-2 py-0.5 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $key === 'all' ? 'border-brand bg-brand text-white' : 'border-gray-300 text-[#646464]' ?>"><?= esc($label) ?></button>
                <?php endforeach; ?>
                <button type="button" data-role="unread-toggle" aria-pressed="false" class="rounded-full border border-gray-300 flex-none px-2 py-0.5 text-[13px] text-[#646464] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Unread only</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto" data-role="list" aria-live="off">
                <div data-state="loading" class="flex flex-col gap-2 p-3" aria-hidden="true">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="flex animate-pulse items-center gap-2"><div class="h-[34px] w-[34px] rounded-full bg-gray-200"></div><div class="flex-1 space-y-1.5"><div class="h-2.5 w-2/3 rounded bg-gray-200"></div><div class="h-2 w-1/2 rounded bg-gray-100"></div></div></div>
                    <?php endfor; ?>
                </div>
                <p data-state="empty" hidden class="p-5 text-center text-[13px] text-[#646464]">No conversations yet. Start one with New message.</p>
                <p data-state="empty-archived" hidden class="p-5 text-center text-[13px] text-[#646464]">No archived chats</p>
                <p data-state="no-match" hidden class="p-5 text-center text-[13px] text-[#646464]">Nothing matches.</p>
                <div data-state="error" hidden class="p-5 text-center text-[13px] text-[#646464]">
                    <p>Messages could not load.</p>
                    <button type="button" data-role="retry-list" class="mt-2 rounded-full border border-brand px-3 py-1 text-[13px] font-semibold text-brand hover:bg-brand/10">Try again</button>
                </div>
                <p data-role="list-note" role="status" aria-live="polite" hidden class="border-b border-gray-100 bg-brand-tint px-4 py-1.5 text-center text-[13px] text-[#646464]"></p>
                <button type="button" data-role="archived-open" hidden class="flex w-full items-center justify-between border-b border-gray-100 px-4 py-2.5 text-left text-[13px] font-semibold text-brand hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"><span>Archived chats (<span data-role="archived-count">0</span>)</span><span aria-hidden="true">&rsaquo;</span></button>
                <button type="button" data-role="archived-back" hidden class="flex w-full items-center gap-1 border-b border-gray-100 px-4 py-2.5 text-left text-[13px] font-semibold text-brand hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"><span aria-hidden="true">&lsaquo;</span> Back to chats</button>
                <div data-role="rows"></div>
            </div>
        </section>

        <section data-pane="thread" aria-label="Conversation" class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm group-data-[view=list]:max-md:hidden">

            <div data-state="none" class="grid flex-1 place-items-center p-6 text-center text-[15px] text-[#646464]">
                <p>Choose a conversation, or start a new one.</p>
            </div>

            <div data-role="thread" hidden class="flex min-h-0 flex-1 flex-col">
                <div class="flex items-center gap-2.5 border-b border-gray-100 px-3.5 py-2.5">
                    <button type="button" data-role="back" aria-label="Back to conversations" class="rounded p-1 text-[#646464] hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 md:hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span data-role="head-avatar" aria-hidden="true" class="grid h-[34px] w-[34px] flex-none place-items-center rounded-full bg-[#E0E5F2] text-[13px] font-semibold text-brand-dark"></span>
                    <div class="min-w-0">
                        <h2 data-role="head-name" class="truncate text-[15px] font-bold text-brand"></h2>
                        <p data-role="head-sub" class="truncate text-[13px] text-[#646464]"></p>
                    </div>
                    <button type="button" data-role="block" hidden class="ml-auto flex-none rounded-full border border-gray-300 px-3 py-1 text-[13px] font-semibold text-[#646464] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></button>
                    <button type="button" data-role="lock" hidden aria-pressed="false" class="ml-auto flex-none rounded-full border border-gray-300 px-3 py-1 text-[13px] font-semibold text-brand hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Lock chat</button>
                </div>
                <p data-role="safety-note" role="status" hidden class="border-b border-gray-100 bg-brand-tint px-3.5 py-1.5 text-center text-[13px] text-[#646464]"></p>
                <p data-role="lock-note" role="status" hidden class="border-b border-gray-100 bg-brand-tint px-3.5 py-1.5 text-center text-[13px] text-[#646464]"></p>

                <div class="relative min-h-0 flex-1 overflow-y-auto bg-[#fafbfd] px-3.5 py-3" data-role="scroller">
                    <button type="button" data-role="older" hidden class="mx-auto mb-2 block rounded-full border border-gray-300 bg-white px-3 py-1 text-[13px] text-[#646464] hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Load older messages</button>
                    <div data-role="messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Messages" class="flex flex-col gap-2"></div>
                    <p data-state="thread-empty" hidden class="py-10 text-center text-[13px] text-[#646464]">No messages yet. Say hello.</p>
                    <p data-state="thread-loading" hidden class="py-10 text-center text-[13px] text-[#646464]">Loading…</p>
                    <div data-state="thread-error" hidden class="py-10 text-center text-[13px] text-[#646464]">
                        <p>This conversation could not load.</p>
                        <button type="button" data-role="retry-thread" class="mt-2 rounded-full border border-brand px-3 py-1 text-[13px] font-semibold text-brand hover:bg-brand/10">Try again</button>
                    </div>
                    <p data-state="gone" hidden class="py-10 text-center text-[15px] text-[#646464]">This conversation is no longer available.</p>
                </div>

                <form data-role="composer" class="flex items-end gap-2 border-t border-gray-100 px-3 py-2">
                    <label for="messages-body" class="sr-only">Write a message</label>
                    <textarea id="messages-body" data-role="body" rows="1" maxlength="4000" placeholder="Write a message…" class="max-h-32 min-h-[34px] flex-1 resize-none rounded-2xl border border-gray-300 px-3 py-1.5 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></textarea>
                    <button type="submit" data-role="send" disabled class="rounded-full bg-brand px-4 py-1.5 text-[13px] font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50">Send</button>
                </form>
                <p data-role="cannot-post" hidden class="border-t border-gray-100 px-3.5 py-3 text-center text-[13px] text-[#646464]"></p>
            </div>
        </section>
    </div>

    <?= view('components/dialog', [
        'id'    => 'messages-new',
        'title' => 'New message',
        'size'  => 'sm',
        'body'  => '<div>'
            . '<label for="messages-picker" class="mb-1 block text-xs font-medium text-gray-600">Find a person by name</label>'
            . '<input id="messages-picker" data-role="picker-input" type="search" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">'
            . '<ul data-role="picker-results" class="mt-3 flex max-h-64 flex-col overflow-y-auto" aria-live="polite"></ul>'
            . '<p data-role="picker-state" class="mt-2 text-xs text-[#646464]">Type at least two letters of a name.</p>'
            . '</div>',
    ], ['saveData' => false]) ?>

    <button type="button" data-role="report-open" data-dialog-open="messages-report" hidden aria-hidden="true" tabindex="-1"></button>

    <?= view('components/dialog', [
        'id'    => 'messages-report',
        'title' => 'Report this message',
        'size'  => 'sm',
        'body'  => '<div class="flex flex-col gap-3">'
            . '<p class="text-xs text-[#646464]">Your institution\'s administrator will see this message and the few before it. The person who wrote it is not told.</p>'
            . '<div><label for="messages-report-reason" class="mb-1 block text-xs font-medium text-gray-600">Why are you reporting it?</label>'
            . '<select id="messages-report-reason" data-role="report-reason" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></select></div>'
            . '<div><label for="messages-report-note" class="mb-1 block text-xs font-medium text-gray-600">Anything to add? (optional)</label>'
            . '<textarea id="messages-report-note" data-role="report-note" rows="3" maxlength="500" class="w-full resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></textarea></div>'
            . '<p data-role="report-state" role="status" class="text-xs text-[#C33939]"></p>'
            . '<button type="button" data-role="report-send" disabled class="self-end rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50">Send report</button>'
            . '</div>',
    ], ['saveData' => false]) ?>

    <?= view('components/dialog', [
        'id'    => 'messages-prefs',
        'title' => 'Notification settings',
        'size'  => 'sm',
        'body'  => '<div class="flex flex-col gap-3">'
            . '<p class="text-xs text-[#646464]">Notifications and emails say who wrote and where. They never include what was written.</p>'
            . '<label class="flex items-center gap-2 text-sm text-[#1A1C1E]"><input type="checkbox" data-role="prefs-email" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40"> Email me about new messages</label>'
            . '<fieldset data-role="prefs-quiet" hidden class="flex flex-col gap-2"><legend class="text-sm font-semibold text-gray-900">Quiet hours</legend>'
            . '<p class="text-xs text-[#646464]">Nothing is notified or emailed during these hours; messages still arrive and are counted.</p>'
            . '<div class="flex items-center gap-2"><label class="text-xs text-gray-600" for="messages-prefs-start">From</label><input id="messages-prefs-start" data-role="prefs-start" type="time" class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">'
            . '<label class="text-xs text-gray-600" for="messages-prefs-end">to</label><input id="messages-prefs-end" data-role="prefs-end" type="time" class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></div></fieldset>'
            . '<p data-role="prefs-note" role="status" class="text-xs text-[#646464]"></p>'
            . '<button type="button" data-role="prefs-save" class="self-end rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save</button>'
            . '</div>',
    ], ['saveData' => false]) ?>

    <?= view('components/dialog', [
        'id'    => 'messages-group',
        'title' => 'New group',
        'size'  => 'sm',
        'body'  => '<div class="flex flex-col gap-3">'
            . '<div><label for="messages-group-title" class="mb-1 block text-xs font-medium text-gray-600">Group name</label>'
            . '<input id="messages-group-title" data-role="group-title" type="text" maxlength="80" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></div>'
            . '<div><label for="messages-group-search" class="mb-1 block text-xs font-medium text-gray-600">Add people by name</label>'
            . '<input id="messages-group-search" data-role="group-search" type="search" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">'
            . '<ul data-role="group-results" class="mt-2 flex max-h-40 flex-col overflow-y-auto" aria-live="polite"></ul></div>'
            . '<ul data-role="group-chips" class="flex flex-wrap gap-1.5" aria-label="People in the group"></ul>'
            . '<p data-role="group-note" role="status" class="text-xs text-[#646464]"></p>'
            . '<button type="button" data-role="group-create" disabled class="self-end rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50">Create group</button>'
            . '</div>',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
