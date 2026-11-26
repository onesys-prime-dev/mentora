/*
|--------------------------------------------------------------------------
| Messages — Group settings dialog
|--------------------------------------------------------------------------
| The dialog behind a group's header button and chat menu: a Members tab
| (people, owner badge, Remove and + Add people for whoever may change the
| group) and a Details tab (rename, Leave, Close group). The server decides
| everything: what comes back says whether this person can manage the group
| and whether it is closed, and a refusal is only "that didn't work".
| Leaving and closing take a second press, like Block. Names are only ever
| set as text.
|--------------------------------------------------------------------------
*/

import { renderAvatar } from "../../shared/avatar.js";
import { createGroupPicker, MAX_MEMBERS } from "./group.js";

const PILL = "rounded-full border px-3 py-1 text-xs font-semibold pointer-coarse:min-h-11 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
const FAILED = "That didn't work. Try again.";

/**
 * @param {{root: HTMLElement, api: object, getGroupId: () => number|null, onChanged: () => void, onLeft: () => void}} deps
 */
export function createGroupSettings({ root, api, getGroupId, onChanged, onLeft }) {
    const $ = (selector) => root.querySelector(selector);
    const dialog = $("#messages-group-settings");
    const opener = $("[data-role=group-settings-open]");

    if (!dialog || !opener) {
        return { destroy() {} };
    }

    const tabs = [...dialog.querySelectorAll("[role=tab]")];
    const panels = [...dialog.querySelectorAll("[role=tabpanel]")];
    const list = dialog.querySelector("[data-role=gs-members]");
    const count = dialog.querySelector("[data-role=gs-count]");
    const addToggle = dialog.querySelector("[data-role=gs-add-toggle]");
    const addBox = dialog.querySelector("[data-role=gs-add]");
    const search = dialog.querySelector("[data-role=gs-search]");
    const results = dialog.querySelector("[data-role=gs-results]");
    const chips = dialog.querySelector("[data-role=gs-chips]");
    const addSend = dialog.querySelector("[data-role=gs-add-send]");
    const rename = dialog.querySelector("[data-role=gs-rename]");
    const title = dialog.querySelector("[data-role=gs-title]");
    const save = dialog.querySelector("[data-role=gs-save]");
    const leave = dialog.querySelector("[data-role=gs-leave]");
    const close = dialog.querySelector("[data-role=gs-close]");
    const closedNote = dialog.querySelector("[data-role=gs-closed]");
    const noteEl = dialog.querySelector("[data-role=gs-note]");

    let data = null;
    let groupId = null;
    let adding = false;
    let confirming = null;
    let busy = false;
    let loads = 0;

    const note = (text) => { noteEl.textContent = text; };

    function select(name, focus = false) {
        confirming = null;
        paint();

        for (const tab of tabs) {
            const on = tab.dataset.tab === name;

            tab.setAttribute("aria-selected", String(on));
            tab.tabIndex = on ? 0 : -1;

            if (on && focus) {
                tab.focus();
            }
        }

        for (const panel of panels) {
            panel.hidden = panel.dataset.panel !== name;
        }
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener("click", () => select(tab.dataset.tab));
        tab.addEventListener("keydown", (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            const target = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : step === undefined ? null : (index + step + tabs.length) % tabs.length;

            if (target !== null) {
                event.preventDefault();
                select(tabs[target].dataset.tab, true);
            }
        });
    });

    const managing = () => data !== null && data.can_manage && !data.closed;
    const amMember = () => data !== null && data.members.some((m) => m.me);

    const picker = createGroupPicker({ search: (q) => api.groupCandidates(q), onChange: paintAdd });

    function paintAdd() {
        const inGroup = new Set((data?.members ?? []).map((m) => m.id));
        const free = picker.results.filter((entry) => !inGroup.has(entry.id));

        results.replaceChildren(...free.map((entry) => {
            const item = document.createElement("li");
            const button = document.createElement("button");

            button.type = "button";
            button.className = "flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold text-gray-900 pointer-coarse:min-h-11 hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
            button.append(...(entry.avatar ? [renderAvatar({ ...entry.avatar, interactive: false }, "xs", { decorative: true })] : []), entry.name);
            button.setAttribute("aria-pressed", String(picker.isChosen(entry.id)));
            button.addEventListener("click", () => { picker.toggle(entry); search.focus(); });
            item.append(button);

            return item;
        }));

        chips.replaceChildren(...picker.chosen().map((entry) => {
            const item = document.createElement("li");
            const remove = document.createElement("button");

            item.className = "inline-flex items-center rounded-full bg-brand-tint px-2.5 py-0.5 text-xs text-brand-dark";
            remove.type = "button";
            remove.className = "ml-1 inline-flex items-center justify-center rounded-full px-1 pointer-coarse:min-h-11 pointer-coarse:min-w-11 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50";
            remove.setAttribute("aria-label", `Remove ${entry.name}`);
            remove.textContent = "×";
            remove.addEventListener("click", () => { picker.toggle(entry); search.focus(); });
            item.append(document.createTextNode(`${entry.name} `), remove);

            return item;
        }));

        const room = MAX_MEMBERS - (data?.members.length ?? 0);

        addSend.disabled = busy || picker.ids().length === 0 || picker.ids().length > room;
        addSend.textContent = picker.ids().length > 0 ? `Add ${picker.ids().length}` : "Add";

        if (picker.state === "empty" || picker.state === "error") {
            note(picker.state === "empty" ? "No one found." : "The search failed. Try again.");
        }
    }

    function paint() {
        const members = data?.members ?? [];

        count.textContent = String(members.length);

        list.replaceChildren(...members.map((m) => {
            const item = document.createElement("li");
            const name = document.createElement("span");

            item.className = "flex items-center gap-2 border-b border-gray-100 py-1.5 text-sm text-gray-900";
            name.className = "min-w-0 flex-1 truncate";
            name.textContent = m.me ? `${m.name} (you)` : m.name;

            if (m.avatar) {
                item.append(renderAvatar({ ...m.avatar, interactive: false }, "xs", { decorative: true }));
            }

            item.append(name);

            if (m.owner) {
                const badge = document.createElement("span");

                badge.className = "flex-none rounded-full bg-brand-tint px-2 py-0.5 text-[11px] font-bold text-brand-dark";
                badge.textContent = "Owner";
                item.append(badge);
            }

            if (managing() && !m.me) {
                const remove = document.createElement("button");

                remove.type = "button";
                remove.dataset.action = "remove";
                remove.dataset.userId = String(m.id);
                remove.className = `${PILL} flex-none border-red-300 text-red-700 hover:bg-red-50`;
                remove.setAttribute("aria-label", `Remove ${m.name}`);
                remove.textContent = "Remove";
                item.append(remove);
            }

            return item;
        }));

        addToggle.hidden = !managing();
        addBox.hidden = !managing() || !adding;
        addToggle.setAttribute("aria-expanded", String(managing() && adding));
        rename.hidden = !managing();
        leave.hidden = !(data !== null && !data.closed && amMember());
        close.hidden = !managing();
        closedNote.hidden = !data?.closed;
        leave.textContent = confirming === "leave" ? "Press again to leave" : "Leave group";
        close.textContent = confirming === "close" ? "Press again to close the group" : "Close group";
        paintAdd();
    }

    async function load({ keepNote = false } = {}) {
        const mine = ++loads;
        const id = groupId;
        const { status, data: body } = await api.groupMembers(id);

        if (mine !== loads) {
            return;
        }

        if (status !== 200 || !body || !Array.isArray(body.members)) {
            data = null;
            paint();
            note("The group could not load.");

            return;
        }

        data = body;
        title.value = body.title;

        if (!keepNote) {
            note("");
        }

        paint();
    }

    // Runs one change; a 200 refreshes the dialog and the page, anything else is the one plain failure.
    async function change(call, done = "", { reload = true } = {}) {
        if (busy) {
            return false;
        }

        busy = true;
        confirming = null;
        paint();

        const { status, data: body } = await call();

        busy = false;

        if (status !== 200) {
            note(FAILED);
            paint();

            return false;
        }

        note(done);

        if (reload) {
            await load({ keepNote: true });
        }

        onChanged();

        return body ?? true;
    }

    opener.addEventListener("click", () => {
        groupId = getGroupId();
        data = null;
        adding = false;
        busy = false;
        picker.reset();
        search.value = "";
        note("");
        select("members");

        if (groupId !== null) {
            load();
        }
    });

    addToggle.addEventListener("click", () => {
        adding = !adding;
        paint();

        if (adding) {
            search.focus();
        }
    });

    search.addEventListener("input", (event) => { note(""); picker.input(event.target.value); });

    addSend.addEventListener("click", async () => {
        const ids = picker.ids();
        const body = await change(() => api.addGroupMembers(groupId, ids));

        if (body) {
            picker.reset();
            search.value = "";
            search.focus();
            note(body.refused?.length > 0 ? `Added ${body.added?.length ?? 0}. ${body.refused.length} couldn't be added.` : "Added.");
        }
    });

    list.addEventListener("click", (event) => {
        const remove = event.target.closest("[data-action=remove]");

        if (remove) {
            // The row is rebuilt, so keep focus in the list: the next Remove, else the one before, else the tab.
            const row = remove.closest("li");
            const sibling = (el) => el?.querySelector("[data-action=remove]")?.dataset.userId;
            const next = sibling(row.nextElementSibling) ?? sibling(row.previousElementSibling);

            change(() => api.removeGroupMember(groupId, Number(remove.dataset.userId))).then((done) => {
                if (done) {
                    const target = next === undefined ? null : list.querySelector(`[data-action=remove][data-user-id="${next}"]`);

                    (target ?? tabs.find((tab) => tab.getAttribute("aria-selected") === "true"))?.focus();
                }
            });
        }
    });

    save.addEventListener("click", () => {
        const text = title.value.trim();

        if (text === "" || text === data?.title) {
            return;
        }

        change(() => api.renameGroup(groupId, text), "Saved.");
    });

    leave.addEventListener("click", async () => {
        if (confirming !== "leave") {
            confirming = "leave";
            paint();

            return;
        }

        if (await change(() => api.leaveGroup(groupId), "", { reload: false })) {
            dialog.querySelector("[data-dialog-close]")?.click();
            onLeft();
        }
    });

    close.addEventListener("click", () => {
        if (confirming !== "close") {
            confirming = "close";
            paint();

            return;
        }

        change(() => api.closeGroup(groupId), "Group closed.");
    });

    return {
        destroy() {
            loads += 1;
            picker.reset();
        },
    };
}
