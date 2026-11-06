/*
|--------------------------------------------------------------------------
| Notifications panel — opens under the bell like the app menu.
|--------------------------------------------------------------------------
|
| The newest 7 load when the panel opens; scrolling to the bottom fetches the
| next 7, with skeleton rows in place while that batch loads. The full page
| (/notifications) is one link away.
|
*/

import { adjustBellCount, setBellCount } from "./notifications-badge.js";

const BATCH_SKELETON_ROWS = 3;

// The panel is a glance: the latest few, scrolling inside it. Everything else is on the Notifications page.
const PANEL_LIMIT = 10;

const button = document.getElementById("notifications-button");
const panel = document.getElementById("notifications-panel");

/** The loading shape of one batch, drawn the way a notification row will look. */
export function notificationSkeletonRows(count = BATCH_SKELETON_ROWS) {

    return Array.from({ length: count }, () => `
        <li class="flex animate-pulse gap-3 px-5 py-3" data-notification-skeleton aria-hidden="true">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-gray-200"></span>
            <span class="flex min-w-0 flex-1 flex-col gap-2">
                <span class="h-3.5 w-3/4 rounded bg-gray-200"></span>
                <span class="h-3 w-full rounded bg-gray-200"></span>
                <span class="h-2.5 w-16 rounded bg-gray-200"></span>
            </span>
        </li>`).join("");

}

