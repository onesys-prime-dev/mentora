import StarterKit from "@tiptap/starter-kit";

import TextAlign from "@tiptap/extension-text-align";

import Placeholder from "@tiptap/extension-placeholder";

export const extensions = [

    StarterKit.configure({

        heading: {

            levels: [1, 2],

        },

    }),

    TextAlign.configure({

        types: [

            "heading",

            "paragraph",

        ],

    }),

    Placeholder.configure({

        placeholder: "Start writing...",

    }),

];