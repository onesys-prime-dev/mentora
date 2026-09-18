import { Editor } from "@tiptap/core";

import { extensions } from "./extensions.js";

import {

    parseInstructions,

} from "./parser.js";

let editor = null;

export function initializeTipTap() {

    const element =
        document.querySelector("#instructions-editor");

    if (!element) {
        return;
    }

    let content = {};

    try {

        content = JSON.parse(
            element.dataset.instructions ?? "{}"
        );

    }
    catch (e) {

        console.error(
            "Invalid instructions JSON",
            e
        );

    }

    if (editor) {

        editor.destroy();

        editor = null;

    }

    editor = new Editor({

        element,

        extensions,

        content: parseInstructions(content),

        editorProps: {

            attributes: {

                class:
                    "instructions-content min-h-[200px] focus:outline-none",

            },

        },

    });

    element.addEventListener(
        "click",
        (event) => {

            // If the click is already inside ProseMirror,
            // let TipTap handle it normally.
            if (event.target.closest(".ProseMirror")) {
                return;
            }

            editor
                .chain()
                .focus()
                .run();

        }
    );

    return editor;

}

export function getEditor() {

    return editor;

}

export function resetContent() {

    const element =
        document.querySelector("#instructions-editor");

    if (!element || !editor) {
        return;
    }

    let content = {};

    try {

        content = JSON.parse(
            element.dataset.instructions ?? "{}"
        );

    }
    catch (e) {

        console.error(
            "Invalid instructions JSON",
            e
        );

    }

    editor
        .commands
        .setContent(
            parseInstructions(content)
        );

}
