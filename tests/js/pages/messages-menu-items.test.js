import { describe, expect, it, vi } from "vitest";

import { PALETTE, QUICK, chatMenuItems, messageMenuItems } from "../../../resources/js/pages/messages/menu-items.js";

const ids = (items) => items.map((i) => i.id);
const labels = (items) => items.map((i) => i.label);

const chat = (over = {}) => ({
    pinned: false, archived: false, muted: false, can_pin_chat: true, can_delete: true, can_block: true, type: "direct", ...over,
});
const msg = (over = {}) => ({
    can_react: true, can_reply: true, can_forward: true, can_pin: true, can_bump: true,
    can_delete_everyone: true, pinned: false, mine: true, removed: false, deleted_everyone: false, reactions: [], ...over,
});

describe("palette", () => {
    it("holds the 24 server emojis in order, quick row first", () => {
        expect(PALETTE).toHaveLength(24);
        expect(QUICK).toEqual(["👍", "❤️", "😂", "😮", "😢"]);
        expect(PALETTE.slice(0, 5)).toEqual(QUICK);
        expect(PALETTE[1]).toBe("❤️");
        expect(PALETTE[23]).toBe("⭐");
    });
});

describe("chatMenuItems", () => {
    it("lists everything for a direct chat", () => {
        const items = chatMenuItems(chat(), {});
        expect(labels(items)).toEqual([
            "Pin to top", "Mark as unread", "Mute notifications", "Archive chat", "Block", "Delete chat (for me)",
        ]);
        expect(ids(items)).toEqual(["pin", "unread", "mute", "archive", "block", "delete"]);
        expect(items.findIndex((i) => i.separator)).toBe(4);
        expect(items.filter((i) => i.separator)).toHaveLength(1);
    });

    it("flips to the undo labels", () => {
        const items = chatMenuItems(chat({ pinned: true, muted: true, archived: true }), {});
        expect(labels(items).slice(0, 4)).toEqual(["Unpin", "Mark as unread", "Unmute", "Unarchive"]);
        expect(ids(items).slice(0, 4)).toEqual(["unpin", "unread", "unmute", "unarchive"]);
    });

    it("hides items the server does not allow (course chat)", () => {
        const items = chatMenuItems(chat({ type: "course", can_pin_chat: false, can_delete: false, can_block: false }), {});
        expect(ids(items)).toEqual(["unread", "mute", "archive"]);
        expect(items.some((i) => i.separator)).toBe(false);
    });

    it("handles group chats with only some flags", () => {
        const items = chatMenuItems(chat({ type: "group", can_block: false }), {});
        expect(ids(items)).toEqual(["pin", "unread", "mute", "archive", "delete"]);
        expect(items.at(-1).separator).toBe(true);
        expect(items.at(-1).danger).toBe(true);
    });

    it("calls the matching handler and ignores missing ones", () => {
        const pin = vi.fn();
        const items = chatMenuItems(chat(), { pin });
        items[0].onSelect();
        expect(pin).toHaveBeenCalledTimes(1);
        expect(() => items[1].onSelect()).not.toThrow();
    });
});

describe("messageMenuItems", () => {
    const conv = { can_moderate: false };

    it("lists a full own message with separators between groups", () => {
        const items = messageMenuItems(msg(), conv, {});
        expect(ids(items)).toEqual([
            "reactions", "reply", "copy", "forward", "pin", "bump", "delete-me", "delete-everyone",
        ]);
        expect(labels(items).slice(1)).toEqual([
            "Reply", "Copy", "Forward", "Pin message", "Bump (resend as a reminder)", "Delete for me", "Delete for everyone",
        ]);
        expect(items.map((i) => Boolean(i.separator))).toEqual([false, true, false, false, false, false, true, false]);
        expect(items[0]).toMatchObject({ type: "reactions", emojis: QUICK, selected: null });
    });

    it("offers Report on other people's messages, not own", () => {
        expect(ids(messageMenuItems(msg({ mine: false }), conv, {}))).toContain("report");
        expect(ids(messageMenuItems(msg(), conv, {}))).not.toContain("report");
    });

    it("shows Unpin message and the selected reaction", () => {
        const reactions = [{ emoji: "😂", count: 2, mine: true }, { emoji: "👍", count: 1, mine: false }];
        const items = messageMenuItems(msg({ pinned: true, reactions }), conv, {});
        expect(items[0].selected).toBe("😂");
        expect(labels(items)).toContain("Unpin message");
        expect(ids(items)).toContain("unpin");
    });

    it("shows Remove instead of Report on others' messages for moderators", () => {
        const items = messageMenuItems(msg({ mine: false }), { can_moderate: true }, {});
        expect(ids(items).at(-1)).toBe("remove");
        expect(items.at(-1).label).toBe("Remove");
        expect(ids(items)).not.toContain("report");
    });

    it("shows Report and no Remove on others' messages for non-moderators", () => {
        const items = messageMenuItems(msg({ mine: false }), { can_moderate: false }, {});
        expect(ids(items)).toContain("report");
        expect(ids(items)).not.toContain("remove");
    });

    it("shows neither Report nor Remove on own messages, even for moderators", () => {
        const items = messageMenuItems(msg({ mine: true }), { can_moderate: true }, {});
        expect(ids(items)).not.toContain("report");
        expect(ids(items)).not.toContain("remove");
    });

    it("hides Block alone when can_block is false", () => {
        expect(ids(chatMenuItems(chat({ can_block: false }), {}))).toEqual(["pin", "unread", "mute", "archive", "delete"]);
    });

    it("hides Pin/Unpin alone when can_pin_chat is false", () => {
        expect(ids(chatMenuItems(chat({ can_pin_chat: false }), {}))).toEqual(["unread", "mute", "archive", "block", "delete"]);
        expect(ids(chatMenuItems(chat({ can_pin_chat: false, pinned: true }), {}))).not.toContain("unpin");
    });

    it.each([
        ["can_react", "reactions"],
        ["can_reply", "reply"],
        ["can_forward", "forward"],
        ["can_pin", "pin"],
        ["can_bump", "bump"],
        ["can_delete_everyone", "delete-everyone"],
    ])("hides the item when %s is false", (flag, id) => {
        expect(ids(messageMenuItems(msg({ [flag]: false }), conv, {}))).not.toContain(id);
    });

    it("drops the separator when there is no reactions row", () => {
        const items = messageMenuItems(msg({ can_react: false }), conv, {});
        expect(items[0].id).toBe("reply");
        expect(items[0].separator).toBeUndefined();
    });

    it.each([["removed"], ["deleted_everyone"]])("offers only Delete for me on a %s tombstone", (flag) => {
        const items = messageMenuItems(msg({ [flag]: true }), { can_moderate: true }, {});
        expect(labels(items)).toEqual(["Delete for me"]);
    });

    it("wires handlers, including onPick", () => {
        const reply = vi.fn();
        const react = vi.fn();
        const items = messageMenuItems(msg(), conv, { reply, react });
        items.find((i) => i.id === "reply").onSelect();
        items[0].onPick("👍");
        expect(reply).toHaveBeenCalledTimes(1);
        expect(react).toHaveBeenCalledWith("👍");
        expect(() => items.find((i) => i.id === "copy").onSelect()).not.toThrow();
    });
});
