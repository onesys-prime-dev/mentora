/*
|--------------------------------------------------------------------------
| Composer toolbar — same [data-action]/[data-style-select] convention as
| the assessments instructions toolbar, bound to this composer's editor.
|--------------------------------------------------------------------------
*/

import { getComposerEditor } from "./tiptap.js";

export function initializeComposerToolbar(root) {

    const editor = getComposerEditor();

    if (!editor) {
        return;
    }

    root.querySelectorAll("[data-action]").forEach((button) => {
        button.addEventListener("click", (event) => {
            event.preventDefault();
            executeAction(editor, button.dataset.action);
            updateButtons(root, editor);
        });
    });

    editor.on("selectionUpdate", () => updateButtons(root, editor));
    editor.on("transaction", () => updateButtons(root, editor));

    updateButtons(root, editor);

}

function executeAction(editor, action) {

    const chain = editor.chain().focus();

    switch (action) {
        case "bold": chain.toggleBold().run(); break;
        case "italic": chain.toggleItalic().run(); break;
        case "underline": chain.toggleUnderline().run(); break;
        case "justifyLeft": chain.setTextAlign("left").run(); break;
        case "justifyCenter": chain.setTextAlign("center").run(); break;
        case "justifyRight": chain.setTextAlign("right").run(); break;
        case "insertUnorderedList": chain.toggleBulletList().run(); break;
        case "insertOrderedList": chain.toggleOrderedList().run(); break;
    }

}

function updateButtons(root, editor) {

    root.querySelectorAll("[data-action]").forEach((button) => {

        const map = {
            bold: "bold",
            italic: "italic",
            underline: "underline",
            insertUnorderedList: "bulletList",
            insertOrderedList: "orderedList",
        };

        const key = map[button.dataset.action];

        const active = key
            ? editor.isActive(key)
            : button.dataset.action === "justifyLeft" ? editor.isActive({ textAlign: "left" })
            : button.dataset.action === "justifyCenter" ? editor.isActive({ textAlign: "center" })
            : button.dataset.action === "justifyRight" ? editor.isActive({ textAlign: "right" })
            : false;

        button.classList.toggle("bg-[#0040A6]", active);
        button.classList.toggle("text-white", active);
        button.classList.toggle("text-gray-500", !active);

    });

}
