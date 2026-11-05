/*
|--------------------------------------------------------------------------
| Messages — Settings dialog
|--------------------------------------------------------------------------
| "My alerts" (the existing email and quiet-hours form, plus the people the
| person has blocked) for everyone, and "Chat controls" for the institution's
| administrator only: a picker of every course chat and staff group of the
| institution, then that chat's switches. The server decides who that is (it
| answers `chat-controls` with an empty list for anyone else); this only shows
| what it says. Text is set with textContent only.
|--------------------------------------------------------------------------
*/

const SWITCHES = [
    { name: "lock", label: "Only the instructor can post", help: "Students can read but not write (announcements only).", courseOnly: true },
    { name: "reactions", label: "Reactions", help: "Members can react to messages." },
    { name: "replies", label: "Replies", help: "Members can reply with a quote." },
    { name: "forwarding", label: "Forwarding", help: "Members can forward messages out of this chat." },
    { name: "bump", label: "Bump", help: "Members can resend their own message as a reminder." },
    { name: "delete_everyone", label: "Delete for everyone", help: "Members can delete their own message for everyone for 15 minutes. Delete for me is always available." },
];

function node(tag, className, text) {
    const el = document.createElement(tag);

    if (className) {
        el.className = className;
    }

    if (text !== undefined) {
        el.textContent = text;
    }

    return el;
}

/**
 * @param {{root: HTMLElement, api: object, onControlsChanged?: (c: object) => void}} deps
 * @returns {{sync: () => void}}
 */
