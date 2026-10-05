import { beforeEach, describe, expect, it, vi } from "vitest";

/*
 * Reading content (Modules) and assessment instructions share one stored
 * shape ({version, blocks}) and one TipTap mapping, in two copies of the same
 * code. Both are tested against the same cases, so they cannot drift apart,
 * and the PHP side that renders this JSON (announcement/reading views) keeps
 * receiving what it expects.
 */

const editor = { current: null };

vi.mock("../../../resources/js/pages/courses/features/modules/reading/tiptap.js", () => ({ getEditor: () => editor.current }));
vi.mock("../../../resources/js/pages/courses/features/assessments/instructions/tiptap.js", () => ({ getEditor: () => editor.current }));

import { parseReading } from "../../../resources/js/pages/courses/features/modules/reading/parser.js";
import { serializeReading } from "../../../resources/js/pages/courses/features/modules/reading/serializer.js";
import { parseInstructions } from "../../../resources/js/pages/courses/features/assessments/instructions/parser.js";
import { serializeInstructions } from "../../../resources/js/pages/courses/features/assessments/instructions/serializer.js";

const copies = [
    ["reading", parseReading, serializeReading],
    ["instructions", parseInstructions, serializeInstructions],
];

beforeEach(() => {
    editor.current = null;
});

const text = (t, marks) => ({ text: t, ...(marks ? { marks } : {}) });

describe.each(copies)("%s parser (stored JSON -> TipTap)", (_name, parse) => {
    it("turns nothing into an empty document", () => {
        expect(parse()).toEqual({ type: "doc", content: [] });
        expect(parse({})).toEqual({ type: "doc", content: [] });
        expect(parse({ blocks: [] })).toEqual({ type: "doc", content: [] });
    });

    it("maps a paragraph with its alignment, defaulting to left", () => {
        const doc = parse({ blocks: [
            { type: "paragraph", align: "center", content: [text("Hi")] },
            { type: "paragraph", content: [text("Plain")] },
        ] });

        expect(doc.content).toEqual([
            { type: "paragraph", attrs: { textAlign: "center" }, content: [{ type: "text", text: "Hi" }] },
            { type: "paragraph", attrs: { textAlign: "left" }, content: [{ type: "text", text: "Plain" }] },
        ]);
    });

    it("maps a heading with its level, defaulting to 2", () => {
        const doc = parse({ blocks: [
            { type: "heading", level: 1, align: "right", content: [text("Big")] },
            { type: "heading", content: [text("Default")] },
        ] });

        expect(doc.content.map((b) => [b.attrs.level, b.attrs.textAlign])).toEqual([[1, "right"], [2, "left"]]);
    });

    it("maps both kinds of list, one paragraph per item", () => {
        const doc = parse({ blocks: [
            { type: "unordered-list", items: [[text("a")], [text("b")]] },
            { type: "ordered-list", items: [[text("one")]] },
        ] });

        expect(doc.content[0]).toEqual({
            type: "bulletList",
            content: [
                { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "a" }] }] },
                { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "b" }] }] },
            ],
        });
        expect(doc.content[1].type).toBe("orderedList");
        expect(doc.content[1].content).toHaveLength(1);
    });

    it("keeps the three known marks, in order, and drops unknown ones", () => {
        const doc = parse({ blocks: [{ type: "paragraph", content: [text("x", ["underline", "bold", "blink", "italic"])] }] });

        expect(doc.content[0].content[0].marks).toEqual([{ type: "underline" }, { type: "bold" }, { type: "italic" }]);
    });

    it("leaves the marks key off text that has none", () => {
        const doc = parse({ blocks: [{ type: "paragraph", content: [text("x"), text("y", []), text("z", ["blink"])] }] });

        for (const node of doc.content[0].content) {
            expect(node).not.toHaveProperty("marks");
        }
    });

    it("uses empty text for a node without any", () => {
        const doc = parse({ blocks: [{ type: "paragraph", content: [{}] }] });

        expect(doc.content[0].content).toEqual([{ type: "text", text: "" }]);
    });

    it("skips block types it does not know", () => {
        const doc = parse({ blocks: [{ type: "image" }, { type: "paragraph", content: [text("kept")] }, { type: "table" }] });

        expect(doc.content).toHaveLength(1);
        expect(doc.content[0].type).toBe("paragraph");
    });

    it("gives an empty paragraph or list item no text nodes", () => {
        const doc = parse({ blocks: [{ type: "paragraph" }, { type: "unordered-list", items: [[]] }, { type: "ordered-list" }] });

        expect(doc.content[0].content).toEqual([]);
        expect(doc.content[1].content[0].content[0].content).toEqual([]);
        expect(doc.content[2].content).toEqual([]);
    });
});

