import { describe, expect, it } from "vitest";

import { conversationRow, dayLabel, escapeHtml, formatTime, linkify, messageNode } from "../../../resources/js/pages/messages/render.js";

const fragment = (html) => new DOMParser().parseFromString(`<body>${html}</body>`, "text/html").body;

describe("escaping and linking message text", () => {

    it("escapes angle brackets, quotes and ampersands", () => {

        expect(escapeHtml("<script>\"a\" & 'b'")).toBe("&lt;script&gt;&quot;a&quot; &amp; &#39;b&#39;");

    });

    it("turns markup in a message into inert text", () => {

        const body = fragment(linkify("<img src=x onerror=alert(1)>"));

        expect(body.querySelector("img")).toBeNull();
        expect(body.textContent).toBe("<img src=x onerror=alert(1)>");

    });

    it("links only http and https addresses", () => {

        expect(fragment(linkify("javascript:alert(1)")).querySelector("a")).toBeNull();
        expect(fragment(linkify("data:text/html,x")).querySelector("a")).toBeNull();
        expect(fragment(linkify("ftp://files.example/x")).querySelector("a")).toBeNull();
        expect(fragment(linkify("see http://a.example/x")).querySelector("a").getAttribute("href")).toBe("http://a.example/x");

    });

    it("leaves trailing punctuation outside the link", () => {

        const link = fragment(linkify("see https://a.example/x.")).querySelector("a");

        expect(link.getAttribute("href")).toBe("https://a.example/x");
        expect(link.parentElement.textContent).toBe("see https://a.example/x.");

    });

    it("keeps a look-alike address as one literal link", () => {

        const links = fragment(linkify("https://a.example@evil.test/")).querySelectorAll("a");

        expect(links).toHaveLength(1);
        expect(links[0].getAttribute("href")).toBe("https://a.example@evil.test/");

    });

    it("opens every link safely in a new tab", () => {

        const links = fragment(linkify("https://a.example and https://b.example")).querySelectorAll("a");

        expect(links).toHaveLength(2);
        links.forEach((a) => {
            expect(a.getAttribute("rel")).toBe("noopener noreferrer nofollow");
            expect(a.getAttribute("target")).toBe("_blank");
        });

    });

    it("does not let a quote in the address break out of the href", () => {

        const body = fragment(linkify("https://a.example/\"onmouseover=\"alert(1)"));

        expect(body.querySelector("[onmouseover]")).toBeNull();

    });

    it("turns line breaks into <br>", () => {

        expect(fragment(linkify("a\nb")).querySelectorAll("br")).toHaveLength(1);

    });

});

describe("a message bubble", () => {

    const message = (over = {}) => ({ id: 1, sender_id: 2, sender_name: "Prof. Reyes", sender_role: "instructor", mine: false, body: "Quiz moved", removed: false, created_at: "2026-11-03 09:41:00", ...over });

    it("labels another person's message with their name and role", () => {

        const node = messageNode(message(), { mine: false });

        expect(node.textContent).toContain("Prof. Reyes · Instructor");
        expect(node.textContent).toContain("Quiz moved");

    });

    it("has no label on my own message and is marked as mine", () => {

        const node = messageNode(message({ mine: true }), { mine: true });

        expect(node.textContent).not.toContain("Prof. Reyes");
        expect(node.dataset.mine).toBe("true");

    });

    it("shows a tombstone for a removed message, with no body", () => {

        const node = messageNode(message({ removed: true, body: null }), { mine: false });

        expect(node.textContent).toContain("Message removed");
        expect(node.textContent).not.toContain("Quiz moved");

    });

    it("lets a long unbroken string wrap", () => {

        const node = messageNode(message({ body: "a".repeat(4000) }), { mine: false });

        expect(node.className).toContain("break-words");
        expect(node.textContent).toContain("a".repeat(4000));

    });

    it("renders hostile text and names as text, not markup", () => {

        const node = messageNode(message({ sender_name: "<b>x</b>", body: "<img src=x onerror=alert(1)>" }), { mine: false });

        expect(node.querySelector("img")).toBeNull();
        expect(node.querySelector("b")).toBeNull();

    });

});

