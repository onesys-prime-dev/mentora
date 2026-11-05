import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initNotificationsPage } from "../../../resources/js/pages/notifications/notifications.js";

const row = (id, unread, type = "message") => `
<li data-id="${id}" data-notification="${unread ? "unread" : "read"}" data-type="${type}">
  <a data-role="row" href="/messages/${id}" class="${unread ? "bg-[#F2F7FF]" : ""}">
    <span data-role="badge" class="bg-brand text-white"></span>
    <span data-role="title" class="font-semibold text-gray-900">T${id}</span>
    <span data-role="dot"></span>
  </a>
</li>`;

beforeEach(() => {
    document.body.innerHTML = `
<div data-notifications-page data-read-url="/notifications/read" data-delete-url="/notifications/delete">
  <p data-role="summary"></p>
  <form data-role="mark-all"></form>
  <button data-filter="all" data-on="a" data-off="b" aria-pressed="true">All</button>
  <button data-filter="unread" data-on="a" data-off="b" aria-pressed="false">Unread <span data-role="unread-count"></span></button>
  <div data-role="list">
    <section data-group><ul>${row(1, true)}${row(2, false)}</ul></section>
  </div>
  <p data-role="empty" class="hidden"></p>
  <p data-role="filter-empty" class="hidden"></p>
</div>`;
    globalThis.fetch = vi.fn().mockResolvedValue({ status: 200 });
    initNotificationsPage();
});

afterEach(() => {
    document.body.innerHTML = "";
    delete globalThis.fetch;
});

const rightClick = (el) => el.dispatchEvent(new MouseEvent("contextmenu", { bubbles: true, cancelable: true, clientX: 5, clientY: 5 }));

describe("the notifications page", () => {
    it("marks a notification read when its row is clicked, and counts it", () => {
        document.querySelector("li[data-id='1'] a").addEventListener("click", (e) => e.preventDefault());
        document.querySelector("li[data-id='1'] a").click();

        expect(document.querySelector("li[data-id='1']").dataset.notification).toBe("read");
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toBe("/notifications/read");
        expect(document.querySelector("[data-role=unread-count]").textContent).toBe("0");
    });

    it("offers Mark as read on an unread row and only Delete on a read one", () => {
        rightClick(document.querySelector("li[data-id='1'] a"));
        expect([...document.querySelectorAll("[data-role=menu] [data-action]")].map((b) => b.dataset.action)).toEqual(["read", "delete"]);

        document.querySelector("[data-role=menu] [data-action=delete]").click();
        rightClick(document.querySelector("li[data-id='2'] a"));
    });

    it("deletes a notification from the list and the server", async () => {
        rightClick(document.querySelector("li[data-id='2'] a"));
        expect([...document.querySelectorAll("[data-role=menu] [data-action]")].map((b) => b.dataset.action)).toEqual(["delete"]);

        document.querySelector("[data-role=menu] [data-action=delete]").click();
        await Promise.resolve();
        await Promise.resolve();

        expect(fetch.mock.calls[0][0]).toBe("/notifications/delete");
        expect(document.querySelector("li[data-id='2']")).toBeNull();
    });
});
