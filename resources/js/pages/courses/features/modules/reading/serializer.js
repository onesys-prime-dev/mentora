/*
|--------------------------------------------------------------------------
| Reading Serializer
|--------------------------------------------------------------------------
|
| Converts TipTap JSON into Mentora Reading JSON.
|
*/

import { getEditor } from "./tiptap.js";

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

export function serializeReading() {

    const editor = getEditor();

    if (!editor) {

        return {

            version: 1,

            blocks: [],

        };

    }

    const document =
        editor.getJSON();

    return {

        version: 1,

        blocks:

            (document.content ?? [])

                .map(
                    serializeBlock
                )

                .filter(Boolean),

    };

}

/*
|--------------------------------------------------------------------------
| Blocks
|--------------------------------------------------------------------------
*/

function serializeBlock(
    block,
    index
) {

    const id =
        `block_${String(index + 1).padStart(3, "0")}`;

    switch (block.type) {

        case "heading":

            return {

                id,

                type:
                    "heading",

                level:
                    block.attrs?.level ?? 2,

                align:
                    block.attrs?.textAlign ?? "left",

                content:
                    serializeInline(
                        block.content
                    ),

            };

        case "paragraph":

            return {

                id,

                type:
                    "paragraph",

                align:
                    block.attrs?.textAlign ?? "left",

                content:
                    serializeInline(
                        block.content
                    ),

            };

        case "bulletList":

            return {

                id,

                type:
                    "unordered-list",

                align: "left",

                items:

                    (block.content ?? []).map(

                        item =>

                            serializeInline(
                                item.content?.[0]?.content
                            )

                    ),

            };

        case "orderedList":

            return {

                id,

                type:
                    "ordered-list",

                align: "left",

                items:

                    (block.content ?? []).map(

                        item =>

                            serializeInline(
                                item.content?.[0]?.content
                            )

                    ),

            };

        default:

            return null;

    }

}

/*
|--------------------------------------------------------------------------
| Inline Content
|--------------------------------------------------------------------------
*/

function serializeInline(
    nodes = []
) {

    return nodes.map(node => {

        if (
            node.type !== "text"
        ) {

            return null;

        }

        return {

            text:
                node.text ?? "",

            ...(node.marks?.length && {

                marks:

                    node.marks

                        .map(
                            serializeMark
                        )

                        .filter(Boolean),

            }),

        };

    })

    .filter(Boolean);

}

/*
|--------------------------------------------------------------------------
| Marks
|--------------------------------------------------------------------------
*/

function serializeMark(
    mark
) {

    switch (
        mark.type
    ) {

        case "bold":

            return "bold";

        case "italic":

            return "italic";

        case "underline":

            return "underline";

        default:

            return null;

    }

}