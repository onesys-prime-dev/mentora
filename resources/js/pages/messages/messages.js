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
import { conversationRow, dayLabel, messageNode } from "./render.js";

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
    const pickerNote = $("[data-role=picker-state]");

    const listGuard = createGuard();
    const threadGuard = createGuard();

    const state = {
        filter: "all",
        unreadOnly: false,
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
    };

    // ---- small helpers -------------------------------------------------

    const only = (scope, name) => scope.querySelectorAll("[data-state]").forEach((el) => { el.hidden = el.dataset.state !== name; });
    const listState = (name) => only($("[data-role=list]"), name);
    const threadState = (name) => only($("[data-role=scroller]"), name);

    function updateBadge(count) {
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
    const current = () => state.conversations.find((c) => c.id === state.openId) ?? null;

    // ---- the list ------------------------------------------------------

    function renderList() {
        const needle = state.search.trim().toLowerCase();
        const shown = state.conversations.filter((c) => needle === "" || [c.name, c.subtitle].some((t) => (t ?? "").toLowerCase().includes(needle)));

        rowsEl.replaceChildren(...shown.map((c) => conversationRow(c, { selected: c.id === state.openId })));

        if (state.conversations.length === 0 && state.filter === "all" && !state.unreadOnly) {
            listState("empty");
        } else {
            listState(shown.length === 0 ? "no-match" : null);
        }
    }

    async function loadList() {
        const token = listGuard.next();
        const { status, data } = await api.conversations({ filter: state.filter, unread: state.unreadOnly });

        if (!listGuard.isCurrent(token)) {
            return;
        }

        if (status !== 200 || !data) {
            if (!state.listLoaded) {
                listState("error");
            }

            return;
        }

        state.listLoaded = true;
        state.conversations = data.conversations;
        updateBadge(data.unread ?? 0);
        renderList();

        if (state.openId !== null && current() !== null) {
            applyConversation(current());
        }
    }

    const listPoller = createPoller({ task: async () => { await loadList(); return "ok"; }, visible, fast: 15000 });

    // ---- the thread ----------------------------------------------------

    function applyConversation(c) {
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

    function messageRow(item) {
        const row = document.createElement("div");

        row.className = `group/msg flex flex-col ${item.mine ? "items-end" : "items-start"}`;
        row.append(messageNode(item, { mine: item.mine }));

        const note = document.createElement("div");

        note.className = "mt-0.5 flex gap-2 text-[12px] text-[#646464]";

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
            hide.className = "opacity-0 hover:underline focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 group-hover/msg:opacity-100";
            hide.textContent = "Delete for me";
            note.append(hide);

            if (!item.mine) {
                const report = document.createElement("button");

                report.type = "button";
                report.dataset.action = "report";
                report.dataset.messageId = String(item.id);
                report.className = "opacity-0 hover:underline focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 group-hover/msg:opacity-100";
                report.textContent = "Report";
                note.append(report);
            }

            // A moderator of this chat can blank the message for everyone; it takes a second click to be sure.
            if (current()?.can_moderate) {
                const confirming = state.confirmRemove === item.id;
                const remove = document.createElement("button");

                remove.type = "button";
                remove.dataset.action = "remove";
                remove.dataset.messageId = String(item.id);
                remove.className = `${confirming ? "font-semibold text-red-700" : "opacity-0 group-hover/msg:opacity-100"} hover:underline focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50`;
                remove.textContent = confirming ? "Click again to remove" : "Remove message";
                note.append(remove);
            }
        }

        if (note.childNodes.length > 0) {
            row.append(note);
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

        return "ok";
    }

    async function open(id) {
        state.poller?.stop();

        const token = threadGuard.next();

        state.openId = id;
        state.token = token;
        state.cursor = 0;
        state.loadingOlder = false;
        state.confirmRemove = null;
        state.confirmBlock = null;
        showSafetyNote("");
        state.items = [];
        state.hasMore = false;
        state.sending = null;
        state.composer = createComposer({
            send: (text, uuid) => api.send(id, text, uuid),
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
        }

        renderMessages();
    }

    function submit(text, item = null) {
        if (state.openId === null || !canSend(text, state.composer.state === "sending")) {
            return;
        }

        // The id stays with the message, so Retry of an older one can never post it twice.
        const draft = item ?? { id: `local-${++state.localSeq}`, uuid: crypto.randomUUID(), mine: true, body: text.trim(), raw: text, removed: false, created_at: stamp(now()) };

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
        const button = event.target.closest("[data-action]");

        if (!button) {
            return;
        }

        if (button.dataset.action === "retry") {
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
            const { status } = await api.hide(state.openId, id);

            if (status === 200) {
                state.items = state.items.filter((i) => i.id !== id);
                renderMessages();
            }
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

    $("[data-role=prefs-open]")?.addEventListener("click", async () => {
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

    // ---- the list controls ---------------------------------------------

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
        loadList();
    }));

    const unreadToggle = $("[data-role=unread-toggle]");

    unreadToggle.addEventListener("click", () => {
        state.unreadOnly = !state.unreadOnly;
        unreadToggle.setAttribute("aria-pressed", String(state.unreadOnly));
        unreadToggle.classList.toggle("bg-brand", state.unreadOnly);
        unreadToggle.classList.toggle("text-white", state.unreadOnly);
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
