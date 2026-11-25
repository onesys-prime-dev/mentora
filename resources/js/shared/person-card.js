/**
 * A person's avatar as a way into their profile.
 *
 *   <button data-person-avatar="7" data-person-name="Maria" aria-haspopup="dialog" aria-label="Open Maria's profile">
 *     hover or keyboard focus (after 300 ms)  → a small card (GET /people/7/card)
 *     click, Enter, Space, "See more", a tap  → the profile in #person-dialog (GET /people/7/panel)
 *
 * The fragments are server-rendered by our own endpoints, so they go in as HTML.
 * Each is fetched once per person per page; a failed fetch is not remembered.
 * Escape closes the card with focus kept on the avatar; the modal (shared/dialog.js)
 * traps focus and gives it back to the avatar.
 */

import { createDialog } from "./dialog.js";

const HOVER_DELAY = 300;
const LEAVE_DELAY = 200;
const FAILED = "Couldn't load this profile. Try again.";
const GONE = "This profile isn't available";
const SKELETON = '<div class="flex w-[19rem] max-w-full animate-pulse flex-col gap-3 p-4" aria-hidden="true"><div class="h-14 w-14 rounded-full bg-gray-200"></div><div class="h-3 w-2/3 rounded bg-gray-200"></div><div class="h-3 w-1/2 rounded bg-gray-200"></div></div>';

const make = (tag, attrs = {}, text = "") => {
    const el = document.createElement(tag);

    for (const [name, value] of Object.entries(attrs)) {
        el.setAttribute(name, value);
    }

    el.textContent = text;

    return el;
};

