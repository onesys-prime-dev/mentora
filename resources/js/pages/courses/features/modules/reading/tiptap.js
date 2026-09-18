import { Editor } from "@tiptap/core";

import { extensions } from "./extensions.js";

import {

    parseReading,

} from "./parser.js";

let editor = null;

export function initializeTipTap() {

    const element =
        document.querySelector("#reading-editor");

    if (!element) {
        return;
    }

    let content = {};

    try {

        content = JSON.parse(
            element.dataset.reading ?? "{}"
        );

    }
    catch (e) {

        console.error(
            "Invalid reading JSON",
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

        content: parseReading(content),

        editorProps: {

            attributes: {

                class:
                    "reading-content min-h-[300px] focus:outline-none",

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