import { describe, expect, it, vi } from "vitest";

import { reactionChips } from "../../../resources/js/pages/messages/reactions.js";
import { messageNode } from "../../../resources/js/pages/messages/render.js";

const base = { id: 7, body: "hi", sender_name: "Ann", sender_role: "student" };

describe("reaction chips", () => {

    it("shows counts and highlights the viewer's own", () => {

        const onToggle = vi.fn();
        const row = reactionChips({ ...base, reactions: [{ emoji: "👍", count: 2, mine: true }, { emoji: "❤️", count: 1, mine: false }] }, { onToggle });
        const [a, b] = row.querySelectorAll("button");

        expect(a.textContent).toBe("👍 2");
        expect(a.getAttribute("aria-pressed")).toBe("true");
        expect(a.getAttribute("aria-label")).toBe("2 reactions, 👍. You reacted");
        expect(b.getAttribute("aria-pressed")).toBe("false");
        expect(b.getAttribute("aria-label")).toBe("1 reaction, ❤️.");

        a.click();
        expect(onToggle).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }), "👍");

    });

    it("renders nothing for an empty list", () => {

        expect(reactionChips({ ...base, reactions: [] }, {})).toBeNull();
        expect(reactionChips(base, {})).toBeNull();

    });

});

describe("message node extras", () => {

    it("keeps quote text inert", () => {

        const node = messageNode({ ...base, reply: { id: 3, sender_name: "Bo", text: "<img src=x onerror=alert(1)>" } }, { mine: false });
        const quote = node.querySelector("[data-reply-to='3']");

        expect(quote).not.toBeNull();
        expect(node.querySelector("img")).toBeNull();
        expect(quote.textContent).toContain("<img src=x onerror=alert(1)>");

    });

    it("shows a tombstone for a message deleted for everyone", () => {

        const node = messageNode({ ...base, body: null, deleted_everyone: true }, { mine: true });

        expect(node.textContent).toBe("You deleted a message");

        const other = messageNode({ ...base, body: null, deleted_everyone: true }, { mine: false });

        expect(other.textContent).toBe("Ann deleted a message");

    });

    it("keeps Message removed for moderator removal", () => {

        expect(messageNode({ ...base, body: null, removed: true }, { mine: false }).textContent).toContain("Message removed");

    });

    it("shows Message removed in a quote with no text", () => {

        const node = messageNode({ ...base, reply: { id: 3, sender_name: "Bo", text: "" } }, { mine: false });

        expect(node.querySelector("[data-reply-to]").textContent).toContain("Message removed");

    });

    it("calls onShowWho on contextmenu and prevents default", () => {

        const onShowWho = vi.fn();
        const msg = { ...base, reactions: [{ emoji: "👍", count: 1, mine: false }] };
        const chip = reactionChips(msg, { onShowWho }).querySelector("button");
        const event = new MouseEvent("contextmenu", { bubbles: true, cancelable: true });

        chip.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(onShowWho).toHaveBeenCalledWith(msg, "👍");

    });

    it("highlights only the viewer's chip", () => {

        const [a, b] = reactionChips({ ...base, reactions: [{ emoji: "👍", count: 1, mine: true }, { emoji: "❤️", count: 1, mine: false }] }, {}).querySelectorAll("button");

        expect(a.getAttribute("aria-pressed")).toBe("true");
        expect(a.className).toContain("bg-brand-tint");
        expect(b.getAttribute("aria-pressed")).toBe("false");
        expect(b.className).not.toContain("bg-brand-tint");

    });

    it("labels forwarded and bumped messages and is keyboard reachable", () => {

        const node = messageNode({ ...base, forwarded: true, bumped: true, reactions: [{ emoji: "👍", count: 1, mine: false }] }, { mine: false });

        expect(node.textContent).toContain("Forwarded");
        expect(node.textContent).toContain("Bump");
        expect(node.getAttribute("tabindex")).toBe("0");
        expect(node.querySelector("button[aria-pressed]")).not.toBeNull();

    });

});
