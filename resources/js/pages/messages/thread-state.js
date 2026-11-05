/*
|--------------------------------------------------------------------------
| Messages — thread state helpers
|--------------------------------------------------------------------------
| Pure functions the page uses to update an open thread without redrawing
| from scratch: the optimistic reaction toggle, merging changed messages by
| id, and paging through the pinned messages.
|--------------------------------------------------------------------------
*/

/**
 * The reactions after the viewer picks `emoji` (null removes their reaction). A person has at most one reaction
 * per message, so picking another emoji replaces the old one.
 *
 * @param {Array<{emoji: string, count: number, mine?: boolean}>} reactions
 * @param {string|null} emoji
 * @returns {Array<{emoji: string, count: number, mine: boolean}>}
 */
export function toggleReaction(reactions, emoji) {
    const list = (reactions ?? []).map((r) => ({ emoji: r.emoji, count: Number(r.count) || 0, mine: Boolean(r.mine) }));
    const previous = list.find((r) => r.mine)?.emoji ?? null;
    const next = emoji === previous ? null : emoji;

    const out = list
        .map((r) => (r.emoji === previous ? { ...r, count: r.count - 1, mine: false } : r))
        .filter((r) => r.count > 0);

    if (next !== null) {
        const existing = out.find((r) => r.emoji === next);

        if (existing) {
            existing.count += 1;
            existing.mine = true;
        } else {
            out.push({ emoji: next, count: 1, mine: true });
        }
    }

    return out;
}

/**
 * Replaces messages by id and adds new ones; delivered messages stay in id order and unsent drafts stay last.
 *
 * @param {Array<{id: number|string}>} items
 * @param {Array<{id: number}>} incoming
 */
export function mergeById(items, incoming) {
    const byId = new Map(items.filter((i) => typeof i.id === "number").map((i) => [i.id, i]));

    incoming.forEach((m) => byId.set(m.id, m));

    return [...[...byId.values()].sort((a, b) => a.id - b.id), ...items.filter((i) => typeof i.id !== "number")];
}

/** The next index when paging through `count` pinned messages, wrapping around. */
export function stepIndex(index, count, direction = 1) {
    if (count <= 0) {
        return 0;
    }

    return (((index + direction) % count) + count) % count;
}
