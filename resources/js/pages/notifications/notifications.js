/*
|--------------------------------------------------------------------------
| Notifications page — click to open, right-click for the menu
|--------------------------------------------------------------------------
| A row is a link: clicking it goes to that notification and marks it read on
| the way. Right-click, long-press or Shift+F10 opens a small menu with Mark as
| read and Delete. The filter chips only hide rows. Counts, dots and the empty
| messages follow every change without a reload.
|--------------------------------------------------------------------------
*/

import { adjustBellCount } from "../../shared/notifications-badge.js";
import { createMenu } from "../messages/menu.js";
import { bindMenuTriggers } from "../messages/gestures.js";

export function initNotificationsPage(root = document.querySelector("[data-notifications-page]")) {
    if (!root) {
        return;
    }

    const list = root.querySelector("[data-role=list]");
    const markAll = root.querySelector("[data-role=mark-all]");
    const summary = root.querySelector("[data-role=summary]");
    const countEl = root.querySelector("[data-role=unread-count]");
    const emptyEl = root.querySelector("[data-role=empty]");
    const filterEmptyEl = root.querySelector("[data-role=filter-empty]");
    const menu = createMenu(document);
    let filter = "all";

    const items = () => [...list.querySelectorAll("li[data-id]")];
    const isUnread = (li) => li.dataset.notification === "unread";

    function post(url, id, keepalive = false) {
        const body = new FormData();

        body.set("id", id);

        return fetch(url, { method: "POST", body, credentials: "same-origin", redirect: "manual", keepalive }).catch(() => null);
    }

    function refresh() {
        const unread = items().filter(isUnread).length;
        let shown = 0;

        items().forEach((li) => {
            const show = filter === "all" || (filter === "unread" && isUnread(li)) || li.dataset.type === filter;

            li.hidden = !show;
            shown += show ? 1 : 0;
        });

        list.querySelectorAll("[data-group]").forEach((group) => {
            group.hidden = [...group.querySelectorAll("li[data-id]")].every((li) => li.hidden);
        });

        if (countEl) {
            countEl.textContent = String(unread);
        }

        if (markAll) {
            markAll.hidden = unread === 0;
        }

        if (summary) {
            summary.textContent = items().length === 0 ? "" : unread === 0 ? "Everything is read" : `${unread} unread · everything else is read`;
        }

        emptyEl?.classList.toggle("hidden", items().length > 0);
        filterEmptyEl?.classList.toggle("hidden", shown > 0 || items().length === 0);
    }

    function showRead(li) {
        li.dataset.notification = "read";
        li.querySelector("[data-role=row]")?.classList.remove("bg-[#F2F7FF]");
        li.querySelector("[data-role=dot]")?.classList.add("invisible");
        li.querySelector("[data-role=badge]")?.classList.replace("bg-brand", "bg-gray-100");
        li.querySelector("[data-role=badge]")?.classList.replace("text-white", "text-gray-600");
        li.querySelector("[data-role=title]")?.classList.replace("font-semibold", "font-medium");
        li.querySelector("[data-role=title]")?.classList.replace("text-gray-900", "text-gray-700");
    }

    function markRead(li, { keepalive = false } = {}) {
        if (!isUnread(li)) {
            return;
        }

        showRead(li);
        adjustBellCount(-1);
        refresh();
        post(root.dataset.readUrl, li.dataset.id, keepalive);
    }

    async function remove(li) {
        const response = await post(root.dataset.deleteUrl, li.dataset.id);

        if (response === null || (response.status !== 200 && response.status !== 404)) {
            return;
        }

        if (isUnread(li)) {
            adjustBellCount(-1);
        }

        li.remove();
        refresh();
    }

    // Left click: the row's own link takes the person there; the read mark must still get out first.
    list.addEventListener("click", (event) => {
        const row = event.target.closest("[data-role=row]");

        if (!row || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }

        markRead(row.closest("li"), { keepalive: true });
    });

    list.addEventListener("keydown", (event) => {
        const row = event.target.closest?.("[data-role=row]");

        if (row && row.tagName !== "A" && (event.key === "Enter" || event.key === " ")) {
            event.preventDefault();
            markRead(row.closest("li"));
        }
    });

    bindMenuTriggers(list, {
        selector: "li[data-id]",
        onOpen: (li, at) => {
            const entries = [];

            if (isUnread(li)) {
                entries.push({ id: "read", label: "Mark as read", onSelect: () => markRead(li) });
            }

            entries.push({ id: "delete", label: "Delete", danger: true, onSelect: () => remove(li) });
            menu.open(entries, at);
        },
    });

    root.querySelectorAll("[data-filter]").forEach((chip) => chip.addEventListener("click", () => {
        filter = chip.dataset.filter;
        root.querySelectorAll("[data-filter]").forEach((other) => {
            const on = other === chip;

            other.setAttribute("aria-pressed", String(on));
            other.classList.remove(...(on ? other.dataset.off : other.dataset.on).split(" "));
            other.classList.add(...(on ? other.dataset.on : other.dataset.off).split(" "));
        });
        refresh();
    }));

    refresh();
}

initNotificationsPage();
