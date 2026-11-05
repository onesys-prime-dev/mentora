/*
|--------------------------------------------------------------------------
| Messages — menu items
|--------------------------------------------------------------------------
| Builds the entries for the chat and message context menus from the flags
| the server sends. Pure: no DOM. A `separator` item has a rule drawn above
| it. Each item's id is its data-action value; onSelect calls the handler of
| the same id (a missing handler does nothing). The reactions row calls
| handlers.react(emoji) through onPick.
|--------------------------------------------------------------------------
*/

export const PALETTE = [
    "👍", "❤️", "😂", "😮", "😢", "🙏", "👏", "🎉", "🔥", "💯", "✅", "❌",
    "😀", "😍", "🤔", "😅", "😭", "😡", "👀", "💪", "🙌", "👌", "🤝", "⭐",
];

export const QUICK = PALETTE.slice(0, 5);

/**
 * @param {Record<string, Function>} handlers callbacks keyed by action id
 * @param {string} id
 * @param {string} label
 * @param {{danger?: boolean}} [opts]
 */
function item(handlers, id, label, opts = {}) {
    const entry = { id, label, onSelect: (...args) => handlers?.[id]?.(...args) };

    if (opts.danger) {
        entry.danger = true;
    }

    return entry;
}

/** Joins groups of items, flagging the first item of every group after the first. */
function join(groups) {
    const out = [];

    groups
        .filter((group) => group.length > 0)
        .forEach((group, index) => {
            group.forEach((entry, i) => {
                out.push(index > 0 && i === 0 ? { ...entry, separator: true } : entry);
            });
        });

    return out;
}

/**
 * @param {object} conversation a conversation row from the server
 * @param {Record<string, Function>} handlers
 */
export function chatMenuItems(conversation, handlers = {}) {
    const c = conversation ?? {};
    const actions = [];
    const destructive = [];

    if (c.can_pin_chat) {
        actions.push(c.pinned ? item(handlers, "unpin", "Unpin") : item(handlers, "pin", "Pin to top"));
    }

    actions.push(c.marked_unread || c.unread > 0 ? item(handlers, "read", "Mark as read") : item(handlers, "unread", "Mark as unread"));
    actions.push(c.muted ? item(handlers, "unmute", "Unmute") : item(handlers, "mute", "Mute notifications"));
    actions.push(c.archived ? item(handlers, "unarchive", "Unarchive") : item(handlers, "archive", "Archive chat"));

    if (c.can_block) {
        destructive.push(item(handlers, "block", "Block", { danger: true }));
    }

    if (c.can_delete) {
        destructive.push(item(handlers, "delete", "Delete chat (for me)", { danger: true }));
    }

    return join([actions, destructive]);
}

/**
 * @param {object} message a message from the server
 * @param {object} conversation the conversation it sits in
 * @param {Record<string, Function>} handlers
 */
export function messageMenuItems(message, conversation, handlers = {}) {
    const m = message ?? {};

    if (m.removed || m.deleted_everyone) {
        return [item(handlers, "delete-me", "Delete for me", { danger: true })];
    }

    const groups = [];

    if (m.can_react) {
        const selected = (m.reactions ?? []).find((r) => r.mine)?.emoji ?? null;

        groups.push([
            {
                id: "reactions",
                type: "reactions",
                emojis: QUICK,
                selected,
                onPick: (...args) => handlers?.react?.(...args),
            },
        ]);
    }

    const actions = [];

    if (m.can_reply) {
        actions.push(item(handlers, "reply", "Reply"));
    }

    actions.push(item(handlers, "copy", "Copy"));

    if (m.can_forward) {
        actions.push(item(handlers, "forward", "Forward"));
    }

    if (m.can_pin) {
        actions.push(m.pinned ? item(handlers, "unpin", "Unpin message") : item(handlers, "pin", "Pin message"));
    }

    if (m.can_bump) {
        actions.push(item(handlers, "bump", "Bump"));
    }

    groups.push(actions);

    const destructive = [item(handlers, "delete-me", "Delete for me", { danger: true })];

    if (m.can_delete_everyone) {
        destructive.push(item(handlers, "delete-everyone", "Delete for everyone", { danger: true }));
    }

    // Someone else's message only.
    if (!m.mine) {
        destructive.push(item(handlers, "report", "Report", { danger: true }));
    }

    groups.push(destructive);

    return join(groups);
}
