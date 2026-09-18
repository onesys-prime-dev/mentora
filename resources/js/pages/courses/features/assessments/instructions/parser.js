/*
|--------------------------------------------------------------------------
| Instructions Parser
|--------------------------------------------------------------------------
|
| Converts Mentora Instructions JSON
| into TipTap JSON.
|
*/

export function parseInstructions(content = {}) {

    return {

        type: "doc",

        content:

            (content.blocks ?? [])

                .map(parseBlock)

                .filter(Boolean),

    };

}

function parseBlock(block) {

    switch (block.type) {

        case "heading":

            return {

                type: "heading",

                attrs: {

                    level:
                        block.level ?? 2,

                    textAlign:
                        block.align ?? "left",

                },

                content:
                    parseInline(
                        block.content
                    ),

            };

        case "paragraph":

            return {

                type: "paragraph",

                attrs: {

                    textAlign:
                        block.align ?? "left",

                },

                content:
                    parseInline(
                        block.content
                    ),

            };

        case "unordered-list":

            return {

                type: "bulletList",

                content:

                    (block.items ?? []).map(

                        item => ({

                            type: "listItem",

                            content: [

                                {

                                    type: "paragraph",

                                    content:

                                        parseInline(item),

                                },

                            ],

                        })

                    ),

            };

        case "ordered-list":

            return {

                type: "orderedList",

                content:

                    (block.items ?? []).map(

                        item => ({

                            type: "listItem",

                            content: [

                                {

                                    type: "paragraph",

                                    content:

                                        parseInline(item),

                                },

                            ],

                        })

                    ),

            };

        default:

            return null;

    }

}

function parseInline(nodes = []) {

    return nodes.map(node => {

        const marks = [];

        (node.marks ?? []).forEach(mark => {

            switch (mark) {

                case "bold":

                    marks.push({

                        type: "bold",

                    });

                    break;

                case "italic":

                    marks.push({

                        type: "italic",

                    });

                    break;

                case "underline":

                    marks.push({

                        type: "underline",

                    });

                    break;

            }

        });

        return {

            type: "text",

            text:
                node.text ?? "",

            ...(marks.length && {

                marks,

            }),

        };

    });

}
