/*
|--------------------------------------------------------------------------
| Composer serializer — converts the composer's own TipTap JSON into the
| same {version, blocks} shape the instructions feature uses (parser.js is
| shared), but reading the editor passed in rather than a different
| module's getEditor(), since this is a separate TipTap instance.
|--------------------------------------------------------------------------
*/

export function serializeMessage(editor) {

    if (!editor) {
        return { version: 1, blocks: [] };
    }

    const document = editor.getJSON();

    return {
        version: 1,
        blocks: (document.content ?? []).map(serializeBlock).filter(Boolean),
    };

}

function serializeBlock(block, index) {

    const id = `block_${String(index + 1).padStart(3, "0")}`;

    switch (block.type) {

        case "heading":
            return { id, type: "heading", level: block.attrs?.level ?? 2, align: block.attrs?.textAlign ?? "left", content: serializeInline(block.content) };

        case "paragraph":
            return { id, type: "paragraph", align: block.attrs?.textAlign ?? "left", content: serializeInline(block.content) };

        case "bulletList":
            return { id, type: "unordered-list", align: "left", items: (block.content ?? []).map((item) => serializeInline(item.content?.[0]?.content)) };

        case "orderedList":
            return { id, type: "ordered-list", align: "left", items: (block.content ?? []).map((item) => serializeInline(item.content?.[0]?.content)) };

        default:
            return null;

    }

}

function serializeInline(nodes = []) {

    return nodes.map((node) => {

        if (node.type !== "text") {
            return null;
        }

        return {
            text: node.text ?? "",
            ...(node.marks?.length && { marks: node.marks.map(serializeMark).filter(Boolean) }),
        };

    }).filter(Boolean);

}

function serializeMark(mark) {

    switch (mark.type) {
        case "bold": return "bold";
        case "italic": return "italic";
        case "underline": return "underline";
        default: return null;
    }

}

/** Plain-text extraction, used to decide if the composer has content (for the x/trash swap) and for client-side validation. */
export function plainTextFrom(editor) {

    if (!editor) {
        return "";
    }

    return editor.getText().trim();

}
