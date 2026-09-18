/*
|--------------------------------------------------------------------------
| TipTap Toolbar
|--------------------------------------------------------------------------
|
| Connects the toolbar UI to the TipTap editor.
|
*/

import { getEditor } from "./tiptap.js";

import { queueAutosave } from "./reading-autosave.js";

export function initializeToolbar() {

    const editor = getEditor();

    if (!editor) {
        return;
    }

    bindButtons(editor);

    bindStyleSelector(editor);

    bindUndoRedo(editor);

    bindSelectionEvents(editor);

    updateToolbar(editor);

}

/*
|--------------------------------------------------------------------------
| Buttons
|--------------------------------------------------------------------------
*/

function bindButtons(editor) {

    document

        .querySelectorAll("[data-action]")

        .forEach(button => {

            button.addEventListener(

                "click",

                event => {

                    event.preventDefault();

                    const action =
                        button.dataset.action;

                    executeAction(

                        editor,

                        action

                    );

                    updateToolbar(editor);

                }

            );

        });

}

/*
|--------------------------------------------------------------------------
| Style Dropdown
|--------------------------------------------------------------------------
*/

function bindStyleSelector(editor) {

    const select =
        document.querySelector(
            "[data-style-select]"
        );

    if (!select) {
        return;
    }

    select.addEventListener(

        "change",

        () => {

            switch (select.value) {

                case "h1":

                    editor
                        .chain()
                        .focus()
                        .toggleHeading({
                            level: 1,
                        })
                        .run();

                    queueAutosave();

                    break;

                case "h2":

                    editor
                        .chain()
                        .focus()
                        .toggleHeading({
                            level: 2,
                        })
                        .run();

                    queueAutosave();

                    break;

                default:

                    editor
                        .chain()
                        .focus()
                        .setParagraph()
                        .run();

                    queueAutosave();

            }

            updateToolbar(editor);

        }

    );

}

/*
|--------------------------------------------------------------------------
| Undo / Redo
|--------------------------------------------------------------------------
*/

function bindUndoRedo(editor) {

    document

        .querySelector("[data-editor-undo]")

        ?.addEventListener(

            "click",

            () => {

                editor

                    .chain()

                    .focus()

                    .undo()

                    .run();

                queueAutosave();

                updateToolbar(editor);

            }

        );

    document

        .querySelector("[data-editor-redo]")

        ?.addEventListener(

            "click",

            () => {

                editor

                    .chain()

                    .focus()

                    .redo()

                    .run();
                
                queueAutosave();

                updateToolbar(editor);

            }

        );

}

/*
|--------------------------------------------------------------------------
| Editor Events
|--------------------------------------------------------------------------
*/

function bindSelectionEvents(editor) {

    editor.on(

        "selectionUpdate",

        () => updateToolbar(editor)

    );

    editor.on(

        "transaction",

        () => updateToolbar(editor)

    );

}

/*
|--------------------------------------------------------------------------
| Execute Toolbar Action
|--------------------------------------------------------------------------
*/

function executeAction(
    editor,
    action
) {

    const chain =
        editor.chain().focus();

    switch (action) {

        case "bold":

            chain.toggleBold().run();

            queueAutosave();

            break;

        case "italic":

            chain.toggleItalic().run();

            queueAutosave();

            break;

        case "underline":

            chain.toggleUnderline().run();

            queueAutosave();

            break;

        case "justifyLeft":

            chain.setTextAlign("left").run();

            queueAutosave();

            break;

        case "justifyCenter":

            chain.setTextAlign("center").run();

            queueAutosave();

            break;

        case "justifyRight":

            chain.setTextAlign("right").run();

            queueAutosave();

            break;

        case "justifyFull":

            chain.setTextAlign("justify").run();

            queueAutosave();

            break;

        case "insertUnorderedList":

            chain.toggleBulletList().run();

            queueAutosave();

            break;

        case "insertOrderedList":

            chain.toggleOrderedList().run();

            queueAutosave();

            break;

    }

}

/*
|--------------------------------------------------------------------------
| Update Toolbar
|--------------------------------------------------------------------------
*/

function updateToolbar(editor) {

    updateButtons(editor);

    updateStyle(editor);

}

/*
|--------------------------------------------------------------------------
| Active Buttons
|--------------------------------------------------------------------------
*/

function updateButtons(editor) {

    document

        .querySelectorAll("[data-action]")

        .forEach(button => {

            const action =
                button.dataset.action;

            let active =
                false;

            switch (action) {

                case "bold":

                    active =
                        editor.isActive("bold");

                    break;

                case "italic":

                    active =
                        editor.isActive("italic");

                    break;

                case "underline":

                    active =
                        editor.isActive("underline");

                    break;

                case "justifyLeft":

                    active =
                        editor.isActive({
                            textAlign: "left",
                        });

                    break;

                case "justifyCenter":

                    active =
                        editor.isActive({
                            textAlign: "center",
                        });

                    break;

                case "justifyRight":

                    active =
                        editor.isActive({
                            textAlign: "right",
                        });

                    break;

                case "justifyFull":

                    active =
                        editor.isActive({
                            textAlign: "justify",
                        });

                    break;

                case "insertUnorderedList":

                    active =
                        editor.isActive("bulletList");

                    break;

                case "insertOrderedList":

                    active =
                        editor.isActive("orderedList");

                    break;

            }

            button.classList.toggle(
                "bg-[#0040A6]",
                active
            );

            button.classList.toggle(
                "text-white",
                active
            );

            button.classList.toggle(
                "text-gray-500",
                !active
            );

        });

}

/*
|--------------------------------------------------------------------------
| Style Dropdown
|--------------------------------------------------------------------------
*/

function updateStyle(editor) {

    const select =
        document.querySelector(
            "[data-style-select]"
        );

    if (!select) {
        return;
    }

    if (

        editor.isActive(

            "heading",

            {

                level: 1,

            }

        )

    ) {

        select.value =
            "h1";

        return;

    }

    if (

        editor.isActive(

            "heading",

            {

                level: 2,

            }

        )

    ) {

        select.value =
            "h2";

        return;

    }

    select.value =
        "p";

}