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

    <div class="grid h-[calc(100vh-11rem)] min-h-[440px] gap-3 md:grid-cols-[260px_1fr]">

        <section data-pane="list" aria-label="Conversations" class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm group-data-[view=thread]:max-md:hidden">
            <div class="flex items-center justify-between px-3 pb-1.5 pt-3">
                <h1 class="font-display text-[15px] font-bold text-brand">Messages</h1>
                <button type="button" data-role="new" data-dialog-open="messages-new" class="rounded-full bg-brand px-3 py-1 text-[11px] font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">+ New</button>
            </div>

            <div class="px-3 pb-1.5">
                <label for="messages-search" class="sr-only">Search people or courses</label>
                <input id="messages-search" data-role="search" type="search" autocomplete="off" placeholder="Search people or courses" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
            </div>

            <div class="flex flex-wrap gap-1.5 px-3 pb-1.5" role="group" aria-label="Filter conversations">
                <?php foreach ($chips as $key => $label): ?>
                    <button type="button" data-chip="<?= esc($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>" class="rounded-full border px-2.5 py-0.5 text-[11px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $key === 'all' ? 'border-brand bg-brand text-white' : 'border-gray-300 text-[#646464]' ?>"><?= esc($label) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="px-3 pb-2">
                <button type="button" data-role="unread-toggle" aria-pressed="false" class="rounded-full border border-gray-300 px-2.5 py-0.5 text-[11px] text-[#646464] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Unread only</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto" data-role="list" aria-live="off">
                <div data-state="loading" class="flex flex-col gap-2 p-3" aria-hidden="true">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="flex animate-pulse items-center gap-2"><div class="h-[30px] w-[30px] rounded-full bg-gray-200"></div><div class="flex-1 space-y-1.5"><div class="h-2.5 w-2/3 rounded bg-gray-200"></div><div class="h-2 w-1/2 rounded bg-gray-100"></div></div></div>
                    <?php endfor; ?>
                </div>
                <p data-state="empty" hidden class="p-5 text-center text-xs text-[#646464]">No conversations yet. Start one with New message.</p>
                <p data-state="no-match" hidden class="p-5 text-center text-xs text-[#646464]">Nothing matches.</p>
                <div data-state="error" hidden class="p-5 text-center text-xs text-[#646464]">
                    <p>Messages could not load.</p>
                    <button type="button" data-role="retry-list" class="mt-2 rounded-full border border-brand px-3 py-1 text-xs font-semibold text-brand hover:bg-brand/10">Try again</button>
                </div>
                <div data-role="rows"></div>
            </div>
        </section>

        <section data-pane="thread" aria-label="Conversation" class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm group-data-[view=list]:max-md:hidden">

            <div data-state="none" class="grid flex-1 place-items-center p-6 text-center text-sm text-[#646464]">
                <p>Choose a conversation, or start a new one.</p>
            </div>

            <div data-role="thread" hidden class="flex min-h-0 flex-1 flex-col">
                <div class="flex items-center gap-2.5 border-b border-gray-100 px-3.5 py-2.5">
                    <button type="button" data-role="back" aria-label="Back to conversations" class="rounded p-1 text-[#646464] hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 md:hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span data-role="head-avatar" aria-hidden="true" class="grid h-[30px] w-[30px] flex-none place-items-center rounded-full bg-[#E0E5F2] text-[11px] font-semibold text-brand-dark"></span>
                    <div class="min-w-0">
                        <h2 data-role="head-name" class="truncate text-[13px] font-bold text-brand"></h2>
                        <p data-role="head-sub" class="truncate text-[11px] text-[#646464]"></p>
                    </div>
                    <button type="button" data-role="lock" hidden aria-pressed="false" class="ml-auto flex-none rounded-full border border-gray-300 px-3 py-1 text-[11px] font-semibold text-brand hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Lock chat</button>
                </div>
                <p data-role="lock-note" role="status" hidden class="border-b border-gray-100 bg-brand-tint px-3.5 py-1.5 text-center text-[11px] text-[#646464]"></p>

                <div class="relative min-h-0 flex-1 overflow-y-auto bg-[#fafbfd] px-3.5 py-3" data-role="scroller">
                    <button type="button" data-role="older" hidden class="mx-auto mb-2 block rounded-full border border-gray-300 bg-white px-3 py-1 text-[11px] text-[#646464] hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Load older messages</button>
                    <div data-role="messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Messages" class="flex flex-col gap-2"></div>
                    <p data-state="thread-empty" hidden class="py-10 text-center text-xs text-[#646464]">No messages yet. Say hello.</p>
                    <p data-state="thread-loading" hidden class="py-10 text-center text-xs text-[#646464]">Loading…</p>
                    <div data-state="thread-error" hidden class="py-10 text-center text-xs text-[#646464]">
                        <p>This conversation could not load.</p>
                        <button type="button" data-role="retry-thread" class="mt-2 rounded-full border border-brand px-3 py-1 text-xs font-semibold text-brand hover:bg-brand/10">Try again</button>
                    </div>
                    <p data-state="gone" hidden class="py-10 text-center text-sm text-[#646464]">This conversation is no longer available.</p>
                </div>

                <form data-role="composer" class="flex items-end gap-2 border-t border-gray-100 px-3 py-2">
                    <label for="messages-body" class="sr-only">Write a message</label>
                    <textarea id="messages-body" data-role="body" rows="1" maxlength="4000" placeholder="Write a message…" class="max-h-32 min-h-[34px] flex-1 resize-none rounded-2xl border border-gray-300 px-3 py-1.5 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></textarea>
                    <button type="submit" data-role="send" disabled class="rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50">Send</button>
                </form>
                <p data-role="cannot-post" hidden class="border-t border-gray-100 px-3.5 py-3 text-center text-xs text-[#646464]"></p>
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

</div>

<?= $this->endSection() ?>
