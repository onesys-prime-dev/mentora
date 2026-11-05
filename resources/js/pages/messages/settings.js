/*
|--------------------------------------------------------------------------
| Messages — Settings dialog
|--------------------------------------------------------------------------
| "My alerts": the email and quiet-hours form (messages.js), and the people the
| person has blocked, with Unblock. Chat controls are not here: the institution
| administrator sets them in Institution settings, Messages.
| Text is set with textContent only.
|--------------------------------------------------------------------------
*/

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
 * @param {{root: HTMLElement, api: object}} deps
 * @returns {{sync: () => void}}
 */
export function createSettings({ root, api }) {
    const $ = (selector) => root.querySelector(selector);
    const blockedList = $("[data-role=blocked-list]");
    const blockedEmpty = $("[data-role=blocked-empty]");
    const blockedCount = $("[data-role=blocked-count]");

    // A page without the Settings markup (older fixtures, a trimmed view) simply has nothing to fill.
    if (![blockedList, blockedEmpty, blockedCount].every(Boolean)) {
        return { sync() {} };
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
    function sync() {
        renderBlocked();
    }

    return { sync };
}