describe("a conversation row", () => {

    const row = (over = {}) => ({ id: 5, type: "direct", name: "Prof. Reyes", initials: "PR", subtitle: null, preview: "Thanks", last_message_at: "2026-11-03 09:41:00", unread: 0, ...over });

    it("shows initials, name, preview and time", () => {

        const node = conversationRow(row(), { selected: false });

        expect(node.textContent).toContain("PR");
        expect(node.textContent).toContain("Prof. Reyes");
        expect(node.textContent).toContain("Thanks");
        expect(node.tagName).toBe("BUTTON");
        expect(node.dataset.id).toBe("5");

    });

    it("shows a pin for a pinned chat and a muted icon for a muted one", () => {

        expect(conversationRow(row(), { selected: false }).querySelector("svg")).toBeNull();

        const node = conversationRow(row({ pinned: true, muted: true }), { selected: false });

        expect(node.querySelectorAll("svg")).toHaveLength(2);
        expect(node.querySelector("[data-muted]")).not.toBeNull();

    });

    it("dims an archived chat and offers the chat actions control", () => {

        const node = conversationRow(row({ archived: true }), { selected: false });

        expect(node.className).toContain("opacity-70");
        expect(node.querySelector("[aria-label='Chat actions']")).not.toBeNull();

    });

    it("shows a dot for a chat marked unread", () => {

        expect(conversationRow(row({ marked_unread: true }), { selected: false }).querySelector("[data-marked-unread]")).not.toBeNull();

    });

    it("shows the child's name under a parent thread", () => {

        expect(conversationRow(row({ subtitle: "Stu Dent" }), { selected: false }).textContent).toContain("Stu Dent");

    });

    it("marks the selected row and keeps that for assistive technology", () => {

        const node = conversationRow(row(), { selected: true });

        expect(node.getAttribute("aria-current")).toBe("true");
        expect(node.className).toContain("bg-brand-tint");

    });

    it("shows an unread count with a spoken word", () => {

        const node = conversationRow(row({ unread: 3 }), { selected: false });

        expect(node.querySelector("[data-unread]").textContent).toBe("3");
        expect(node.textContent).toContain("unread");

    });

    it("shapes the avatar by type", () => {

        expect(conversationRow(row({ type: "course" }), { selected: false }).querySelector("[data-avatar]").className).toContain("rounded-lg");
        expect(conversationRow(row({ type: "group" }), { selected: false }).querySelector("[data-avatar]").className).toContain("bg-accent");
        expect(conversationRow(row(), { selected: false }).querySelector("[data-avatar]").className).toContain("rounded-full");

    });

    it("renders hostile names and previews as text", () => {

        const node = conversationRow(row({ name: "<b>x</b>", preview: "<img src=x onerror=alert(1)>" }), { selected: false });

        expect(node.querySelector("b")).toBeNull();
        expect(node.querySelector("img")).toBeNull();

    });

});

describe("dayLabel", () => {

    const now = new Date(2026, 10, 3, 15, 0, 0);

    it("names today and yesterday, and dates anything older", () => {

        expect(dayLabel("2026-11-03 00:01:00", now)).toBe("Today");
        expect(dayLabel("2026-11-02 23:00:00", now)).toBe("Yesterday");
        expect(dayLabel("2026-10-30 10:00:00", now)).toBe("30 Oct");
        expect(dayLabel("2025-12-30 10:00:00", now)).toBe("30 Dec 2025");

    });

    it("is the same for two times on one day, so one separator covers them", () => {

        expect(dayLabel("2026-10-30 08:00:00", now)).toBe(dayLabel("2026-10-30 21:30:00", now));

    });

});

describe("formatTime", () => {

    const now = new Date(2026, 10, 3, 15, 0, 0);

    it("shows the clock for today", () => {

        expect(formatTime("2026-11-03 09:41:00", now)).toBe("09:41");

    });

    it("says Yesterday for the day before", () => {

        expect(formatTime("2026-11-02 23:59:00", now)).toBe("Yesterday");

    });

    it("shows the weekday within a week", () => {

        expect(formatTime("2026-10-30 10:00:00", now)).toBe("Fri");

    });

    it("shows the day and month when older", () => {

        expect(formatTime("2026-10-01 10:00:00", now)).toBe("1 Oct");

    });

    it("shows nothing for a missing time", () => {

        expect(formatTime(null, now)).toBe("");

    });

});
