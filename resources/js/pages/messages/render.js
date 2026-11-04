/*
|--------------------------------------------------------------------------
| Messages — rendering
|--------------------------------------------------------------------------
| Everything the Messages page puts on screen from server data. Text is set
| with textContent; the only innerHTML is the output of linkify(), which
| escapes first and then adds links for http(s) addresses and nothing else.
|--------------------------------------------------------------------------
*/

import { reactionChips } from "./reactions.js";

const ESCAPES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" };

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

const ROLE_LABELS = { instructor: "Instructor", chairperson: "Chairperson", dean: "Dean", parent: "Parent", institutional_admin: "Administrator" };

// An address ends at whitespace or at an escaped quote or bracket.
const URL_PATTERN = /https?:\/\/(?:(?!&quot;|&#39;|&lt;|&gt;)\S)+/g;

const PIN = "<svg aria-hidden=\"true\" viewBox=\"0 0 20 20\" class=\"mr-1 inline h-3 w-3 text-brand\" fill=\"currentColor\"><path d=\"M11 2l7 7-2 1-3-1-3 3 1 4-2 1-3-3-4 4-1-1 4-4-3-3 1-2 4 1 3-3-1-3z\"/></svg>";

const MUTED = "<svg aria-hidden=\"true\" data-muted viewBox=\"0 0 20 20\" class=\"ml-1 inline h-3 w-3 text-gray-400\" fill=\"currentColor\"><path d=\"M10 3a5 5 0 00-5 5v3l-1.5 3h13L15 11V8a5 5 0 00-5-5zm-2 12a2 2 0 004 0H8zM3 2l15 15-1 1L2 3z\"/></svg>";

export function escapeHtml(text) {
    return String(text ?? "").replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

export function linkify(text) {
    const html = escapeHtml(text).replace(URL_PATTERN, (match) => {
        let url = match;
        let tail = "";

        while (/[.,!?:)]$/.test(url)) {
            tail = url.slice(-1) + tail;
            url = url.slice(0, -1);
        }

        return `<a href="${url}" target="_blank" rel="noopener noreferrer nofollow" class="underline">${url}</a>${tail}`;
    });

    return html.replace(/\r?\n/g, "<br>");
}

function el(tag, className, text) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function parseTime(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(iso ?? ""));

    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5])) : null;
}

const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

export function formatTime(iso, now) {
    const at = parseTime(iso);

    if (at === null) {
        return "";
    }

    const days = Math.round((startOfDay(now) - startOfDay(at)) / 86400000);

    if (days <= 0) {
        return `${String(at.getHours()).padStart(2, "0")}:${String(at.getMinutes()).padStart(2, "0")}`;
    }

    if (days === 1) {
        return "Yesterday";
    }

    return days < 7 ? WEEKDAYS[at.getDay()] : `${at.getDate()} ${MONTHS[at.getMonth()]}`;
}

/** The label of the separator above a day's messages: Today, Yesterday, "30 Oct", or "30 Dec 2025" in another year. */
export function dayLabel(iso, now) {
    const at = parseTime(iso);

    if (at === null) {
        return "";
    }

    const days = Math.round((startOfDay(now) - startOfDay(at)) / 86400000);

    if (days <= 0) {
        return "Today";
    }

    if (days === 1) {
        return "Yesterday";
    }

    const label = `${at.getDate()} ${MONTHS[at.getMonth()]}`;

    return at.getFullYear() === now.getFullYear() ? label : `${label} ${at.getFullYear()}`;
}

