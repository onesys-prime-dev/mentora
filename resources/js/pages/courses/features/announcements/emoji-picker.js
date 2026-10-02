/*
|--------------------------------------------------------------------------
| Searchable emoji picker ("more reactions" / composer emoji button) —
| emoji-mart's vanilla Picker, one shared instance reused across triggers.
|--------------------------------------------------------------------------
*/

import { Picker } from "emoji-mart";
import data from "@emoji-mart/data";

/**
 * Mounts (once) a picker inside `container` and calls `onSelect(emojiChar)`
 * when something is picked. Returns a toggle(show) function.
 */
export function mountEmojiPicker(container, onSelect) {

    if (container.dataset.mounted === "1") {
        return;
    }

    const picker = new Picker({
        data,
        onEmojiSelect: (emoji) => {
            onSelect(emoji.native);
            container.classList.add("hidden");
        },
        theme: "light",
        previewPosition: "none",
    });

    container.appendChild(picker);
    container.dataset.mounted = "1";

}
