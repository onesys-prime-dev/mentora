/*
|--------------------------------------------------------------------------
| A preview beside the bell when something new arrives
|--------------------------------------------------------------------------
| Every 20 s (slower while the tab is hidden) the page asks for the newest
| notification id. The first answer is only the baseline, so nothing that was
| already there pops up. Anything newer shows as a small card under the bell
| with its title, its line and an Open link, and goes away by itself after 8 s.
| The bell's panel and the badge counts are not touched: this only previews.
| Text is set with textContent, never as HTML.
|--------------------------------------------------------------------------
*/

const SHOW_MS = 8000;
const POLL_MS = 20000;
const POLL_HIDDEN_MS = 60000;

export function startNotificationToast(doc = document, fetchImpl = (...args) => fetch(...args)) {
    const bell = doc.getElementById("notifications-button");
    const panel = doc.getElementById("notifications-panel");

    if (!bell || !panel?.dataset.feedUrl) {
        return () => {};
    }

    const url = panel.dataset.feedUrl.replace(/\/feed$/, "/latest");
    let lastId = null;
    let timer = null;
    let stopped = false;

    const host = doc.createElement("div");

    host.setAttribute("role", "status");
    host.setAttribute("aria-live", "polite");
    host.dataset.role = "notification-toast-host";
    host.className = "pointer-events-none absolute right-0 top-full z-40 mt-2 flex w-80 flex-col gap-2";
    bell.parentElement.append(host);

    function show(item) {
        const card = doc.createElement(item.link ? "a" : "div");

        card.dataset.role = "notification-toast";
        card.className = `pointer-events-auto flex gap-3 rounded-2xl border bg-white p-3 text-left shadow-lg ring-1 ring-black/5 ${item.urgent ? "border-[#C33939]" : "border-gray-200"}`;

        if (item.link) {
            card.href = item.link;
        }

        const icon = doc.createElement("span");

        icon.className = `mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full ${item.urgent ? "bg-[#C33939]" : "bg-brand"}`;
        icon.setAttribute("aria-hidden", "true");

        const text = doc.createElement("span");
        const title = doc.createElement("span");
        const body = doc.createElement("span");

        text.className = "flex min-w-0 flex-col";
        title.className = "text-sm font-semibold text-gray-900";
        title.textContent = item.title;
        body.className = "line-clamp-2 text-xs text-gray-600";
        body.textContent = item.body;
        text.append(title, body);

        const close = doc.createElement("button");

        close.type = "button";
        close.className = "ml-auto h-6 w-6 shrink-0 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-700";
        close.setAttribute("aria-label", "Dismiss");
        close.textContent = "×";
        close.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            card.remove();
        });

        card.append(icon, text, close);
        host.append(card);
        setTimeout(() => card.remove(), SHOW_MS);
    }

    async function tick() {
        if (stopped) {
            return;
        }

        const wanted = lastId === null ? url : `${url}?after=${lastId}`;
        const response = await fetchImpl(wanted, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" }).catch(() => null);

        if (response?.status === 200) {
            const data = await response.json().catch(() => null);

            if (data && typeof data.last_id === "number") {
                // The panel being open already shows what arrived, so only preview while it is shut.
                if (lastId !== null && panel.classList.contains("hidden")) {
                    // On the Messages page the chat itself is the notification.
                    const onMessages = doc.defaultView?.location.pathname.includes("/messages") ?? false;

                    (data.items ?? []).filter((item) => !(onMessages && String(item.link ?? "").includes("/messages"))).forEach(show);
                }

                lastId = Math.max(lastId ?? 0, data.last_id);
            }
        } else if (response?.status === 404 || response?.status === 403) {
            return;
        }

        timer = setTimeout(tick, doc.visibilityState === "hidden" ? POLL_HIDDEN_MS : POLL_MS);
    }

    tick();

    return () => {
        stopped = true;
        clearTimeout(timer);
        host.remove();
    };
}

if (typeof document !== "undefined" && document.getElementById("notifications-button")) {
    startNotificationToast();
}
