/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
| The page behind pages/messages/index.php (design "A · Filter chips"): the
| conversation list on the left, the open thread on the right; below `md`
| one at a time (data-view on #messages-app says which).
|
| The server decides everything that matters: who can be messaged (the
| picker only shows what /recipients returns), whether the person can post
| (`can_post` on each row), and what a conversation is (a 404 means it is
| not theirs, and the page says it is no longer available). This file shows
| it and keeps it fresh: the open thread is polled every 5 s while the tab
| is visible, the list every 15 s, and both slow to 30 s when the tab is
| hidden. An answer for a conversation or filter the person has since left
| is dropped, never drawn.
|
| Text from the server is only ever set as text (render.js).
|--------------------------------------------------------------------------
*/

import { createApi } from "./api.js";
import { canSend, createComposer } from "./composer.js";
import { createGroupPicker, MAX_MEMBERS } from "./group.js";
import { REASONS, describeFailure } from "./safety.js";
import { createPicker } from "./picker.js";
import { createGuard, createPoller, watchVisibility } from "./poller.js";
import { bindMenuTriggers } from "./gestures.js";
import { chatMenuItems, messageMenuItems } from "./menu-items.js";
import { mergeById, stepIndex, toggleReaction } from "./thread-state.js";
import { createSettings } from "./settings.js";
import { createMenu } from "./menu.js";
import { conversationRow, dayLabel, hoverTime, messageNode } from "./render.js";

const CHIP_ON = ["border-brand", "bg-brand", "text-white"];
const CHIP_OFF = ["border-gray-300", "text-[#646464]"];

const ROLE_WORDS = { instructor: "Instructor", chairperson: "Chairperson", dean: "Dean", parent: "Parent", student: "Student", institutional_admin: "Administrator" };

const pad = (n) => String(n).padStart(2, "0");
const stamp = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
const visible = () => document.visibilityState !== "hidden";

export function mountMessages(root, { api, now = () => new Date() }) {
    const $ = (selector) => root.querySelector(selector);
    const rowsEl = $("[data-role=rows]");
    const messagesEl = $("[data-role=messages]");
    const scroller = $("[data-role=scroller]");
    const bodyEl = $("[data-role=body]");
    const sendEl = $("[data-role=send]");
    const composerEl = $("[data-role=composer]");
    const cannotPostEl = $("[data-role=cannot-post]");
    const blockEl = $("[data-role=block]");
    const safetyNoteEl = $("[data-role=safety-note]");
    const lockEl = $("[data-role=lock]");
    const lockNoteEl = $("[data-role=lock-note]");
    const olderEl = $("[data-role=older]");
    const archivedOpenEl = $("[data-role=archived-open]");
    const listNoteEl = $("[data-role=list-note]");
    const archivedBackEl = $("[data-role=archived-back]");
    const pickerNote = $("[data-role=picker-state]");
    const pinbarEl = $("[data-role=pinbar]");
    const replyStripEl = $("[data-role=reply-strip]");
    const toastEl = $("[data-role=toast]");

    const listGuard = createGuard();
    const threadGuard = createGuard();

    const state = {
        filter: "all",
        unreadOnly: false,
        archived: false,
        switching: false,
        archivedCount: 0,
        search: "",
        conversations: [],
        listLoaded: false,
        openId: null,
        items: [],
        hasMore: false,
        composer: null,
        sending: null,
        poller: null,
        localSeq: 0,
        // The poll cursor: the newest message the poll or the first page has delivered. A message I send
        // does not move it, or a lower-numbered one from someone else would be skipped.
        cursor: 0,
        token: null,
        loadingOlder: false,
        confirmRemove: null,
        confirmBlock: null,
        reportFor: null,
        reporting: false,
        replyTo: null,
        since: "",
        pinned: [],
        pinIndex: 0,
    };

    // ---- small helpers -------------------------------------------------

    const only = (scope, name) => scope.querySelectorAll("[data-state]").forEach((el) => { el.hidden = el.dataset.state !== name; });
    const listState = (name) => only($("[data-role=list]"), name);
    const threadState = (name) => only($("[data-role=scroller]"), name);

    let listNoteTimer = null;

    function showListNote(text) {
        clearTimeout(listNoteTimer);

        if (!listNoteEl) {
            return;
        }

        listNoteEl.textContent = text;
        listNoteEl.hidden = text === "";

        if (text !== "") {
            listNoteTimer = setTimeout(() => showListNote(""), 5000);
        }
    }

    function updateBadge(count) {
        state.badge = count;
        document.querySelectorAll("[data-messages-badge]").forEach((el) => {
            el.textContent = count > 99 ? "99+" : String(count);
            el.hidden = count <= 0;
        });
    }

    const serverItems = () => state.items.filter((item) => typeof item.id === "number");

    // Delivered messages by id, then the ones still being sent.
    const sortItems = () => {
        const sent = state.items.filter((item) => typeof item.id === "number").sort((a, b) => a.id - b.id);

        state.items = [...sent, ...state.items.filter((item) => typeof item.id !== "number")];
    };
    // The open chat can leave the list (archived or muted while another filter is on); its last row keeps its menu working.
    const current = () => state.conversations.find((c) => c.id === state.openId) ?? (state.openRow?.id === state.openId ? state.openRow : null);

    // ---- the list ------------------------------------------------------

    function renderList() {
        const needle = state.search.trim().toLowerCase();
        const shown = state.conversations.filter((c) => needle === "" || [c.name, c.subtitle].some((t) => (t ?? "").toLowerCase().includes(needle)));

        if (archivedOpenEl) {
            archivedOpenEl.hidden = state.archived || state.archivedCount <= 0;
            archivedOpenEl.querySelector("[data-role=archived-count]").textContent = String(state.archivedCount);
        }
        if (archivedBackEl) {
            archivedBackEl.hidden = !state.archived;
        }

        rowsEl.replaceChildren(...shown.map((c) => conversationRow(c, { selected: c.id === state.openId })));

        if (state.conversations.length === 0 && state.filter === "all" && !state.unreadOnly && !state.archived) {
            listState("empty");
        } else {
            listState(shown.length === 0 ? (state.archived && needle === "" ? "empty-archived" : "no-match") : null);
        }
    }

    // The last answer for each filter, shown at once while a fresh one is fetched.
    const listCache = new Map();
    const cacheKey = (unread = state.unreadOnly) => `${state.filter}|${unread}|${state.archived}`;
    const isUnread = (c) => c.unread > 0 || c.marked_unread;

    function showCached() {
        const exact = listCache.get(cacheKey());
        const wider = state.unreadOnly ? listCache.get(cacheKey(false))?.filter(isUnread) : null;
        const known = exact ?? wider;

        if (known) {
            state.conversations = known;
            renderList();
        }
    }

    // Opening a chat reads it: its count goes at once, not after the next refresh.
    function markOpenedRead(id) {
        const row = state.conversations.find((c) => c.id === id);

        if (!row || (!(row.unread > 0) && !row.marked_unread)) {
            return;
        }

        if (!row.muted) {
            updateBadge(Math.max(0, (state.badge ?? 0) - (row.unread > 0 ? row.unread : 0)));
        }

        row.unread = 0;
        row.marked_unread = false;
        renderList();
    }

    async function loadList() {
        const token = listGuard.next();
        const { status, data } = await api.conversations({ filter: state.filter, unread: state.unreadOnly, archived: state.archived });

        if (!listGuard.isCurrent(token)) {
            return;
        }

        if (status !== 200 || !data) {
            if (!state.listLoaded || state.switching) {
                state.switching = false;
                listState("error");
            }

            return;
        }

        state.listLoaded = true;
        listCache.set(cacheKey(), data.conversations);
        state.conversations = data.conversations;
        state.archivedCount = data.archived_count ?? state.archivedCount;
        state.switching = false;
        updateBadge(data.unread ?? 0);

        // The chat on screen is being read, whatever the server has not heard yet.
        const openRow = state.conversations.find((c) => c.id === state.openId);

        if (openRow && (openRow.unread > 0 || openRow.marked_unread)) {
            updateBadge(Math.max(0, (data.unread ?? 0) - (openRow.muted ? 0 : openRow.unread ?? 0)));
            openRow.unread = 0;
            openRow.marked_unread = false;
        }

        renderList();

        if (state.openId !== null && current() !== null) {
            applyConversation(current());
        }
    }

    const listPoller = createPoller({ task: async () => { await loadList(); return "ok"; }, visible, fast: 15000 });

    // ---- the thread ----------------------------------------------------

    function applyConversation(c) {
        if (c) {
            state.openRow = c;
        }

        const avatar = $("[data-role=head-avatar]");

        $("[data-role=head-name]").textContent = c?.name ?? "Conversation";
        $("[data-role=head-sub]").textContent = !c ? "" : c.subtitle ? `About ${c.subtitle}` : c.type === "course" ? "Course chat" : c.type === "group" ? "Group" : "Direct message";
        avatar.textContent = c?.initials ?? "";
        avatar.className = `grid h-[34px] w-[34px] flex-none place-items-center text-[13px] font-semibold ${c?.type === "course" ? "rounded-lg bg-brand text-white" : c?.type === "group" ? "rounded-lg bg-accent text-white" : "rounded-full bg-[#E0E5F2] text-brand-dark"}`;

        const canPost = c === null || c.can_post !== false;
        const locked = Number(c?.is_locked) === 1;

        composerEl.hidden = !canPost;
        cannotPostEl.hidden = canPost;
        cannotPostEl.textContent = locked ? "Only the instructor can post here" : c?.closed ? "This course has ended, so this chat is read-only." : "You can't post in this conversation";

        // The lock belongs to whoever moderates a course chat; a moderator who can still post is told what students see.
        if (lockEl) {
            lockEl.hidden = !(c?.can_moderate && c.type === "course");
            lockEl.textContent = locked ? "Unlock chat" : "Lock chat";
            lockEl.setAttribute("aria-pressed", locked ? "true" : "false");
        }

        showLockNote(locked && canPost && c?.can_moderate ? "Locked: students can read only" : "");

        // A block is for direct threads, with someone a block can shut out; it takes a second click to be sure.
        if (blockEl) {
            const offered = c?.type === "direct" && (c.can_block || c.blocked_by_me);

            blockEl.hidden = !offered;

            if (offered) {
                blockEl.textContent = c.blocked_by_me ? `Unblock ${c.name}` : state.confirmBlock === c.id ? `Click again to block ${c.name}` : `Block ${c.name}`;
            }
        }
    }

    function showSafetyNote(text) {
        if (safetyNoteEl) {
            safetyNoteEl.textContent = text;
            safetyNoteEl.hidden = text === "";
        }
    }

    function showLockNote(text) {
        if (lockNoteEl) {
            lockNoteEl.textContent = text;
            lockNoteEl.hidden = text === "";
        }
    }

    const ICON_BUTTON = "grid h-7 w-7 flex-none place-items-center rounded-full text-gray-500 opacity-0 hover:bg-gray-200/70 hover:text-gray-800 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 group-hover/msg:opacity-100 [@media(hover:none)]:opacity-100";
    const svg = (d) => `<svg aria-hidden="true" viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
    const ICONS = {
        trash: svg('<path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10M9 9v4M11 9v4"/>'),
        more: svg('<circle cx="10" cy="4.5" r="1" fill="currentColor"/><circle cx="10" cy="10" r="1" fill="currentColor"/><circle cx="10" cy="15.5" r="1" fill="currentColor"/>'),
        flag: svg('<path d="M5 17V3M5 4h9l-2 3 2 3H5"/>'),
        remove: svg('<circle cx="10" cy="10" r="7"/><path d="M7 7l6 6M13 7l-6 6"/>'),
    };

    function messageRow(item) {
        const row = document.createElement("div");

        row.className = `flex flex-col ${item.mine ? "items-end" : "items-start"}`;
        const bubble = messageNode(item, { mine: item.mine, onToggle: (m, emoji) => react(m, emoji), onShowWho: (m, emoji) => showWho(m, emoji) });

        const note = document.createElement("div");

        note.className = "flex flex-none items-center gap-1 text-[12px] text-[#646464]";

        if (item.pending) {
            note.textContent = "Sending…";
        } else if (item.failed) {
            const retry = document.createElement("button");

            note.append(document.createTextNode(`${item.failNote ?? "Not sent"} — `));
            retry.type = "button";
            retry.dataset.action = "retry";
            retry.dataset.key = String(item.id);
            retry.className = "font-semibold text-brand underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
            retry.textContent = "Retry";
            note.append(retry);
        } else if (typeof item.id === "number" && !item.removed) {
            const hide = document.createElement("button");

            hide.type = "button";
            hide.dataset.action = "hide";
            hide.dataset.messageId = String(item.id);
            hide.className = ICON_BUTTON;
            hide.setAttribute("aria-label", "Delete");
            hide.title = "Delete";
            hide.innerHTML = ICONS.trash;
            hide.dataset.canEveryone = item.can_delete_everyone ? "true" : "false";
            note.append(hide);

            const more = document.createElement("button");

            more.type = "button";
            more.dataset.action = "more";
            more.dataset.messageId = String(item.id);
            more.setAttribute("aria-label", "Message actions");
            more.className = ICON_BUTTON;
            more.title = "More";
            more.innerHTML = ICONS.more;
            note.append(more);

            if (!item.mine) {
                const report = document.createElement("button");

                report.type = "button";
                report.dataset.action = "report";
                report.dataset.messageId = String(item.id);
                report.className = ICON_BUTTON;
                report.setAttribute("aria-label", "Report");
                report.title = "Report";
                report.innerHTML = ICONS.flag;
                note.append(report);
            }

            // A moderator of this chat can blank the message for everyone; it takes a second click to be sure.
            if (current()?.can_moderate) {
                const confirming = state.confirmRemove === item.id;
                const remove = document.createElement("button");

                remove.type = "button";
                remove.dataset.action = "remove";
                remove.dataset.messageId = String(item.id);
                remove.className = confirming ? "rounded px-1 text-[12px] font-semibold text-red-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" : ICON_BUTTON;

                if (confirming) {
                    remove.textContent = "Click again to remove";
                } else {
                    remove.setAttribute("aria-label", "Remove message");
                    remove.title = "Remove message";
                    remove.innerHTML = ICONS.remove;
                }
                note.append(remove);
            }
        }

        if (note.querySelector("[data-action=hide]") !== null) {
            // Like Messenger: the icons sit beside the bubble, on the side facing the middle of the chat, and
            // show only while the pointer is over the message itself (the group is just as wide as the two).
            const line = document.createElement("div");

            line.className = "group/msg flex w-fit max-w-full items-center gap-1";

            // Shown only while the pointer is over the message: on the left of my messages, the right of theirs.
            const sentAt = document.createElement("time");

            sentAt.dataset.role = "sent-at";
            sentAt.setAttribute("datetime", String(item.created_at ?? ""));
            sentAt.className = "hidden whitespace-nowrap px-1 text-[11px] text-gray-500 group-hover/msg:block";
            sentAt.textContent = hoverTime(item.created_at, new Date());
            line.append(...(item.mine ? [sentAt, note, bubble] : [bubble, note, sentAt]));
            row.append(line);
        } else {
            row.append(bubble);

            if (note.childNodes.length > 0) {
                row.append(note);
            }
        }

        return row;
    }

    function renderMessages({ stick = false, keepPosition = false } = {}) {
        const nearBottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 80;
        const heightBefore = scroller.scrollHeight;
        const nodes = [];
        let day = null;

        for (const item of state.items) {
            const label = dayLabel(item.created_at, now());

            if (label !== day) {
                const separator = document.createElement("div");

                separator.className = "py-1 text-center text-[12px] text-[#646464]";
                separator.textContent = label;
                nodes.push(separator);
                day = label;
            }

            nodes.push(messageRow(item));
        }

        messagesEl.replaceChildren(...nodes);
        olderEl.hidden = !state.hasMore;

        if (state.items.length === 0) {
            threadState("thread-empty");
        } else {
            threadState(null);
        }

        if (keepPosition) {
            scroller.scrollTop += scroller.scrollHeight - heightBefore;
        } else if (stick || nearBottom) {
            scroller.scrollTop = scroller.scrollHeight;
        }
    }

    function showGone() {
        state.poller?.stop();
        state.items = [];
        state.hasMore = false;
        messagesEl.replaceChildren();
        olderEl.hidden = true;
        composerEl.hidden = true;
        cannotPostEl.hidden = true;
        threadState("gone");
    }

    function readUpTo() {
        const id = state.cursor;

        if (id > 0 && state.openId !== null) {
            api.markRead(state.openId, id).then(() => listPoller.nudge());
        }
    }

    async function pollThread(id, token) {
        if (!threadGuard.isCurrent(token)) {
            return "gone";
        }

        const { status, data } = await api.page(id, { after: state.cursor });

        if (!threadGuard.isCurrent(token)) {
            return "gone";
        }

        if (status === 404) {
            showGone();

            return "gone";
        }

        if (status !== 200 || !data) {
            return "error";
        }

        const known = new Set(serverItems().map((item) => item.id));
        const fresh = data.messages.filter((m) => !known.has(m.id));

        if (fresh.length > 0) {
            state.items.push(...fresh);
            state.cursor = Math.max(state.cursor, ...fresh.map((m) => m.id));
            sortItems();
            renderMessages();
            readUpTo();
        }

        await pollChanges(id, token);

        return "ok";
    }

    // Messages reacted to, pinned or erased since the last tick, merged by id.
    async function pollChanges(id, token) {
        if (state.since === "") {
            return;
        }

        const { status, data } = await api.changes(id, state.since);

        if (!threadGuard.isCurrent(token) || status !== 200 || !data) {
            return;
        }

        state.since = data.server_time ?? state.since;

        // A change to a message older than the loaded page is left alone: merging it in would open a gap that
        // "Load older" then skips over. It shows when that page is loaded.
        const loaded = state.items.filter((i) => typeof i.id === "number").map((i) => i.id);
        const newest = loaded.length > 0 ? Math.max(...loaded) : 0;
        const known = new Set(loaded);
        // A message whose reaction is still on its way keeps what the person just picked.
        const busy = new Set(state.items.filter((i) => i.reacting).map((i) => i.id));
        const relevant = data.messages.filter((m) => !busy.has(m.id) && (known.has(m.id) || m.id > newest));

        if (relevant.length > 0) {
            state.items = mergeById(state.items, relevant);
            renderMessages();
            refreshThread();
        }
    }

    async function open(id) {
        state.poller?.stop();

        const token = threadGuard.next();

        state.openId = id;
        state.token = token;
        markOpenedRead(id);
        state.cursor = 0;
        state.loadingOlder = false;
        state.confirmRemove = null;
        state.confirmBlock = null;
        state.pinned = [];
        state.pinIndex = 0;
        setReply(null);
        renderPinbar();
        showSafetyNote("");
        state.items = [];
        state.hasMore = false;
        state.sending = null;
        state.composer = createComposer({
            send: (text, uuid) => api.send(id, text, uuid, state.sending?.replyTo ?? undefined),
            onSent: (message) => sent(id, message),
            onFailed: (reason, data) => failed(id, reason, data),
        });

        root.dataset.view = "thread";
        $("[data-state=none]").hidden = true;
        $("[data-role=thread]").hidden = false;
        messagesEl.replaceChildren();
        olderEl.hidden = true;
        bodyEl.value = "";
        sendEl.disabled = true;
        applyConversation(current());
        threadState("thread-loading");
        renderList();

        // The changes clock starts before the first page so nothing changed in between is missed.
        state.since = "";
        api.changes(id, "").then((clock) => {
            if (threadGuard.isCurrent(token) && clock.status === 200 && clock.data?.server_time && state.since === "") {
                state.since = clock.data.server_time;
            }
        });

        const { status, data } = await api.page(id, {});

        if (!threadGuard.isCurrent(token)) {
            return;
        }

        if (status === 404) {
            showGone();

            return;
        }

        if (status !== 200 || !data) {
            threadState("thread-error");

            return;
        }

        state.items = data.messages;
        state.cursor = Math.max(0, ...data.messages.map((m) => m.id));
        state.hasMore = data.has_more;
        state.pinned = data.pinned ?? [];
        renderPinbar();
        renderMessages({ stick: true });
        readUpTo();

        state.poller = createPoller({ task: () => pollThread(id, token), visible, fast: 5000 });
        state.poller.start();
    }

    function closeThread() {
        state.poller?.stop();
        threadGuard.next();
        state.openId = null;
        $("[data-role=thread]").hidden = true;
        $("[data-state=none]").hidden = false;
        root.dataset.view = "list";
        renderList();
    }

    async function loadOlder() {
        const first = serverItems()[0];

        if (!first || state.openId === null || state.loadingOlder) {
            return;
        }

        const token = state.token;
        const id = state.openId;

        state.loadingOlder = true;

        const { status, data } = await api.page(id, { before: first.id });

        state.loadingOlder = false;

        // The person may have left this thread (or reopened it) while the page was on its way.
        if (!threadGuard.isCurrent(token) || status !== 200 || !data) {
            return;
        }

        const known = new Set(serverItems().map((item) => item.id));

        state.items = [...data.messages.filter((m) => !known.has(m.id)), ...state.items];
        state.hasMore = data.has_more;
        renderMessages({ keepPosition: true });
    }

    // ---- sending -------------------------------------------------------

    function sent(id, message) {
        const item = state.sending;

        state.sending = null;

        if (state.openId !== id) {
            listPoller.nudge();

            return;
        }

        state.items = state.items.filter((i) => i !== item && i.id !== message.id);
        state.items.push(message);
        sortItems();
        renderMessages({ stick: true });
        listPoller.nudge();
    }

    function failed(id, reason, data = null) {
        const item = state.sending;

        state.sending = null;

        if (state.openId !== id || !item) {
            return;
        }

        if (reason === "not_allowed") {
            state.items = state.items.filter((i) => i !== item);
            composerEl.hidden = true;
            cannotPostEl.hidden = false;
            cannotPostEl.textContent = "You can't post in this conversation";
        } else {
            item.pending = false;
            item.failed = true;
            item.failNote = reason === "rate_limited" ? data?.message ?? null : null;

            if (reason === "invalid_reply") {
                // The message being answered is gone. Retry sends it as a plain message instead of failing again.
                item.replyTo = null;
                item.failNote = "The message you replied to is no longer there. Retry sends this without the reply.";
            }
        }

        renderMessages();
    }

    function submit(text, item = null) {
        if (state.openId === null || !canSend(text, state.composer.state === "sending")) {
            return;
        }

        // The id stays with the message, so Retry of an older one can never post it twice.
        const draft = item ?? { id: `local-${++state.localSeq}`, uuid: crypto.randomUUID(), mine: true, body: text.trim(), raw: text, removed: false, created_at: stamp(now()), replyTo: state.replyTo?.id ?? null };

        if (item === null) {
            setReply(null);
        }

        draft.pending = true;
        draft.failed = false;
        state.sending = draft;

        if (!state.items.includes(draft)) {
            state.items.push(draft);
        }

        renderMessages({ stick: true });
        state.composer.submit(text, draft.uuid);
    }

    const syncSend = () => { sendEl.disabled = !canSend(bodyEl.value, state.composer?.state === "sending"); };

    composerEl.addEventListener("submit", (event) => {
        event.preventDefault();

        const text = bodyEl.value;

        if (!canSend(text, state.composer?.state === "sending")) {
            return;
        }

        bodyEl.value = "";
        bodyEl.style.height = "auto";
        syncSend();
        submit(text);
        bodyEl.focus();
    });

    bodyEl.addEventListener("input", () => {
        bodyEl.style.height = "auto";
        bodyEl.style.height = `${Math.min(bodyEl.scrollHeight, 128)}px`;
        syncSend();
    });

    bodyEl.addEventListener("keydown", (event) => {
        if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            composerEl.dispatchEvent(new Event("submit", { cancelable: true }));
        }
    });

    messagesEl.addEventListener("click", async (event) => {
        const quote = event.target.closest("[data-reply-to]");

        if (quote) {
            scrollToMessage(Number(quote.dataset.replyTo));

            return;
        }

        const button = event.target.closest("[data-action]");

        if (!button) {
            return;
        }

        if (button.dataset.action === "more") {
            const rect = button.getBoundingClientRect();

            openMessageMenu(messagesEl.querySelector(`[data-id="${button.dataset.messageId}"]`), { x: rect.left, y: rect.bottom, trigger: button });
        } else if (button.dataset.action === "retry") {
            const item = state.items.find((i) => String(i.id) === button.dataset.key);

            if (item) {
                submit(item.raw, item);
            }
        } else if (button.dataset.action === "report") {
            openReport(Number(button.dataset.messageId));
        } else if (button.dataset.action === "remove") {
            const id = Number(button.dataset.messageId);

            if (state.confirmRemove !== id) {
                state.confirmRemove = id;
                renderMessages();

                return;
            }

            state.confirmRemove = null;

            const { status } = await api.remove(state.openId, id);
            const item = state.items.find((i) => i.id === id);

            if (status === 200 && item) {
                item.removed = true;
                item.body = null;
            }

            renderMessages();
        } else if (button.dataset.action === "hide") {
            const id = Number(button.dataset.messageId);
            const rect = button.getBoundingClientRect();
            const items = [{ id: "delete-me", label: "Delete for me", onSelect: () => hideMessage(id) }];

            // "For everyone" is offered only where the server says it is allowed (own message, inside the window, switch on).
            if (button.dataset.canEveryone === "true") {
                items.push({ id: "delete-everyone", label: "Delete for everyone", danger: true, onSelect: () => deleteEveryone(id) });
            }

            menu.open(items, { x: rect.left, y: rect.bottom, trigger: button });
        }
    });

    lockEl?.addEventListener("click", async () => {
        const c = current();

        if (!c || state.openId === null) {
            return;
        }

        const locking = Number(c.is_locked) !== 1;
        const { status, data } = await (locking ? api.lock(state.openId) : api.unlock(state.openId));

        if (status === 200 && data?.conversation && c.id === state.openId) {
            c.is_locked = data.conversation.is_locked ? 1 : 0;
            applyConversation(c);
            renderList();
        } else {
            showLockNote("Couldn't change that. Try again.");
        }
    });

    // ---- starting a conversation ---------------------------------------

    async function startWith(entry) {
        const { status, data } = await api.start(entry.id, entry.subject_student_id);

        if (status !== 200 || !data?.conversation) {
            pickerNote.textContent = "That person can't be messaged.";

            return;
        }

        const conversation = data.conversation;

        state.conversations = [conversation, ...state.conversations.filter((c) => c.id !== conversation.id)];
        $("[data-role=picker-input]").value = "";
        picker.input("");
        $("#messages-new [data-dialog-close]")?.click();
        open(conversation.id);
    }

    function renderPicker() {
        const list = $("[data-role=picker-results]");

        list.replaceChildren(...picker.results.map((entry) => {
            const item = document.createElement("li");
            const button = document.createElement("button");
            const name = document.createElement("span");
            const meta = document.createElement("span");

            button.type = "button";
            button.className = "flex w-full flex-col rounded-lg px-3 py-2 text-left hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
            name.className = "text-sm font-semibold text-gray-900";
            name.textContent = entry.name;
            meta.className = "text-xs text-[#646464]";
            meta.textContent = entry.subject_name ? `${ROLE_WORDS[entry.role] ?? entry.role} · about ${entry.subject_name}` : (ROLE_WORDS[entry.role] ?? entry.role);
            button.append(name, meta);
            button.addEventListener("click", () => picker.pick(entry));
            item.append(button);

            return item;
        }));

        pickerNote.textContent = { loading: "Searching…", empty: "No one found.", error: "The search failed. Try again." }[picker.state] ?? (picker.results.length > 0 ? "" : "Type at least two letters of a name.");
    }

    const picker = createPicker({ search: (q) => api.recipients(q), onPick: startWith, onChange: renderPicker });

    $("[data-role=picker-input]").addEventListener("input", (event) => picker.input(event.target.value));

    // ---- block, report, settings ---------------------------------------

    blockEl?.addEventListener("click", async () => {
        const c = current();

        if (!c || state.openId === null || c.type !== "direct") {
            return;
        }

        if (c.blocked_by_me) {
            const result = await api.unblock(c.other_user_id);

            if (result.status === 200) {
                c.blocked_by_me = false;
                c.can_block = true;
                showSafetyNote("");
            } else {
                showSafetyNote(describeFailure(result, "Couldn't unblock them. Try again."));
            }
        } else if (state.confirmBlock !== c.id) {
            state.confirmBlock = c.id;
        } else {
            state.confirmBlock = null;

            const result = await api.block(c.other_user_id);

            if (result.status === 200) {
                c.blocked_by_me = true;
                c.can_block = false;
                showSafetyNote(`You blocked ${c.name}. They can't start or reply to messages with you.`);
            } else {
                showSafetyNote(describeFailure(result, "That person can't be blocked."));
            }
        }

        applyConversation(c);
    });

    const reportReason = $("[data-role=report-reason]");
    const reportNote = $("[data-role=report-note]");
    const reportState = $("[data-role=report-state]");
    const reportSend = $("[data-role=report-send]");

    if (reportReason) {
        reportReason.replaceChildren(...[["", "Choose a reason"], ...Object.entries(REASONS)].map(([value, label]) => {
            const option = document.createElement("option");

            option.value = value;
            option.textContent = label;

            return option;
        }));
    }

    const syncReport = () => { reportSend.disabled = state.reporting || reportReason.value === ""; };

    function openReport(messageId) {
        state.reportFor = messageId;
        reportReason.value = "";
        reportNote.value = "";
        reportState.textContent = "";
        syncReport();
        $("[data-role=report-open]")?.click();
    }

    reportReason?.addEventListener("change", syncReport);

    reportSend?.addEventListener("click", async () => {
        if (state.reporting || state.reportFor === null || reportReason.value === "") {
            return;
        }

        state.reporting = true;
        reportState.textContent = "";
        syncReport();

        const result = await api.report(state.openId, state.reportFor, reportReason.value, reportNote.value.trim() || null);

        state.reporting = false;
        syncReport();

        if (result.status === 200) {
            state.reportFor = null;
            $("#messages-report [data-dialog-close]")?.click();
            showSafetyNote("Thanks, we'll look into it.");
        } else {
            reportState.textContent = describeFailure(result, "Couldn't send the report. Try again.");
        }
    });

    const prefsEmail = $("[data-role=prefs-email]");
    const prefsQuiet = $("[data-role=prefs-quiet]");
    const prefsStart = $("[data-role=prefs-start]");
    const prefsEnd = $("[data-role=prefs-end]");
    const prefsNote = $("[data-role=prefs-note]");

    const settings = createSettings({
        root,
        api,
        // The administrator's picker holds only names and switches, so the open chat is reloaded for the new ones.
        onControlsChanged: (c) => {
            if (c.id === state.openId) {
                loadList();
                refreshThread();
            }
        },
    });

    $("[data-role=prefs-open]")?.addEventListener("click", async () => {
        settings.sync();
        prefsNote.textContent = "";

        const { status, data } = await api.preferences();

        if (status !== 200 || !data) {
            prefsNote.textContent = "Couldn't load your settings.";

            return;
        }

        prefsEmail.checked = data.preferences.email_alerts;
        prefsStart.value = data.preferences.quiet_start ?? "";
        prefsEnd.value = data.preferences.quiet_end ?? "";
        prefsQuiet.hidden = !data.quiet_hours_allowed;
    });

    $("[data-role=prefs-save]")?.addEventListener("click", async () => {
        const hours = !prefsQuiet.hidden;
        const result = await api.savePreferences({
            email_alerts: prefsEmail.checked,
            quiet_start: hours ? prefsStart.value || null : null,
            quiet_end: hours ? prefsEnd.value || null : null,
        });

        prefsNote.textContent = result.status === 200 ? "Saved." : result.status === 422 ? "Check the times." : describeFailure(result, "Couldn't save your settings. Try again.");
    });

    // ---- starting a staff group ----------------------------------------
    // Only a chairperson or dean is offered the button; the server decides who may be added and who may start.

    const groupBtn = $("[data-role=new-group]");
    const groupTitle = $("[data-role=group-title]");
    const groupSearch = $("[data-role=group-search]");
    const groupResults = $("[data-role=group-results]");
    const groupChips = $("[data-role=group-chips]");
    const groupNote = $("[data-role=group-note]");
    const groupCreate = $("[data-role=group-create]");
    let creatingGroup = false;
    let groupFailure = "";

    if (groupBtn) {
        groupBtn.hidden = root.dataset.canGroup !== "1";
    }

    function syncGroup() {
        const count = groupPicker.ids().length;

        groupCreate.disabled = creatingGroup || groupTitle.value.trim() === "" || count < 1 || count > MAX_MEMBERS;
    }

    function renderGroup() {
        groupResults.replaceChildren(...groupPicker.results.map((entry) => {
            const item = document.createElement("li");
            const button = document.createElement("button");

            button.type = "button";
            button.className = "w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-gray-900 hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
            button.textContent = entry.name;
            button.title = ROLE_WORDS[entry.role] ?? entry.role;
            button.setAttribute("aria-pressed", String(groupPicker.isChosen(entry.id)));
            button.addEventListener("click", () => groupPicker.toggle(entry));
            item.append(button);

            return item;
        }));

        groupChips.replaceChildren(...groupPicker.chosen().map((entry) => {
            const item = document.createElement("li");
            const name = document.createElement("span");
            const remove = document.createElement("button");

            item.className = "inline-flex items-center rounded-full bg-brand-tint px-2.5 py-0.5 text-xs text-brand-dark";
            name.textContent = entry.name;
            remove.type = "button";
            remove.className = "ml-1 rounded-full px-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
            remove.setAttribute("aria-label", `Remove ${entry.name}`);
            remove.textContent = "×";
            remove.addEventListener("click", () => groupPicker.toggle(entry));
            item.append(name, document.createTextNode(" "), remove);

            return item;
        }));

        groupNote.textContent = groupFailure || ({ loading: "Searching…", empty: "No one found.", error: "The search failed. Try again." }[groupPicker.state] ?? "");
        syncGroup();
    }

    const groupPicker = createGroupPicker({ search: (q) => api.groupCandidates(q), onChange: renderGroup });

    groupSearch?.addEventListener("input", (event) => groupPicker.input(event.target.value));
    groupTitle?.addEventListener("input", () => { groupFailure = ""; renderGroup(); });

    groupCreate?.addEventListener("click", async () => {
        if (creatingGroup) {
            return;
        }

        creatingGroup = true;
        groupFailure = "";
        syncGroup();

        const { status, data } = await api.createGroup(groupTitle.value.trim(), groupPicker.ids());

        creatingGroup = false;

        if (status === 201 && data?.conversation) {
            const conversation = data.conversation;

            state.conversations = [conversation, ...state.conversations.filter((c) => c.id !== conversation.id)];
            groupTitle.value = "";
            groupSearch.value = "";
            groupPicker.reset();
            $("#messages-group [data-dialog-close]")?.click();
            open(conversation.id);

            return;
        }

        groupFailure = "Couldn't create the group";
        renderGroup();
    });

    // ---- the message menu ----------------------------------------------

    let toastTimer = null;

    function toast(text) {
        clearTimeout(toastTimer);

        if (!toastEl) {
            return;
        }

        toastEl.textContent = text;
        toastEl.hidden = text === "";

        if (text !== "") {
            toastTimer = setTimeout(() => toast(""), 4000);
        }
    }

    const messageById = (id) => state.items.find((i) => i.id === id) ?? null;

    function renderPinbar() {
        if (!pinbarEl) {
            return;
        }

        const total = state.pinned.length;

        pinbarEl.hidden = total === 0;

        if (total === 0) {
            return;
        }

        state.pinIndex = Math.min(state.pinIndex, total - 1);

        const pin = state.pinned[state.pinIndex];
        const next = $("[data-role=pinbar-next]");

        $("[data-role=pinbar-text]").textContent = `\u{1F4CC} Pinned · ${pin.sender_name ? `${pin.sender_name}: ` : ""}${pin.text}`;
        next.textContent = `${state.pinIndex + 1} of ${total} ▾`;
        next.hidden = total < 2;
    }

    // Re-read the latest page and merge it, so flags and the pinned bar are what the server says now.
    async function refreshThread() {
        const id = state.openId;
        const token = state.token;

        if (id === null) {
            return;
        }

        const { status, data } = await api.page(id, {});

        if (!threadGuard.isCurrent(token) || status !== 200 || !data) {
            return;
        }

        state.items = mergeById(state.items, data.messages);
        state.pinned = data.pinned ?? [];
        state.cursor = Math.max(state.cursor, ...data.messages.map((m) => m.id));
        renderPinbar();
        renderMessages();
    }

    function scrollToMessage(id) {
        const node = messagesEl.querySelector(`[data-id="${id}"]`);

        if (!node) {
            return;
        }

        node.scrollIntoView?.({ block: "center" });
        node.classList.add("ring-2", "ring-brand");
        setTimeout(() => node.classList.remove("ring-2", "ring-brand"), 1500);
    }

    // Says why an action was refused; a stale menu also refreshes the flags. Returns true when it was a refusal.
    function refused(result) {
        if (result.status === 200) {
            return false;
        }

        const error = result.data?.error;

        if (result.status === 429) {
            toast("Slow down a little.");
        } else if (error === "too_late") {
            toast("It's too late to delete that for everyone.");
        } else if (error === "limit") {
            toast(result.data?.max ? `You can pin up to ${result.data.max} messages.` : "You've reached the limit for that.");
        } else if (result.status === 403) {
            toast("That's turned off in this chat.");
        } else {
            toast("That didn't work. Try again.");
        }

        if (result.status === 403 || result.status === 404) {
            loadList();
            refreshThread();
        }

        return true;
    }

    function setReply(message) {
        state.replyTo = message;

        if (replyStripEl) {
            replyStripEl.hidden = message === null;
            $("[data-role=reply-text]").textContent = message === null ? "" : `Replying to ${message.mine ? "yourself" : message.sender_name ?? "message"}: ${(message.body ?? "").slice(0, 80)}`;
        }

        if (message !== null) {
            bodyEl.focus();
        }
    }

    async function react(m, emoji) {
        if (m.reacting || state.openId === null) {
            return;
        }

        const before = m.reactions ?? [];
        const picked = before.find((r) => r.mine)?.emoji === emoji ? null : emoji;

        m.reacting = true;
        m.reactions = toggleReaction(before, emoji);
        renderMessages();

        const result = await api.react(state.openId, m.id, picked);

        m.reacting = false;

        // A poll may have swapped this message for a fresh copy while the request was in flight.
        const live = messageById(m.id) ?? m;

        live.reacting = false;

        if (result.status === 200 && Array.isArray(result.data?.reactions)) {
            live.reactions = result.data.reactions;
        } else {
            live.reactions = before;
            refused(result);
        }

        renderMessages();
    }

    async function showWho(m, emoji) {
        const { status, data } = await api.reactors(state.openId, m.id);

        if (status !== 200 || !data) {
            toast("Couldn't load who reacted.");

            return;
        }

        const entries = Object.entries(data.reactors ?? {}).filter(([e]) => !emoji || e === emoji);

        $("[data-role=who-list]").replaceChildren(...entries.flatMap(([e, names]) => names.map((name) => {
            const item = document.createElement("li");

            item.textContent = `${e} ${name}`;

            return item;
        })));
        $("[data-role=who-open]")?.click();
    }

    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const area = document.createElement("textarea");

            area.value = text;
            area.style.position = "fixed";
            area.style.opacity = "0";
            document.body.append(area);
            area.select();
            document.execCommand?.("copy");
            area.remove();
        }

        toast("Copied");
    }

    async function hideMessage(id) {
        const { status } = await api.hide(state.openId, id);

        if (status === 200) {
            state.items = state.items.filter((i) => i.id !== id);
            renderMessages();
        }
    }

    async function openForward(m) {
        const list = $("[data-role=forward-list]");
        const send = $("[data-role=forward-send]");
        const note = $("[data-role=forward-state]");
        // Every active chat, whatever filter the list is showing.
        const all = await api.conversations({ filter: "all", unread: false, archived: false });
        const source = all.status === 200 && Array.isArray(all.data?.conversations) ? all.data.conversations : state.conversations;
        const targets = source.filter((c) => c.id !== state.openId && !c.archived && !c.closed && c.can_post !== false);
        const chosen = new Set();
        const boxes = new Map();

        const sync = () => {
            send.disabled = chosen.size === 0;
            list.querySelectorAll("input").forEach((box) => { box.disabled = box.dataset.sent === "true" || (!box.checked && chosen.size >= 5); });
        };

        note.textContent = targets.length === 0 ? "You have no other chats to forward to." : "";
        list.replaceChildren(...targets.map((c) => {
            const item = document.createElement("li");
            const label = document.createElement("label");
            const box = document.createElement("input");

            label.className = "flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-brand-tint";
            box.type = "checkbox";
            box.className = "h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40";
            box.addEventListener("change", () => {
                if (box.checked) {
                    chosen.add(c.id);
                } else {
                    chosen.delete(c.id);
                }

                sync();
            });
            boxes.set(c.id, box);
            label.append(box, document.createTextNode(c.name));
            item.append(label);

            return item;
        }));
        sync();

        send.onclick = async () => {
            send.disabled = true;

            const result = await api.forward(state.openId, m.id, [...chosen]);

            if (result.status !== 200) {
                note.textContent = result.data?.error === "switched_off" ? "Forwarding is turned off in this chat." : "Couldn't forward that. Try again.";
                sync();

                return;
            }

            const failed = (result.data?.results ?? []).filter((r) => !r.ok);

            if (failed.length > 0) {
                const names = failed.map((r) => targets.find((c) => c.id === r.conversation_id)?.name ?? "a chat");

                // Chats that did get it stay done, so a retry does not send it to them twice.
                (result.data?.results ?? []).filter((r) => r.ok).forEach((r) => {
                    chosen.delete(r.conversation_id);

                    const box = boxes.get(r.conversation_id);

                    if (box) {
                        box.checked = false;
                        box.dataset.sent = "true";
                        box.disabled = true;
                        box.parentElement.append(document.createTextNode(" (sent)"));
                    }
                });

                note.textContent = `Couldn't forward to: ${names.join(", ")}`;
                sync();
                send.disabled = chosen.size === 0;

                return;
            }

            $("#messages-forward [data-dialog-close]")?.click();
            toast("Forwarded");
            listPoller.nudge();
        };

        $("[data-role=forward-open]")?.click();
    }

    async function deleteEveryone(id) {
        if (!window.confirm("Delete this message for everyone? This can't be undone.")) {
            return;
        }

        if (!refused(await api.deleteEveryone(state.openId, id))) {
            await refreshThread();
        }
    }

    function openMessageMenu(node, at) {
        const m = node ? messageById(Number(node.dataset.id)) : null;
        const c = current();

        if (!m || !c) {
            return;
        }

        const handlers = {
            react: (emoji) => react(m, emoji),
            reply: () => setReply(m),
            copy: () => copyText(m.body ?? ""),
            forward: () => openForward(m),
            pin: async () => { if (!refused(await api.pinMessage(state.openId, m.id, true))) { await refreshThread(); } },
            unpin: async () => { if (!refused(await api.pinMessage(state.openId, m.id, false))) { await refreshThread(); } },
            bump: async () => { if (!refused(await api.bump(state.openId, m.id))) { await refreshThread(); readUpTo(); } },
            "delete-me": () => hideMessage(m.id),
            "delete-everyone": () => deleteEveryone(m.id),
            report: () => openReport(m.id),
            remove: async () => {
                if (!window.confirm("Remove this message for everyone in this chat?")) {
                    return;
                }

                if (!refused(await api.remove(state.openId, m.id))) {
                    m.removed = true;
                    m.body = null;
                    renderMessages();
                }
            },
        };

        menu.open(messageMenuItems(m, c, handlers), at);
    }

    bindMenuTriggers(messagesEl, { selector: "[data-id]", onOpen: openMessageMenu });

    $("[data-role=reply-cancel]")?.addEventListener("click", () => setReply(null));
    $("[data-role=pinbar-next]")?.addEventListener("click", () => {
        state.pinIndex = stepIndex(state.pinIndex, state.pinned.length, 1);
        renderPinbar();
    });
    $("[data-role=pinbar-text]")?.addEventListener("click", () => {
        const pin = state.pinned[state.pinIndex];

        if (pin) {
            scrollToMessage(pin.id);
        }
    });

    // ---- the list controls ---------------------------------------------

    // ---- the chat menu -------------------------------------------------

    const menu = createMenu(document);

    async function chatAction(c, action) {
        showListNote("");

        // A list answer already on its way was asked for before this click; it would put the old state back.
        if (action === "unread" || action === "read") {
            listGuard.next();
        }

        // Marking unread shows at once (the dot and the badge); the server answer below confirms or puts it back.
        if (action === "unread" && !c.marked_unread && !(c.unread > 0)) {
            c.marked_unread = true;

            if (!c.muted) {
                updateBadge((state.badge ?? 0) + 1);
            }

            renderList();
        }

        // Marking read clears the dot or count at once.
        if (action === "read" && (c.marked_unread || c.unread > 0)) {
            if (!c.muted) {
                updateBadge(Math.max(0, (state.badge ?? 0) - Math.max(c.unread > 0 ? c.unread : 0, c.marked_unread ? 1 : 0)));
            }

            c.unread = 0;
            c.new_messages = 0;
            c.marked_unread = false;
            renderList();
        }

        const result = await api.chatAction(c.id, action);

        if (result.status !== 200 && (action === "unread" || action === "read")) {
            await loadList();
        }

        if (result.status === 409 && result.data?.error === "limit") {
            showListNote(`You can pin up to ${result.data.max ?? 5} chats.`);
        } else if (result.status === 403) {
            showListNote("You can't do that in this chat.");
        } else if (result.status !== 200) {
            showListNote("That didn't work. Try again.");
        } else {
            if (action === "delete" && state.openId === c.id) {
                closeThread();
            }
            await loadList();
        }
    }

    function openChatMenu(row, at) {
        const c = state.conversations.find((x) => x.id === Number(row.dataset.id));

        if (!c) {
            return;
        }

        const act = (name) => () => chatAction(c, name);
        const handlers = {
            pin: act("pin"),
            unpin: act("unpin"),
            unread: act("unread"),
            read: act("read"),
            mute: act("mute"),
            unmute: act("unmute"),
            archive: act("archive"),
            unarchive: act("unarchive"),
            delete: act("delete"),
            // The existing block flow: open the chat and ask for the second click there.
            block: async () => {
                await open(c.id);

                if (state.openId === c.id && current() !== null) {
                    state.confirmBlock = c.id;
                    applyConversation(current());
                }
            },
        };

        menu.open(chatMenuItems(c, handlers), at);
    }

    bindMenuTriggers(rowsEl, { selector: "button[data-id]", onOpen: openChatMenu });

    const switchView = (archived) => {
        state.archived = archived;
        state.switching = true;
        state.conversations = [];
        rowsEl.replaceChildren();
        showListNote("");
        closeThread();
        listState("loading");
        loadList();
    };

    archivedOpenEl?.addEventListener("click", () => switchView(true));
    archivedBackEl?.addEventListener("click", () => switchView(false));

    rowsEl.addEventListener("click", (event) => {
        const row = event.target.closest("button[data-id]");

        if (row) {
            open(Number(row.dataset.id));
        }
    });

    root.querySelectorAll("[data-chip]").forEach((chip) => chip.addEventListener("click", () => {
        state.filter = chip.dataset.chip;
        root.querySelectorAll("[data-chip]").forEach((other) => {
            const on = other === chip;

            other.setAttribute("aria-pressed", String(on));
            other.classList.remove(...(on ? CHIP_OFF : CHIP_ON));
            other.classList.add(...(on ? CHIP_ON : CHIP_OFF));
        });
        showCached();
        loadList();
    }));

    const unreadToggle = $("[data-role=unread-toggle]");

    unreadToggle.addEventListener("click", () => {
        state.unreadOnly = !state.unreadOnly;
        unreadToggle.setAttribute("aria-pressed", String(state.unreadOnly));
        unreadToggle.classList.toggle("bg-brand", state.unreadOnly);
        unreadToggle.classList.toggle("text-white", state.unreadOnly);
        showCached();
        loadList();
    });

    $("[data-role=search]").addEventListener("input", (event) => {
        state.search = event.target.value;
        renderList();
    });

    $("[data-role=retry-list]").addEventListener("click", () => { listState("loading"); loadList(); });
    $("[data-role=retry-thread]").addEventListener("click", () => open(state.openId));
    $("[data-role=back]").addEventListener("click", closeThread);
    olderEl.addEventListener("click", loadOlder);

    // ---- go ------------------------------------------------------------

    // When the tab comes back, both the list and the open thread catch up at once.
    const unwatch = watchVisibility({ nudge: () => { listPoller.nudge(); state.poller?.nudge(); } });

    const ready = loadList().then(() => {
        listPoller.start();

        if (root.dataset.open) {
            return open(Number(root.dataset.open));
        }

        return undefined;
    });

    return {
        ready,
        destroy() {
            listPoller.stop();
            state.poller?.stop();
            unwatch();
        },
    };
}

const root = document.getElementById("messages-app");

if (root) {
    mountMessages(root, { api: createApi(root.dataset.api) });
}
