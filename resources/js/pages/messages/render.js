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

// The server writes times as wall-clock text in its own timezone (UTC). Told its offset in minutes, the page
// reads each stamp as that instant and shows it in the viewer's own time; with none, a stamp is taken as local.
let serverOffsetMinutes = null;

export function setServerOffset(minutes) {
    serverOffsetMinutes = Number.isFinite(minutes) ? minutes : null;
}

/** A moment as the server would write it ("2026-11-05 02:26:00"), for a message the page itself stamps. */
export function serverStamp(date) {
    const pad = (n) => String(n).padStart(2, "0");

    if (serverOffsetMinutes === null) {
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    }

    const shifted = new Date(date.getTime() + serverOffsetMinutes * 60000);

    return `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}-${pad(shifted.getUTCDate())} ${pad(shifted.getUTCHours())}:${pad(shifted.getUTCMinutes())}:${pad(shifted.getUTCSeconds())}`;
}

function parseTime(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(iso ?? ""));

    if (!m) {
        return null;
    }

    const [year, month, day, hour, minute] = [Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5])];

    return serverOffsetMinutes === null
        ? new Date(year, month, day, hour, minute)
        : new Date(Date.UTC(year, month, day, hour, minute) - serverOffsetMinutes * 60000);
}

const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

/** The clock time on a 12-hour clock with AM or PM: "8:24 AM", "12:05 PM". Empty when the stamp is unreadable. */
export function clockTime(iso) {
    const at = parseTime(iso);

    if (at === null) {
        return "";
    }

    const hours = at.getHours();

    return `${hours % 12 === 0 ? 12 : hours % 12}:${String(at.getMinutes()).padStart(2, "0")} ${hours < 12 ? "AM" : "PM"}`;
}

/**
 * The time on a chat row, the way Messenger shows it: "now", minutes ("5m"), hours ("3h"), days ("2d"), weeks
 * ("3w"), and a date once it is a year old.
 */
export function formatTime(iso, now) {
    const at = parseTime(iso);

    if (at === null) {
        return "";
    }

    const minutes = Math.floor((now - at) / 60000);

    if (minutes < 1) {
        return "now";
    }

    if (minutes < 60) {
        return `${minutes}m`;
    }

    if (minutes < 1440) {
        return `${Math.floor(minutes / 60)}h`;
    }

    const days = Math.floor(minutes / 1440);

    if (days < 7) {
        return `${days}d`;
    }

    if (days < 365) {
        return `${Math.floor(days / 7)}w`;
    }

    return `${at.getDate()} ${MONTHS[at.getMonth()]} ${at.getFullYear()}`;
}

/**
 * The time shown beside a message when the pointer is over it: just the clock today, the weekday and clock
 * inside the last week, and the date and clock after that ("30 Oct, 8:24 AM", with the year when it is another year's).
 */
export function hoverTime(iso, now) {
    const at = parseTime(iso);

    if (at === null) {
        return "";
    }

    const clock = clockTime(iso);
    const days = Math.round((startOfDay(now) - startOfDay(at)) / 86400000);

    if (days <= 0) {
        return clock;
    }

    if (days < 7) {
        return `${WEEKDAYS[at.getDay()]} ${clock}`;
    }

    const date = `${at.getDate()} ${MONTHS[at.getMonth()]}${at.getFullYear() === now.getFullYear() ? "" : ` ${at.getFullYear()}`}`;

    return `${date}, ${clock}`;
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

/**
 * The words on a separator above a message: the same time the hover shows, with Today and Yesterday spelled out
 * ("Today 9:00 AM", "Yesterday 9:00 AM", then "Mon 9:00 AM" inside a week and "30 Oct, 9:00 AM" after).
 */
export function separatorTime(iso, now) {
    const at = parseTime(iso);

    if (at === null) {
        return "";
    }

    const days = Math.round((startOfDay(now) - startOfDay(at)) / 86400000);

    if (days <= 0) {
        return `Today ${clockTime(iso)}`;
    }

    return days === 1 ? `Yesterday ${clockTime(iso)}` : hoverTime(iso, now);
}

/** Whole minutes from one stamp to a later one; 0 when either is unreadable. */
export function minutesBetween(earlier, later) {
    const a = parseTime(earlier);
    const b = parseTime(later);

    return a === null || b === null ? 0 : Math.max(0, Math.floor((b - a) / 60000));
}

export function messageNode(message, { mine, onToggle, onShowWho } = {}) {
    const node = el("div", `max-w-[70%] min-w-[4.5rem] break-words rounded-2xl border px-4 py-2.5 text-base leading-snug ${mine ? "self-end border-brand bg-brand text-white" : "border-gray-200 bg-white text-gray-800"}`);

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
        // The pill hangs off the bubble's bottom edge into the gap between messages; nothing moves when it appears.
        node.classList.add("relative");
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

    // A chat only marked unread is a dot; the number is for messages that really have not been read.
    const count = conversation.new_messages ?? conversation.unread;

    if (count > 0) {
        const pill = el("span", "mt-0.5 inline-block min-w-[16px] rounded-full bg-brand px-1 text-center text-[12px] font-semibold text-white", String(count));

        pill.dataset.unread = "";
        meta.append(pill, el("span", "sr-only", " unread"));
    }

    if (conversation.marked_unread && !(count > 0)) {
        const dot = el("span", "mt-0.5 inline-block h-2 w-2 rounded-full bg-brand");

        dot.dataset.markedUnread = "";
        meta.append(dot, el("span", "sr-only", " marked unread"));
    }

    if (conversation.archived) {
        row.className += " opacity-70";
    }

    row.append(avatar, text, meta);

    return row;
}
