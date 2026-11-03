import { describe, expect, it, vi } from "vitest";

import { createApi } from "../../../resources/js/pages/messages/api.js";
import { MAX, canSend, createComposer } from "../../../resources/js/pages/messages/composer.js";

describe("canSend", () => {

    it("needs between 1 and 4000 characters once trimmed", () => {

        expect(MAX).toBe(4000);
        expect(canSend("", false)).toBe(false);
        expect(canSend("   \n ", false)).toBe(false);
        expect(canSend("hi", false)).toBe(true);
        expect(canSend("a".repeat(4000), false)).toBe(true);
        expect(canSend("a".repeat(4001), false)).toBe(false);

    });

    it("is false while a send is in flight", () => {

        expect(canSend("hi", true)).toBe(false);

    });

});

describe("the composer", () => {

    const deferred = () => {
        let resolve;
        const promise = new Promise((r) => { resolve = r; });

        return { promise, resolve };
    };

    it("ignores a second submit while the first is pending", async () => {

        const gate = deferred();
        const send = vi.fn(() => gate.promise);
        const composer = createComposer({ send, onSent: () => {}, onFailed: () => {} });

        composer.submit("hello");
        composer.submit("hello");
        expect(send).toHaveBeenCalledTimes(1);
        expect(composer.state).toBe("sending");

        gate.resolve({ status: 201, data: { message: { id: 1 } } });
        await gate.promise;

    });

    it("fails on a network error or 503, keeps the draft and retries with the same uuid", async () => {

        const send = vi.fn()
            .mockResolvedValueOnce({ status: 0, data: null })
            .mockResolvedValueOnce({ status: 503, data: { error: "unavailable" } })
            .mockResolvedValueOnce({ status: 201, data: { message: { id: 9 } } });
        const onFailed = vi.fn();
        const onSent = vi.fn();
        const composer = createComposer({ send, onSent, onFailed });

        await composer.submit("hello");
        expect(composer.state).toBe("failed");
        await composer.submit("hello");
        expect(composer.state).toBe("failed");
        await composer.submit("hello");

        const uuids = send.mock.calls.map((call) => call[1]);

        expect(new Set(uuids).size).toBe(1);
        expect(onFailed).toHaveBeenCalledTimes(2);
        expect(onSent).toHaveBeenCalledWith({ id: 9 });
        expect(composer.state).toBe("idle");

    });

    it("uses a new uuid for the next draft once one has been sent", async () => {

        const send = vi.fn().mockResolvedValue({ status: 201, data: { message: { id: 1 } } });
        const composer = createComposer({ send, onSent: () => {}, onFailed: () => {} });

        await composer.submit("one");
        await composer.submit("two");

        expect(send.mock.calls[0][1]).not.toBe(send.mock.calls[1][1]);
        expect(send.mock.calls[0][1]).toMatch(/^[0-9a-f-]{36}$/);

    });

    it("reports an invalid body as a failure without a retry loop", async () => {

        const send = vi.fn().mockResolvedValue({ status: 422, data: { error: "invalid_body" } });
        const onFailed = vi.fn();
        const composer = createComposer({ send, onSent: () => {}, onFailed });

        await composer.submit("x");

        expect(send).toHaveBeenCalledTimes(1);
        expect(onFailed).toHaveBeenCalledWith("invalid_body");
        expect(composer.state).toBe("failed");

    });

    it("sends with the uuid it is given, so a retry keeps the id of its own message", async () => {

        const send = vi.fn().mockResolvedValue({ status: 201, data: { message: { id: 1 } } });
        const composer = createComposer({ send, onSent: () => {}, onFailed: () => {} });

        await composer.submit("A", "uuid-of-a");
        await composer.submit("B");
        await composer.submit("A", "uuid-of-a");

        expect(send.mock.calls[0][1]).toBe("uuid-of-a");
        expect(send.mock.calls[1][1]).not.toBe("uuid-of-a");
        expect(send.mock.calls[2][1]).toBe("uuid-of-a");

    });

    it("does not send an empty or too-long draft", async () => {

        const send = vi.fn();
        const composer = createComposer({ send, onSent: () => {}, onFailed: () => {} });

        await composer.submit("   ");
        await composer.submit("a".repeat(4001));

        expect(send).not.toHaveBeenCalled();

    });

});

describe("the API client", () => {

    const reply = (status, data) => ({ status, json: () => Promise.resolve(data) });

    it("posts a send as JSON to the conversation's messages", async () => {

        const fetchImpl = vi.fn().mockResolvedValue(reply(201, { message: { id: 1 } }));
        const api = createApi("/messages/api", fetchImpl);

        const result = await api.send(7, "hi", "uuid-1");

        const [url, init] = fetchImpl.mock.calls[0];

        expect(url).toBe("/messages/api/conversations/7/messages");
        expect(init.method).toBe("POST");
        expect(JSON.parse(init.body)).toEqual({ body: "hi", client_uuid: "uuid-1" });
        expect(init.headers["Content-Type"]).toBe("application/json");
        expect(result).toEqual({ status: 201, data: { message: { id: 1 } } });

    });

    it("builds the list and page queries", async () => {

        const fetchImpl = vi.fn().mockResolvedValue(reply(200, {}));
        const api = createApi("/messages/api", fetchImpl);

        await api.conversations({ filter: "direct", unread: true });
        await api.page(7, { after: 40, limit: 30 });
        await api.recipients("ann lee");

        expect(fetchImpl.mock.calls[0][0]).toBe("/messages/api/conversations?filter=direct&unread=1");
        expect(fetchImpl.mock.calls[1][0]).toBe("/messages/api/conversations/7/messages?after=40&limit=30");
        expect(fetchImpl.mock.calls[2][0]).toBe("/messages/api/recipients?q=ann%20lee");

    });

    it("resolves status 0 and no data when the network fails", async () => {

        const api = createApi("/messages/api", vi.fn().mockRejectedValue(new TypeError("offline")));

        expect(await api.unread()).toEqual({ status: 0, data: null });

    });

    it("resolves the status of a 404 with an empty body instead of throwing", async () => {

        const api = createApi("/messages/api", vi.fn().mockResolvedValue({ status: 404, json: () => Promise.reject(new SyntaxError("x")) }));

        expect(await api.page(1, {})).toEqual({ status: 404, data: null });

    });

});
