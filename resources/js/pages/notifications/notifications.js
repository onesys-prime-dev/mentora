/*
|--------------------------------------------------------------------------
| Notifications page — list on the left, the chosen one on the right
|--------------------------------------------------------------------------
| Choosing a row shows its panel and marks it read at once (the count, the dot
| and the row update without a reload). The filter chips only hide rows.
| Everything is already on the page; the only request is the read mark.
|--------------------------------------------------------------------------
*/

export function initNotificationsPage(root = document.querySelector("[data-notifications-page]")) {
    if (!root) {
        return;
    }

    const rows = [...root.querySelectorAll("[data-open]")];
    const panels = [...root.querySelectorAll("[data-detail]")];
    const countEl = root.querySelector("[data-role=unread-count]");
    const markAll = root.querySelector("[data-role=mark-all]");
    const emptyEl = root.querySelector("[data-role=filter-empty]");
    const readUrl = root.dataset.readUrl;
    let filter = "all";

    const itemOf = (button) => button.closest("li");
    const unreadCount = () => root.querySelectorAll("li[data-notification=unread]").length;

    function syncCount() {
        const n = unreadCount();

        if (countEl) {
            countEl.textContent = String(n);
        }

        if (markAll) {
            markAll.hidden = n === 0;
        }
    }

    function markRead(button) {
        const item = itemOf(button);

        if (item.dataset.notification !== "unread") {
            return;
        }

        item.dataset.notification = "read";
        item.querySelector("[data-role=dot]")?.classList.add("invisible");
        item.querySelector("[data-role=badge]")?.classList.replace("bg-brand", "bg-gray-100");
        item.querySelector("[data-role=badge]")?.classList.replace("text-white", "text-gray-600");
        item.querySelector("[data-role=title]")?.classList.replace("font-semibold", "font-medium");
        root.querySelector(`[data-detail="${button.dataset.open}"] [data-role=read-form]`)?.setAttribute("hidden", "");
        syncCount();

        const body = new FormData();

        body.set("id", button.dataset.open);
        fetch(readUrl, { method: "POST", body, credentials: "same-origin", redirect: "manual" }).catch(() => {});
    }

    function select(button) {
        rows.forEach((row) => row.setAttribute("aria-current", row === button ? "true" : "false"));
        panels.forEach((panel) => { panel.hidden = panel.dataset.detail !== button.dataset.open; });
        markRead(button);
    }

    function applyFilter() {
        let shown = 0;

        rows.forEach((button) => {
            const item = itemOf(button);
            const show = filter === "all" || (filter === "unread" && item.dataset.notification === "unread") || item.dataset.type === filter;

            item.hidden = !show;
            shown += show ? 1 : 0;
        });

        // With nothing to list, the list steps aside so the message sits in the middle of the card.
        root.querySelector("[data-role=list]")?.classList.toggle("hidden", shown === 0 && rows.length > 0);

        if (emptyEl) {
            emptyEl.classList.toggle("hidden", shown > 0 || rows.length === 0);
        }
    }

    rows.forEach((button) => button.addEventListener("click", () => select(button)));

    root.querySelectorAll("[data-filter]").forEach((chip) => chip.addEventListener("click", () => {
        filter = chip.dataset.filter;
        root.querySelectorAll("[data-filter]").forEach((other) => {
            const on = other === chip;

            other.setAttribute("aria-pressed", String(on));
            other.classList.toggle("bg-brand", on);
            other.classList.toggle("text-white", on);
            other.classList.toggle("border-brand", on);
            other.classList.toggle("bg-white", !on);
            other.classList.toggle("text-gray-700", !on);
            other.classList.toggle("border-gray-300", !on);
        });
        applyFilter();
    }));

    // The notification open on arrival counts as read, like choosing it.
    const open = rows.find((row) => row.getAttribute("aria-current") === "true");

    if (open) {
        markRead(open);
    }
}

initNotificationsPage();
