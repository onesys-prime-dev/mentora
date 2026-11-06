import { describe, expect, it, vi } from "vitest";

import { createApi } from "../../../resources/js/pages/messages/api.js";

function setup() {
    const fetchImpl = vi.fn(async () => ({ status: 200, json: async () => ({ ok: true }) }));
    const api = createApi("/messages/api", fetchImpl);

    const last = () => {
        const [url, init] = fetchImpl.mock.calls.at(-1);

        return { url, method: init.method, body: init.body === undefined ? undefined : JSON.parse(init.body) };
    };

    return { api, last };
}

describe("messages api client: actions", () => {

    it("lists archived conversations with archived=1", async () => {
        const { api, last } = setup();

        await api.conversations({ archived: true });
        expect(last()).toMatchObject({ method: "GET", url: "/messages/api/conversations?archived=1" });

        await api.conversations({ filter: "all" });
        expect(last().url).toBe("/messages/api/conversations");
    });

    it.each(["pin", "unpin", "mute", "unmute", "archive", "unarchive", "unread", "delete"])("posts chat action %s", async (action) => {
        const { api, last } = setup();

        const result = await api.chatAction(7, action);

        expect(last()).toEqual({ url: `/messages/api/conversations/7/${action}`, method: "POST", body: {} });
        expect(result).toEqual({ status: 200, data: { ok: true } });
    });

    it("reacts, lists reactors, bumps, and deletes for everyone", async () => {
        const { api, last } = setup();

        await api.react(7, 9, "👍");
        expect(last()).toEqual({ url: "/messages/api/conversations/7/messages/9/react", method: "POST", body: { emoji: "👍" } });

        await api.reactors(7, 9);
        expect(last()).toMatchObject({ url: "/messages/api/conversations/7/messages/9/reactors", method: "GET", body: undefined });

        await api.bump(7, 9);
        expect(last()).toEqual({ url: "/messages/api/conversations/7/messages/9/bump", method: "POST", body: {} });

        await api.deleteEveryone(7, 9);
        expect(last()).toEqual({ url: "/messages/api/conversations/7/messages/9/delete-everyone", method: "POST", body: {} });
    });

    it("forwards to targets", async () => {
        const { api, last } = setup();

        await api.forward(7, 9, [1, 2]);

        expect(last()).toEqual({ url: "/messages/api/conversations/7/messages/9/forward", method: "POST", body: { targets: [1, 2] } });
    });

    it("pins and unpins a message", async () => {
        const { api, last } = setup();

        await api.pinMessage(7, 9, true);
        expect(last().url).toBe("/messages/api/conversations/7/messages/9/pin");

        await api.pinMessage(7, 9, false);
        expect(last()).toEqual({ url: "/messages/api/conversations/7/messages/9/unpin", method: "POST", body: {} });
    });

    it("reads the changes feed", async () => {
        const { api, last } = setup();

        await api.changes(7, "2026-11-04 10:00:00");

        expect(last()).toMatchObject({ method: "GET", url: "/messages/api/conversations/7/changes?since=2026-11-04%2010%3A00%3A00" });
    });

    it("sets a control switch", async () => {
        const { api, last } = setup();

        await api.setControl(7, "students_can_reply", true);

        expect(last()).toEqual({ url: "/messages/api/conversations/7/controls", method: "POST", body: { switch: "students_can_reply", on: true } });
    });

    it("sends with and without reply_to", async () => {
        const { api, last } = setup();

        await api.send(7, "hi", "u-1");
        expect(last().body).toEqual({ body: "hi", client_uuid: "u-1" });

        await api.send(7, "hi", "u-1", 42);
        expect(last().body).toEqual({ body: "hi", client_uuid: "u-1", reply_to: 42 });
    });

    it("lists blocks", async () => {
        const { api, last } = setup();

        await api.blocks();

        expect(last()).toMatchObject({ url: "/messages/api/blocks", method: "GET" });
    });

});
