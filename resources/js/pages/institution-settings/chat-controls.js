/*
|--------------------------------------------------------------------------
| Institution settings, Messages tab: the chat controls
|--------------------------------------------------------------------------
| A picker of every course chat and staff group of the institution, then that
| chat's switches (reactions, replies, forwarding, bump, delete for everyone,
| the lock). Only the institution's administrator is sent any chats; the server
| refuses everyone else. Names and switches only, never a message. Text is set
| with textContent only.
|--------------------------------------------------------------------------
*/

import { createApi } from "../messages/api.js";
import { SWITCHES } from "../messages/settings.js";

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

    const { status, data } = await api.chatControls();
    const chats = status === 200 && Array.isArray(data?.chats) ? data.chats : [];
    let selected = chats[0]?.id ?? null;

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

            paint(Boolean(chat.controls?.[s.name]));

            toggle.addEventListener("click", async () => {
                const next = toggle.getAttribute("aria-checked") !== "true";

                paint(next);
                toggle.disabled = true;
                note.textContent = "";

                const result = await api.setControl(chat.id, s.name, next);

                toggle.disabled = false;

                if (result.status === 200) {
                    chat.controls = { ...(chat.controls ?? {}), ...(result.data?.controls ?? { [s.name]: next }) };
                    paint(Boolean(chat.controls[s.name]));
                } else {
                    paint(!next);
                    note.textContent = "Couldn't change that. Try again.";
                }
            });

            row.append(text, toggle);

            return row;
        }));
    }

    picker.addEventListener("change", () => {
        selected = Number(picker.value);
        render();
    });

    render();
}

initChatControls();
