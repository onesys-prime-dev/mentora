/*
|--------------------------------------------------------------------------
| Announcement composer rich text — same generic TipTap setup already
| used for assessment instructions (resources/js/.../assessments/
| instructions/tiptap.js), just pointed at the composer's own element and
| reusing that feature's parser/serializer (the {version,blocks} shape is
| generic rich text, not instructions-specific).
|--------------------------------------------------------------------------
*/

import { Editor } from "@tiptap/core";

import { extensions } from "./extensions.js";

import {
    parseInstructions as parseMessage,
} from "../../assessments/instructions/parser.js";

let editor = null;

export function initializeComposerEditor(elementId = "announcement-editor") {

    const element = document.getElementById(elementId);

    if (!element) {
        return null;
    }

    let content = {};

    try {
        content = JSON.parse(element.dataset.message ?? "{}");
    } catch (e) {
        console.error("Invalid announcement message JSON", e);
    }

    if (editor) {
        editor.destroy();
        editor = null;
    }

    editor = new Editor({
        element,
        extensions,
        content: parseMessage(content),
        editorProps: {
            attributes: {
                class: "announcement-composer-content min-h-[80px] focus:outline-none",
            },
        },
    });

    element.addEventListener("click", (event) => {
        if (event.target.closest(".ProseMirror")) {
            return;
        }
        editor.chain().focus().run();
    });

    return editor;

}

export function getComposerEditor() {
    return editor;
}

export function resetComposerEditor() {

    if (!editor) {
        return;
    }

    editor.commands.setContent(parseMessage({}));

}

export function destroyComposerEditor() {

    if (editor) {
        editor.destroy();
        editor = null;
    }

}