if (button && panel) {

    const scroller = panel.querySelector("[data-notifications-scroll]");
    const list = panel.querySelector("[data-notifications-list]");
    const empty = panel.querySelector("[data-notifications-empty]");
    const errorBox = panel.querySelector("[data-notifications-error]");

    const readUrl = panel.dataset.feedUrl.replace("/feed", "/read");
    const deleteUrl = panel.dataset.feedUrl.replace("/feed", "/delete");
    const markAllButton = panel.querySelector("[data-notifications-mark-all]");
    const chips = [...panel.querySelectorAll("[data-notifications-filter]")];
    let filter = "all";

    let loaded = false;
    let loading = false;
    let hasMore = true;
    let before = null;

    const rowsOf = () => [...list.querySelectorAll("[data-notification-item]")];
    const isUnread = (li) => li.dataset.notification === "unread";

    function post(url, id, keepalive = false) {
        const body = new FormData();

        if (id !== null) {
            body.set("id", id);
        }

        return fetch(url, { method: "POST", body, credentials: "same-origin", redirect: "manual", keepalive }).catch(() => null);
    }

    // Filter and the "Mark all read" link follow whatever is in the list now.
    function refresh() {
        rowsOf().forEach((li) => { li.hidden = filter === "unread" && !isUnread(li); });

        if (markAllButton) {
            markAllButton.hidden = !rowsOf().some(isUnread);
        }

        empty.classList.toggle("hidden", rowsOf().some((li) => !li.hidden) || !loaded);
        empty.textContent = filter === "unread" && rowsOf().length > 0 ? "No unread notifications." : "You have no notifications yet.";
    }

    function showRead(li) {
        li.dataset.notification = "read";
        li.querySelector("[data-role=note-row]")?.classList.remove("bg-brand/5");
        li.querySelector("[data-role=note-dot]")?.classList.add("invisible");
        li.querySelector("[data-role=note-icon]")?.classList.replace("bg-brand", "bg-gray-100");
        li.querySelector("[data-role=note-icon]")?.classList.replace("text-white", "text-gray-600");
        li.querySelector("[data-role=note-title]")?.classList.replace("font-bold", "font-medium");
        li.querySelector("[data-role=note-time]")?.classList.replace("text-brand", "text-[#767676]");
        li.querySelector("[data-action=read]")?.setAttribute("hidden", "");
    }

    function markRead(li, keepalive = false) {
        if (!isUnread(li)) {
            return;
        }

        showRead(li);
        adjustBellCount(-1);
        refresh();
        post(readUrl, li.dataset.notificationId, keepalive);
    }

    async function remove(li) {
        const response = await post(deleteUrl, li.dataset.notificationId);

        if (response !== null && (response.status === 200 || response.status === 404)) {
            if (isUnread(li)) {
                adjustBellCount(-1);
            }

            li.remove();
            refresh();
        }
    }

    const isOpen = () => !panel.classList.contains("hidden");

    const openPanel = () => {
        panel.classList.remove("hidden");
        button.setAttribute("aria-expanded", "true");

        if (!loaded) {
            loadBatch();
        }
    };

    const closePanel = () => {
        panel.classList.add("hidden");
        button.setAttribute("aria-expanded", "false");
    };

    async function loadBatch() {

        if (loading || !hasMore) {
            return;
        }

        loading = true;

        errorBox.classList.add("hidden");

        list.insertAdjacentHTML("beforeend", notificationSkeletonRows());

        try {

            const url = new URL(panel.dataset.feedUrl, window.location.origin);

            url.searchParams.set("limit", String(PANEL_LIMIT));

            if (before !== null) {
                url.searchParams.set("before", String(before));
            }

            const response = await fetch(url, {
                headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
            });

            if (!response.ok) {
                throw new Error("Unable to load notifications.");
            }

            const result = await response.json();

            list.querySelectorAll("[data-notification-skeleton]").forEach((row) => row.remove());

            list.insertAdjacentHTML("beforeend", result.html);

            // One batch only; "View all notifications" is the way to the rest.
            hasMore = false;
            before = result.next_before ?? before;
            loaded = true;

            refresh();

        } catch (error) {

            console.error(error);

            list.querySelectorAll("[data-notification-skeleton]").forEach((row) => row.remove());

            errorBox.classList.remove("hidden");

        } finally {

            loading = false;

        }

        // A short first batch can leave no scrollbar to trigger the next one.
        if (hasMore && loaded && scroller.scrollHeight <= scroller.clientHeight + 1) {
            loadBatch();
        }

    }

    button.addEventListener("click", () => {
        isOpen() ? closePanel() : openPanel();
    });

    list.addEventListener("click", (event) => {
        const action = event.target.closest("[data-action]");
        const li = event.target.closest("[data-notification-item]");

        if (!li) {
            return;
        }

        if (action?.dataset.action === "read") {
            event.preventDefault();
            markRead(li);
        } else if (action?.dataset.action === "delete") {
            event.preventDefault();
            remove(li);
        } else if (event.target.closest("[data-role=note-row]")) {
            // A click on the row goes to its link; the read mark has to get out first.
            markRead(li, true);
        }
    });

    markAllButton?.addEventListener("click", () => {
        rowsOf().forEach(showRead);
        setBellCount(0);
        refresh();
        post(readUrl, null);
    });

    chips.forEach((chip) => chip.addEventListener("click", () => {
        filter = chip.dataset.notificationsFilter;
        chips.forEach((other) => {
            const on = other === chip;

            other.setAttribute("aria-pressed", String(on));
            other.classList.toggle("bg-brand", on);
            other.classList.toggle("text-white", on);
            other.classList.toggle("font-semibold", on);
            other.classList.toggle("border-brand", on);
            other.classList.toggle("border-transparent", !on);
            other.classList.toggle("bg-[#eef2f8]", !on);
            other.classList.toggle("text-gray-700", !on);
        });
        refresh();
    }));

    scroller.addEventListener("scroll", () => {

        if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 24) {
            loadBatch();
        }

    });

    panel.querySelector("[data-notifications-retry]")?.addEventListener("click", () => loadBatch());

    // Close when clicking outside the button/panel.
    document.addEventListener("click", (event) => {
        if (!isOpen()) return;

        if (!panel.contains(event.target) && !button.contains(event.target)) {
            closePanel();
        }
    });

    // Close on Escape.
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isOpen()) {
            closePanel();
            button.focus();
        }
    });

}
