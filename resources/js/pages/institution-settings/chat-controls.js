/*
|--------------------------------------------------------------------------
| Institution settings, Messages tab: the chat controls
|--------------------------------------------------------------------------
| A picker of every course chat and staff group of the institution, then that
| chat's switches (reactions, replies, forwarding, bump, delete for everyone,
| the lock). The switches are a draft: nothing is sent until the page's Save
| button is pressed, which saves them together with the students setting and
| says what changed. Only the institution's administrator is sent any chats; the
| server refuses everyone else. Names and switches only, never a message. Text is set
| with textContent only.
|--------------------------------------------------------------------------
*/

import { showToast } from "../../shared/toast.js";
import { createApi } from "../messages/api.js";
import { SWITCHES } from "../messages/chat-switches.js";

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

export async function initChatControls(root = document.querySelector("[data-chat-controls]"), api = root ? createApi(root.dataset.api) : null) {
    if (!root) {
        return;
    }

    const picker = root.querySelector("[data-role=controls-picker]");
    const list = root.querySelector("[data-role=controls-list]");
    const empty = root.querySelector("[data-role=controls-empty]");
    const note = root.querySelector("[data-role=controls-note]");

    if (![picker, list, empty, note].every(Boolean)) {
        return;
    }

    const form = root.closest("form");
    const radios = form ? [...form.querySelectorAll("input[name=enabled]")] : [];
    const checkedRadio = () => radios.find((r) => r.checked)?.value ?? null;
    // What the server rendered, not what is ticked once the chats arrive: a choice made while they load still counts as a change.
    let studentsAtStart = radios.find((r) => r.defaultChecked)?.value ?? null;

    const { status, data } = await api.chatControls();
    const chats = (status === 200 && Array.isArray(data?.chats) ? data.chats : []).map((c) => ({ ...c, draft: { ...(c.controls ?? {}) } }));
    let selected = chats[0]?.id ?? null;
    let saving = false;

    /** Every switch whose draft differs from what is saved. */
    const pending = () => chats.flatMap((chat) => SWITCHES
        .filter((s) => Boolean(chat.draft[s.name]) !== Boolean(chat.controls?.[s.name]))
        .map((s) => ({ chat, s, on: Boolean(chat.draft[s.name]) })));
    const studentsChanged = () => studentsAtStart !== null && checkedRadio() !== studentsAtStart;

    function render() {
        const chat = chats.find((c) => c.id === selected) ?? null;

        note.textContent = "";
        picker.hidden = chats.length === 0;
        picker.replaceChildren(...chats.map((c) => {
            const option = node("option", "", `${c.name} · ${c.type === "course" ? "course chat" : "staff group"}`);

            option.value = String(c.id);
            option.selected = c.id === selected;

            return option;
        }));

        empty.hidden = chat !== null;

        if (!chat) {
            list.replaceChildren();

            return;
        }

        list.replaceChildren(...SWITCHES.filter((s) => !s.courseOnly || chat.type === "course").map((s) => {
            const row = node("div", "flex items-center justify-between gap-3 border-b border-gray-100 py-2");
            const text = node("div", "min-w-0");
            const toggle = node("button", "relative h-[19px] w-[34px] flex-none rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50");
            const knob = node("span", "absolute left-[2px] top-[2px] h-[15px] w-[15px] rounded-full bg-white transition-transform");

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

            paint(Boolean(chat.draft[s.name]));

            // Only the draft changes here; Save sends it.
            toggle.addEventListener("click", () => {
                const next = toggle.getAttribute("aria-checked") !== "true";

                chat.draft[s.name] = next;
                paint(next);
                note.textContent = "";
            });

            row.append(text, toggle);

            return row;
        }));
    }

    // Save: the controls go first, then the students setting if that changed too. Each says what it did.
    async function saveControls() {
        const changes = pending();
        const failed = [];

        for (const { chat, s, on } of changes) {
            const result = await api.setControl(chat.id, s.name, on);

            if (result.status === 200) {
                chat.controls = { ...(chat.controls ?? {}), ...(result.data?.controls ?? { [s.name]: on }) };
                chat.draft = { ...chat.controls };
            } else {
                chat.draft[s.name] = Boolean(chat.controls?.[s.name]);
                failed.push({ chat, s });
            }
        }

        const saved = changes.length - failed.length;

        if (saved === 1) {
            const one = changes.find(({ chat, s }) => !failed.some((f) => f.chat === chat && f.s === s));

            showToast(`${one.s.label} is now ${one.on ? "on" : "off"} in ${one.chat.name}.`, { tone: "success" });
        } else if (saved > 1) {
            showToast(`${saved} chat settings saved.`, { tone: "success" });
        }

        if (failed.length > 0) {
            showToast(`Couldn't save ${failed.length} chat ${failed.length === 1 ? "setting" : "settings"}. Try again.`, { tone: "danger" });
        }

        render();
    }

    if (form) {
        // Capture phase: runs before the form's own save, so the two can be told apart.
        form.addEventListener("submit", (event) => {
            if (saving) {
                return;
            }

            const controlsChanged = pending().length > 0;

            if (!controlsChanged && studentsChanged()) {
                return; // only the students setting: the form saves it as always
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if (!controlsChanged) {
                showToast("Nothing to save.", { tone: "info" });

                return;
            }

            saving = true;

            saveControls().then(() => {
                saving = false;

                if (studentsChanged()) {
                    saving = true;
                    form.requestSubmit();
                    saving = false;
                }
            });
        }, true);
    }

    // Once the students setting has gone to the server, that is the new starting point for "changed".
    form?.addEventListener("submit", () => {
        studentsAtStart = checkedRadio();
    });

    picker.addEventListener("change", () => {
        selected = Number(picker.value);
        render();
    });

    render();
}

initChatControls();
