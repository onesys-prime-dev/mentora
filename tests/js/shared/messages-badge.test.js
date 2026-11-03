import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { startBadgePolling } from "../../../resources/js/shared/messages-badge.js";

const tile = (count = 0) => `<span data-messages-badge data-url="/messages/api/unread-count" ${count > 0 ? "" : "hidden"}>${count}</span>`;
const reply = (status, data) => ({ status, json: () => Promise.resolve(data) });

beforeEach(() => vi.useFakeTimers());

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("the unread badge on the Messages tile", () => {

    it("asks for the count every 30 seconds and shows it on every badge", async () => {

        document.body.innerHTML = tile() + tile();
        const fetchImpl = vi.fn().mockResolvedValue(reply(200, { unread: 5 }));

        const stop = startBadgePolling(document, fetchImpl);

        expect(fetchImpl).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchImpl).toHaveBeenCalledWith("/messages/api/unread-count", expect.objectContaining({ credentials: "same-origin" }));
        document.querySelectorAll("[data-messages-badge]").forEach((badge) => {
            expect(badge.textContent).toBe("5");
            expect(badge.hidden).toBe(false);
        });

        stop();

    });

    it("hides the badge when nothing is unread and caps a large count", async () => {

        document.body.innerHTML = tile(3);
        const fetchImpl = vi.fn().mockResolvedValueOnce(reply(200, { unread: 0 })).mockResolvedValueOnce(reply(200, { unread: 250 }));

        const stop = startBadgePolling(document, fetchImpl);

        await vi.advanceTimersByTimeAsync(30000);
        expect(document.querySelector("[data-messages-badge]").hidden).toBe(true);

        await vi.advanceTimersByTimeAsync(30000);
        expect(document.querySelector("[data-messages-badge]").textContent).toBe("99+");

        stop();

    });

    it("keeps the last count when a request fails", async () => {

        document.body.innerHTML = tile(2);
        const fetchImpl = vi.fn().mockRejectedValue(new TypeError("offline"));

        const stop = startBadgePolling(document, fetchImpl);

        await vi.advanceTimersByTimeAsync(30000);

        expect(document.querySelector("[data-messages-badge]").textContent).toBe("2");

        stop();

    });

    it("stops asking once Messages is closed for the person", async () => {

        document.body.innerHTML = tile(2);
        const fetchImpl = vi.fn().mockResolvedValue(reply(404, { error: "not_found" }));

        startBadgePolling(document, fetchImpl);

        await vi.advanceTimersByTimeAsync(30000);
        await vi.advanceTimersByTimeAsync(120000);

        expect(fetchImpl).toHaveBeenCalledTimes(1);

    });

    it("does nothing without a badge, or on the Messages page, which refreshes the count itself", async () => {

        const fetchImpl = vi.fn().mockResolvedValue(reply(200, { unread: 1 }));

        startBadgePolling(document, fetchImpl);
        document.body.innerHTML = `<div id="messages-app"></div>${tile()}`;
        startBadgePolling(document, fetchImpl);

        await vi.advanceTimersByTimeAsync(60000);

        expect(fetchImpl).not.toHaveBeenCalled();

    });

});