/** @returns {() => void} a function that removes everything it set up */
export function initPersonCard() {
    const cache = { card: new Map(), panel: new Map() };
    const dialogEl = document.getElementById("person-dialog");
    const dialog = dialogEl ? createDialog(dialogEl) : null;
    const status = make("div", { role: "status", class: "sr-only" });

    let pop = null;
    let popAvatar = null;
    let popRun = 0;
    let modalRun = 0;
    let modalId = null;
    let openTimer = null;
    let closeTimer = null;
    let touch = false;
    let returning = false;

    document.body.append(status);

    async function load(kind, id) {
        if (cache[kind].has(id)) {
            return { html: cache[kind].get(id) };
        }

        try {
            const response = await fetch(`/people/${id}/${kind}`, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } });

            // An expired session lands on /login: never cache or show that page, take the whole page there.
            if (response.redirected) {
                window.location.assign(response.url);

                return { redirected: true };
            }

            if (response.status === 404) {
                return { gone: true };
            }

            if (!response.ok) {
                return { error: true };
            }

            const html = await response.text();

            cache[kind].set(id, html);

            return { html };
        } catch {
            return { error: true };
        }
    }

    // Puts a result into a container: the fragment, or a message (with Retry unless the profile is gone).
    function render(container, kind, result) {
        if (result.redirected) {
            return;
        }

        container.removeAttribute("aria-busy");

        if (result.html !== undefined) {
            container.innerHTML = result.html;

            return;
        }

        const box = make("div", { class: "flex w-[19rem] max-w-full flex-col items-start gap-3 p-4 text-sm text-[#3D3D3D]" });

        box.append(make("p", {}, result.gone ? GONE : FAILED));

        if (!result.gone) {
            box.append(make("button", { type: "button", "data-person-retry": kind, class: "rounded-full border border-brand/30 px-4 py-2 text-sm font-semibold text-brand hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11" }, "Retry"));
        }

        container.replaceChildren(box);
    }

    function place() {
        if (!pop || !popAvatar) {
            return;
        }

        const a = popAvatar.getBoundingClientRect();
        const h = pop.offsetHeight;
        const w = pop.offsetWidth;
        const below = window.innerHeight - a.bottom >= h + 12 || a.top < h + 12;
        const left = Math.max(8, Math.min(a.left + a.width / 2 - w / 2, window.innerWidth - w - 8));

        pop.style.left = `${left}px`;
        pop.style.top = `${below ? a.bottom + 10 : a.top - h - 10}px`;
        pop.firstElementChild.style.left = `${Math.max(12, Math.min(a.left + a.width / 2 - left - 6, w - 24))}px`;
        pop.firstElementChild.style.top = below ? "-6px" : "auto";
        pop.firstElementChild.style.bottom = below ? "auto" : "-6px";
    }

    function closePopover({ refocus = false } = {}) {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        popRun++;

        const avatar = popAvatar;
        const hadFocus = pop?.contains(document.activeElement);

        pop?.remove();
        pop = null;
        popAvatar = null;

        if (refocus && hadFocus) {
            avatar?.focus();
        }
    }

    async function openPopover(avatar) {
        if (popAvatar === avatar) {
            return;
        }

        closePopover();

        const id = avatar.dataset.personAvatar;
        const run = ++popRun;

        popAvatar = avatar;
        pop = make("div", { "data-person-popover": id, role: "dialog", "aria-label": `${avatar.dataset.personName || (avatar.getAttribute("aria-label") ?? "").replace(/^Open (.*)'s profile$/, "$1") || "Profile"}, profile summary`, "aria-busy": "true", class: "fixed z-40 rounded-2xl bg-white shadow-lg ring-1 ring-black/5" });
        pop.append(make("span", { "aria-hidden": "true", class: "absolute h-3 w-3 rotate-45 bg-white ring-1 ring-black/5" }));

        const content = make("div", { class: "relative rounded-2xl bg-white" });

        content.innerHTML = SKELETON;
        pop.append(content);
        document.body.append(pop);
        place();

        const result = await load("card", id);

        if (run !== popRun) {
            return;
        }

        render(content, "card", result);
        pop.removeAttribute("aria-busy");
        place();
    }

    async function openModal(id, opener) {
        if (!dialog) {
            return;
        }

        closePopover();
        returning = true;
        modalId = id;

        const body = dialogEl.querySelector("[data-dialog-body]");
        const run = ++modalRun;

        body.innerHTML = SKELETON;
        body.setAttribute("aria-busy", "true");
        dialog.open(opener);

        const result = await load("panel", id);

        if (run === modalRun) {
            render(body, "panel", result);

            // A Retry that re-rendered itself away leaves focus nowhere.
            if (!dialogEl.contains(document.activeElement)) {
                (body.querySelector("a[href], button") ?? dialogEl.querySelector("[data-dialog-panel]")).focus();
            }
        }
    }

    const avatarOf = (event) => (event.target instanceof Element ? event.target.closest("[data-person-avatar]") : null);
    const isMouse = (event) => event.pointerType === "mouse";
    const schedule = (avatar) => {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        openTimer = setTimeout(() => openPopover(avatar), HOVER_DELAY);
    };
    const scheduleClose = () => {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        closeTimer = setTimeout(() => closePopover(), LEAVE_DELAY);
    };
    const inPop = (event) => pop && event.target instanceof Element && pop.contains(event.target);

    const on = {
        pointerdown: (event) => {
            touch = event.pointerType === "touch";
        },
        pointerover: (event) => {
            if (!isMouse(event)) {
                return;
            }

            if (inPop(event)) {
                clearTimeout(closeTimer);
            } else if (avatarOf(event)) {
                schedule(avatarOf(event));
            }
        },
        pointerout: (event) => {
            if (isMouse(event) && (inPop(event) || avatarOf(event))) {
                scheduleClose();
            }
        },
        focusin: (event) => {
            if (returning && avatarOf(event)) {
                returning = false;

                return;
            }

            if (avatarOf(event) && !touch && !dialog?.isOpen()) {
                schedule(avatarOf(event));
            }
        },
        focusout: (event) => {
            const to = event.relatedTarget;

            if ((avatarOf(event) || inPop(event)) && !pop?.contains(to) && to !== popAvatar) {
                scheduleClose();
            }
        },
        keydown: (event) => {
            if (!pop) {
                return;
            }

            const controls = [...pop.querySelectorAll("a[href], button:not([disabled])")];

            if (event.key === "Escape") {
                const avatar = popAvatar;
                const had = pop.contains(document.activeElement) || document.activeElement === avatar;

                closePopover();

                if (had) {
                    returning = document.activeElement !== avatar;
                    avatar?.focus();
                }
            } else if (event.key === "Tab" && event.target === popAvatar && !event.shiftKey && controls.length) {
                event.preventDefault();
                clearTimeout(closeTimer);
                controls[0].focus();
            } else if (event.key === "Tab" && inPop(event) && (event.shiftKey ? event.target === controls[0] : event.target === controls.at(-1))) {
                // Back to the avatar; going forward, from the avatar Tab carries on to the next thing on the page.
                const avatar = popAvatar;

                if (event.shiftKey) {
                    event.preventDefault();
                    avatar?.focus();

                    return;
                }

                closePopover();
                returning = document.activeElement !== avatar;
                avatar?.focus();
            }
        },
        scroll: () => closePopover(),
        resize: () => closePopover(),
        click: (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const retry = target?.closest("[data-person-retry]");
            const copy = target?.closest("[data-profile-copy-email]");
            const see = target?.closest("[data-person-panel]");
            const avatar = avatarOf(event);

            if (retry) {
                if (retry.dataset.personRetry === "card" && popAvatar) {
                    const avatarNow = popAvatar;

                    popAvatar = null;
                    openPopover(avatarNow);
                } else if (retry.dataset.personRetry === "panel" && modalId) {
                    openModal(modalId, document.activeElement);
                }
            } else if (copy) {
                const failed = () => {
                    status.textContent = "Couldn't copy the email";
                };
                const label = copy.textContent;

                Promise.resolve(navigator.clipboard?.writeText(copy.dataset.profileCopyEmail)).then(
                    () => {
                        if (!navigator.clipboard) {
                            return failed();
                        }

                        copy.textContent = "Copied";
                        status.textContent = "Email copied";
                        setTimeout(() => {
                            copy.textContent = label;
                        }, 2000);
                    },
                    failed,
                );
            } else if (see) {
                if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                    return;
                }

                event.preventDefault();
                openModal(see.dataset.personPanel, popAvatar ?? see);
            } else if (avatar) {
                openModal(avatar.dataset.personAvatar, avatar);
            }

            touch = false;
        },
    };

    const onClose = () => setTimeout(() => {
        returning = false;
    }, 0);

    const onWindow = ["scroll", "resize"];

    for (const [type, handler] of Object.entries(on)) {
        (onWindow.includes(type) ? window : document).addEventListener(type, handler, type === "scroll");
    }

    dialogEl?.addEventListener("dialog:close", onClose);

    return () => {
        closePopover();
        status.remove();

        for (const [type, handler] of Object.entries(on)) {
            (onWindow.includes(type) ? window : document).removeEventListener(type, handler, type === "scroll");
        }

        dialogEl?.removeEventListener("dialog:close", onClose);
    };
}
