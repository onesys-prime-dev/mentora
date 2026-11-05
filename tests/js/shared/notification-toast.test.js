import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { startNotificationToast } from "../../../resources/js/shared/notification-toast.js";

const reply = (data) => Promise.resolve({ status: 200, json: () => Promise.resolve(data) });

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = `<div class="relative"><button id="notifications-button"></button><div id="notifications-panel" class="hidden" data-feed-url="/notifications/feed"></div></div>`;
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("the notification toast", () => {
    it("treats the first answer as a baseline and previews only what arrives after it", async () => {
        const fetchImpl = vi.fn()
            .mockImplementationOnce(() => reply({ last_id: 5, unread: 1, items: [] }))
            .mockImplementationOnce(() => reply({ last_id: 6, unread: 2, items: [{ id: 6, title: "New message", body: "<b>Joseph</b> wrote", link: "/messages/1", urgent: false }] }))
            .mockImplementation(() => reply({ last_id: 6, unread: 2, items: [] }));
        const stop = startNotificationToast(document, fetchImpl);

        await vi.advanceTimersByTimeAsync(0);
        expect(document.querySelector("[data-role=notification-toast]")).toBeNull();
        expect(fetchImpl.mock.calls[0][0]).toBe("/notifications/latest");

        await vi.advanceTimersByTimeAsync(5000);
        expect(fetchImpl.mock.calls[1][0]).toBe("/notifications/latest?after=5");

        const toast = document.querySelector("[data-role=notification-toast]");

        expect(toast.textContent).toContain("New message");
        expect(toast.textContent).toContain("<b>Joseph</b> wrote");
        expect(toast.querySelector("b")).toBeNull();
        expect(toast.getAttribute("href")).toBe("/messages/1");

        await vi.advanceTimersByTimeAsync(8000);
        expect(document.querySelector("[data-role=notification-toast]")).toBeNull();
        stop();
    });

    it("does not preview while the panel is open", async () => {
        document.getElementById("notifications-panel").classList.remove("hidden");

        const fetchImpl = vi.fn()
            .mockImplementationOnce(() => reply({ last_id: 5, unread: 0, items: [] }))
            .mockImplementationOnce(() => reply({ last_id: 6, unread: 1, items: [{ id: 6, title: "x", body: "y", link: null, urgent: false }] }));
        const stop = startNotificationToast(document, fetchImpl);

        await vi.advanceTimersByTimeAsync(5000);
        expect(document.querySelector("[data-role=notification-toast]")).toBeNull();
        stop();
    });
});
