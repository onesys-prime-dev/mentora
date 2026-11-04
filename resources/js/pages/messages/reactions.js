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

    row.className = "mt-1 flex flex-wrap gap-1";
    row.dataset.reactions = "";

    reactions.forEach((reaction) => {
        const chip = document.createElement("button");
        const count = Number(reaction.count) || 0;

        chip.type = "button";
        chip.dataset.emoji = reaction.emoji;
        chip.className = `rounded-full border px-2 py-0.5 text-[12px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 ${reaction.mine ? "border-brand bg-brand-tint text-brand-dark" : "border-gray-200 bg-white text-gray-700 hover:bg-gray-50"}`;
        chip.setAttribute("aria-pressed", reaction.mine ? "true" : "false");
        chip.setAttribute("aria-label", `${count} ${count === 1 ? "reaction" : "reactions"}, ${reaction.emoji}.${reaction.mine ? " You reacted" : ""}`);
        chip.textContent = `${reaction.emoji} ${count}`;

        chip.addEventListener("click", () => onToggle?.(message, reaction.emoji));
        chip.addEventListener("contextmenu", (event) => {
            event.preventDefault();
            onShowWho?.(message, reaction.emoji);
        });

        row.append(chip);
    });

    return row;
}