describe.each(copies)("%s serializer (TipTap -> stored JSON)", (_name, _parse, serialize) => {
    const withDoc = (content) => {
        editor.current = { getJSON: () => ({ type: "doc", content }) };
    };

    it("saves an empty document when there is no editor", () => {
        expect(serialize()).toEqual({ version: 1, blocks: [] });
    });

    it("saves an empty document for an editor with no content", () => {
        editor.current = { getJSON: () => ({ type: "doc" }) };

        expect(serialize()).toEqual({ version: 1, blocks: [] });
    });

    it("numbers blocks block_001, block_002 … by their position", () => {
        withDoc([
            { type: "paragraph", content: [{ type: "text", text: "a" }] },
            { type: "heading", attrs: { level: 1 }, content: [{ type: "text", text: "b" }] },
        ]);

        expect(serialize().blocks.map((b) => b.id)).toEqual(["block_001", "block_002"]);
    });

    it("keeps positions in the ids when an unknown block is dropped", () => {
        withDoc([
            { type: "paragraph", content: [{ type: "text", text: "a" }] },
            { type: "codeBlock" },
            { type: "paragraph", content: [{ type: "text", text: "c" }] },
        ]);

        expect(serialize().blocks.map((b) => b.id)).toEqual(["block_001", "block_003"]);
    });

    it("saves paragraphs and headings with alignment and level defaults", () => {
        withDoc([
            { type: "paragraph", attrs: { textAlign: "justify" }, content: [{ type: "text", text: "p" }] },
            { type: "paragraph", content: [{ type: "text", text: "q" }] },
            { type: "heading", attrs: { level: 1, textAlign: "center" }, content: [{ type: "text", text: "h" }] },
            { type: "heading", content: [{ type: "text", text: "d" }] },
        ]);

        const blocks = serialize().blocks;

        expect(blocks[0]).toMatchObject({ type: "paragraph", align: "justify" });
        expect(blocks[1]).toMatchObject({ type: "paragraph", align: "left" });
        expect(blocks[2]).toMatchObject({ type: "heading", level: 1, align: "center" });
        expect(blocks[3]).toMatchObject({ type: "heading", level: 2, align: "left" });
    });

    it("saves lists as items of text nodes, and an empty item as an empty list", () => {
        withDoc([
            { type: "bulletList", content: [
                { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "a" }] }] },
                { type: "listItem", content: [{ type: "paragraph" }] },
            ] },
            { type: "orderedList", content: [{ type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "1" }] }] }] },
        ]);

        const [bullets, numbers] = serialize().blocks;

        expect(bullets).toMatchObject({ type: "unordered-list", align: "left", items: [[{ text: "a" }], []] });
        expect(numbers).toMatchObject({ type: "ordered-list", items: [[{ text: "1" }]] });
    });

    it("saves the three marks and drops any other", () => {
        withDoc([{ type: "paragraph", content: [
            { type: "text", text: "x", marks: [{ type: "bold" }, { type: "link" }, { type: "underline" }, { type: "italic" }] },
            { type: "text", text: "plain" },
            { type: "text", text: "only-unknown", marks: [{ type: "link" }] },
        ] }]);

        const nodes = serialize().blocks[0].content;

        expect(nodes[0]).toEqual({ text: "x", marks: ["bold", "underline", "italic"] });
        expect(nodes[1]).toEqual({ text: "plain" });
        expect(nodes[2]).toEqual({ text: "only-unknown", marks: [] });
    });

    it("drops inline nodes that are not text", () => {
        withDoc([{ type: "paragraph", content: [{ type: "hardBreak" }, { type: "text", text: "kept" }] }]);

        expect(serialize().blocks[0].content).toEqual([{ text: "kept" }]);
    });
});

describe.each(copies)("%s round trip", (_name, parse, serialize) => {
    it("saves back exactly what was loaded", () => {
        const stored = {
            version: 1,
            blocks: [
                { id: "block_001", type: "heading", level: 1, align: "center", content: [{ text: "Title" }] },
                { id: "block_002", type: "paragraph", align: "left", content: [{ text: "Some " }, { text: "bold", marks: ["bold", "italic"] }, { text: " text", marks: ["underline"] }] },
                { id: "block_003", type: "unordered-list", align: "left", items: [[{ text: "a" }], [{ text: "b", marks: ["bold"] }]] },
                { id: "block_004", type: "ordered-list", align: "left", items: [[{ text: "one" }]] },
            ],
        };

        editor.current = { getJSON: () => parse(stored) };

        expect(serialize()).toEqual(stored);
    });
});