export function createSettings({ root, api, onControlsChanged = () => {} }) {
    const $ = (selector) => root.querySelector(selector);
    const tabs = $("[data-role=settings-tabs]");
    const tabAlerts = $("[data-role=settings-tab-alerts]");
    const tabControls = $("[data-role=settings-tab-controls]");
    const panelAlerts = $("[data-role=settings-panel-alerts]");
    const panelControls = $("[data-role=settings-panel-controls]");
    const title = $("[data-role=controls-title]");
    const empty = $("[data-role=controls-empty]");
    const list = $("[data-role=controls-list]");
    const note = $("[data-role=controls-note]");
    const blockedList = $("[data-role=blocked-list]");
    const blockedEmpty = $("[data-role=blocked-empty]");
    const blockedCount = $("[data-role=blocked-count]");

    // A page without the Settings markup (older fixtures, a trimmed view) simply has no Chat controls.
    if (![tabs, tabAlerts, tabControls, panelAlerts, panelControls, title, empty, list, note, blockedList, blockedEmpty, blockedCount].every(Boolean)) {
        return { sync() {} };
    }

    let tab = "alerts";
    let chats = [];
    let selectedId = null;

    const picker = node("select", "w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50");

    picker.dataset.role = "controls-picker";
    picker.setAttribute("aria-label", "Chat");
    picker.hidden = true;
    picker.addEventListener("change", () => {
        selectedId = Number(picker.value);
        renderControls();
    });
    title.parentNode.insertBefore(picker, title);

    function showTab(next) {
        tab = next;
        panelAlerts.hidden = tab !== "alerts";
        panelControls.hidden = tab !== "controls";
        tabAlerts.setAttribute("aria-selected", String(tab === "alerts"));
        tabControls.setAttribute("aria-selected", String(tab === "controls"));
        tabAlerts.className = tabAlerts.className.replace(tab === "alerts" ? "border-transparent" : "border-brand", tab === "alerts" ? "border-brand" : "border-transparent");
        tabControls.className = tabControls.className.replace(tab === "controls" ? "border-transparent" : "border-brand", tab === "controls" ? "border-brand" : "border-transparent");
    }

    function renderControls() {
        const c = chats.find((chat) => chat.id === selectedId) ?? null;

        note.textContent = "";

        picker.hidden = chats.length === 0;
        picker.replaceChildren(...chats.map((chat) => {
            const option = node("option", "", `${chat.name} · ${chat.type === "course" ? "course chat" : "group"}`);

            option.value = String(chat.id);
            option.selected = chat.id === selectedId;

            return option;
        }));

        if (!c) {
            title.hidden = true;
            list.replaceChildren();
            empty.hidden = false;
            empty.textContent = "Only your institution's administrator can change chat controls.";

            return;
        }

        empty.hidden = true;
        title.hidden = true;

        list.replaceChildren(...SWITCHES.filter((s) => !s.courseOnly || c.type === "course").map((s) => {
            const row = node("div", "flex items-center justify-between gap-3 border-b border-gray-100 py-2");
            const text = node("div", "min-w-0");
            const toggle = node("button", "relative h-[19px] w-[34px] flex-none rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50");
            const knob = node("span", "absolute left-[2px] top-[2px] h-[15px] w-[15px] rounded-full bg-white transition-transform");
            const on = Boolean(c.controls?.[s.name]);

            text.append(node("span", "block text-sm text-[#1A1C1E]", s.label), node("small", "block text-xs text-[#646464]", s.help));
            toggle.type = "button";
            toggle.dataset.control = s.name;
            toggle.setAttribute("role", "switch");
            toggle.setAttribute("aria-label", s.label);
            toggle.append(knob);

            const paint = (value) => {
                toggle.setAttribute("aria-checked", String(value));
                toggle.classList.toggle("bg-brand", value);
                toggle.classList.toggle("bg-gray-300", !value);
                knob.style.transform = value ? "translateX(15px)" : "translateX(0)";
            };

            paint(on);

            toggle.addEventListener("click", async () => {
                const next = toggle.getAttribute("aria-checked") !== "true";

                paint(next);
                toggle.disabled = true;
                note.textContent = "";

                const result = await api.setControl(c.id, s.name, next);

                toggle.disabled = false;

                if (result.status === 200) {
                    c.controls = { ...(c.controls ?? {}), ...(result.data?.controls ?? { [s.name]: next }) };

                    if (s.name === "lock") {
                        c.is_locked = c.controls.lock ? 1 : 0;
                    }

                    paint(Boolean(c.controls[s.name]));
                    onControlsChanged(c);
                } else {
                    paint(!next);
                    note.textContent = "Couldn't change that. Try again.";
                }
            });

            row.append(text, toggle);

            return row;
        }));
    }

    async function renderBlocked() {
        const { status, data } = await api.blocks();

        if (status !== 200 || !data) {
            return;
        }

        const people = data.blocked ?? [];

        blockedCount.textContent = String(people.length);
        blockedEmpty.hidden = people.length > 0;
        blockedList.replaceChildren(...people.map((p) => {
            const item = node("li", "flex items-center justify-between gap-2 text-sm");
            const unblock = node("button", "rounded-full border border-gray-300 px-2.5 py-0.5 text-xs font-semibold text-[#646464] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50", "Unblock");

            unblock.type = "button";
            unblock.dataset.unblock = String(p.id);
            unblock.addEventListener("click", async () => {
                unblock.disabled = true;

                const result = await api.unblock(p.id);

                if (result.status === 200) {
                    renderBlocked();
                } else {
                    unblock.disabled = false;
                }
            });
            item.append(node("span", "truncate", p.name), unblock);

            return item;
        }));
    }

    // Called when the dialog opens.
    async function sync() {
        renderBlocked();

        const { status, data } = await api.chatControls();

        chats = status === 200 && Array.isArray(data?.chats) ? data.chats : [];

        if (!chats.some((chat) => chat.id === selectedId)) {
            selectedId = chats[0]?.id ?? null;
        }

        tabs.hidden = chats.length === 0;
        showTab(chats.length === 0 ? "alerts" : tab);
        renderControls();
    }

    tabAlerts.addEventListener("click", () => showTab("alerts"));
    tabControls.addEventListener("click", () => { showTab("controls"); renderControls(); });

    return { sync };
}
