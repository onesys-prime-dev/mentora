/*
|--------------------------------------------------------------------------
| Messages — reaction chips
|--------------------------------------------------------------------------
| The row of emoji chips under a message bubble. A click toggles the
| viewer's own reaction; a long press or right click asks who reacted.
| Text is set with textContent only.
|--------------------------------------------------------------------------
*/

/**
 * @param {{id: number, reactions?: Array<{emoji: string, count: number, mine?: boolean}>}} message
 * @param {{onToggle?: Function, onShowWho?: Function}} [handlers]
 * @returns {HTMLElement|null}
 */
export function reactionChips(message, { onToggle, onShowWho } = {}) {
    const reactions = message?.reactions ?? [];

    if (reactions.length === 0) {
        return null;
    }

    const row = document.createElement("div");

    row.className = `absolute -bottom-2.5 ${message.mine ? "right-4" : "right-1"} flex items-center gap-0.5 rounded-full border border-gray-200 bg-white px-1 py-px text-gray-700 shadow-sm`;
    row.dataset.reactions = "";

    reactions.forEach((reaction) => {
        const chip = document.createElement("button");
        const count = Number(reaction.count) || 0;

        chip.type = "button";
        chip.dataset.emoji = reaction.emoji;
        chip.className = `rounded-full px-0.5 py-0 text-[14px] leading-5 transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 ${reaction.mine ? "bg-brand-tint font-semibold text-brand-dark" : ""}`;
        chip.setAttribute("aria-pressed", reaction.mine ? "true" : "false");
        chip.setAttribute("aria-label", `${count} ${count === 1 ? "reaction" : "reactions"}, ${reaction.emoji}.${reaction.mine ? " You reacted" : ""}`);
        chip.textContent = count > 1 ? `${reaction.emoji} ${count}` : reaction.emoji;

        chip.addEventListener("click", () => onToggle?.(message, reaction.emoji));
        chip.addEventListener("contextmenu", (event) => {
            event.preventDefault();
            onShowWho?.(message, reaction.emoji);
        });

        row.append(chip);
    });

    return row;
}