export function messageNode(message, { mine, onToggle, onShowWho } = {}) {
    const node = el("div", `max-w-[70%] break-words rounded-2xl border px-3 py-1.5 text-[15px] ${mine ? "self-end border-brand bg-brand text-white" : "border-gray-200 bg-white text-gray-800"}`);

    node.dataset.id = String(message.id);
    node.dataset.mine = mine ? "true" : "false";
    node.tabIndex = 0;

    if (!mine && message.sender_name && !(message.deleted_everyone && !message.removed)) {
        const role = ROLE_LABELS[message.sender_role];
        const label = el("small", `mb-0.5 block text-[12px] font-semibold ${message.sender_role === "instructor" ? "text-brand" : "text-gray-500"}`, role ? `${message.sender_name} · ${role}` : message.sender_name);

        node.append(label);
    }

    if (message.removed || message.deleted_everyone) {
        node.append(el("span", "italic text-gray-500", message.removed ? "Message removed" : mine ? "You deleted a message" : `${message.sender_name ?? "Someone"} deleted a message`));

        return node;
    }

    if (message.forwarded) {
        node.append(el("small", "mb-0.5 block text-[12px] italic text-gray-500", "Forwarded"));
    }

    if (message.bumped) {
        node.append(el("small", "mb-0.5 block text-[12px] italic text-gray-500", "Bump"));
    }

    if (message.reply) {
        const quote = el("blockquote", "mb-1 border-l-2 border-gray-400 pl-2 text-[13px] text-gray-500");

        quote.dataset.replyTo = String(message.reply.id);
        quote.append(el("strong", "block text-[12px] font-semibold", message.reply.sender_name ?? ""), el("span", "block truncate", message.reply.text || "Message removed"));
        node.append(quote);
    }

    const body = el("div", "whitespace-normal");

    body.innerHTML = linkify(message.body ?? "");
    node.append(body);

    const chips = reactionChips(message, { onToggle, onShowWho });

    if (chips) {
        node.append(chips);
    }

    return node;
}

export function conversationRow(conversation, { selected }) {
    const row = el("button", `flex w-full items-center gap-3 border-l-[3px] px-4 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 ${selected ? "border-brand bg-brand-tint" : "border-transparent hover:bg-gray-50"}`);

    row.type = "button";
    row.dataset.id = String(conversation.id);

    if (selected) {
        row.setAttribute("aria-current", "true");
    }

    const shape = conversation.type === "course" ? "rounded-lg bg-brand text-white" : conversation.type === "group" ? "rounded-lg bg-accent text-white" : "rounded-full bg-[#E0E5F2] text-brand-dark";
    const avatar = el("span", `grid h-[34px] w-[34px] flex-none place-items-center text-[13px] font-semibold ${shape}`, conversation.initials ?? "");

    avatar.dataset.avatar = "";
    avatar.setAttribute("aria-hidden", "true");

    const text = el("span", "min-w-0 flex-1");
    const name = el("span", "block truncate text-[13px] font-semibold text-gray-900");

    if (conversation.pinned || conversation.type === "course") {
        name.insertAdjacentHTML("beforeend", PIN);
    }

    name.append(document.createTextNode(conversation.name ?? ""));

    if (conversation.muted) {
        name.insertAdjacentHTML("beforeend", MUTED);
    }
    text.append(name);

    if (conversation.subtitle) {
        text.append(el("span", "block truncate text-[12px] font-medium text-brand", conversation.subtitle));
    }

    text.append(el("span", "block truncate text-[13px] text-gray-500", conversation.preview ?? ""));

    const meta = el("span", "flex-none text-right text-[12px] text-gray-500");

    meta.append(el("span", "block", formatTime(conversation.last_message_at, new Date())));

    if (conversation.unread > 0) {
        const pill = el("span", "mt-0.5 inline-block min-w-[16px] rounded-full bg-brand px-1 text-center text-[12px] font-semibold text-white", String(conversation.unread));

        pill.dataset.unread = "";
        meta.append(pill, el("span", "sr-only", " unread"));
    }

    if (conversation.marked_unread && !(conversation.unread > 0)) {
        const dot = el("span", "mt-0.5 inline-block h-2 w-2 rounded-full bg-brand");

        dot.dataset.markedUnread = "";
        meta.append(dot, el("span", "sr-only", " marked unread"));
    }

    // Not a nested <button>: a span with the button role, opened by click or Enter/Space (messages.js).
    const more = el("span", "ml-1 flex-none cursor-pointer rounded-full px-1.5 text-[15px] leading-none text-gray-500 hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100 max-md:opacity-100", "⋯");

    more.dataset.role = "row-menu";
    more.setAttribute("role", "button");
    more.setAttribute("tabindex", "0");
    more.setAttribute("aria-label", "Chat actions");

    row.className += " group/row";
    if (conversation.archived) {
        row.className += " opacity-70";
    }

    row.append(avatar, text, meta, more);

    return row;
}
