import { describe, expect, it, vi } from "vitest";

import { OUTPUT, exportBlob, nudge, pan, squareFor } from "../../../../resources/js/pages/profile/photo-crop.js";

const wide = { width: 400, height: 200, zoom: 1, cx: 200, cy: 100 };

describe("the crop square", () => {

    it("is the largest square, centred, at zoom 1", () => {

        expect(squareFor(wide)).toEqual({ x: 100, y: 0, side: 200 });

    });

    it("shrinks with zoom and stays inside the image", () => {

        expect(squareFor({ ...wide, zoom: 2 })).toEqual({ x: 150, y: 50, side: 100 });
        // A centre near the edge is pulled back so the square never leaves the image.
        expect(squareFor({ ...wide, zoom: 2, cx: 0, cy: 0 })).toEqual({ x: 0, y: 0, side: 100 });
        expect(squareFor({ ...wide, zoom: 2, cx: 999, cy: 999 })).toEqual({ x: 300, y: 100, side: 100 });
        // Zoom is held between 1 and 4.
        expect(squareFor({ ...wide, zoom: 99 }).side).toBe(50);
        expect(squareFor({ ...wide, zoom: 0.1 }).side).toBe(200);

    });

    it("follows a drag the other way, clamped", () => {

        const zoomed = { ...wide, zoom: 2 };
        const moved = pan(zoomed, 50, 0, 100); // a 100 px window showing 100 image px: dragging right shows what was left

        expect(squareFor(moved).x).toBe(100);
        expect(squareFor(pan(zoomed, 5000, 0, 100)).x).toBe(0);

    });

});

describe("the arrow keys", () => {

    it("nudge by a twentieth of the square", () => {

        expect(squareFor(nudge(wide, "ArrowRight")).x).toBe(110);
        expect(squareFor(nudge({ ...wide, zoom: 2 }, "ArrowDown")).y).toBe(55);
        expect(nudge(wide, "a")).toBeNull();

    });

    it("stop at the image's edge", () => {

        const left = { ...wide, cx: 0 };

        expect(squareFor(nudge(left, "ArrowLeft")).x).toBe(0);

    });

});

describe("the export", () => {

    it("is a 512 px JPEG of the square", async () => {

        const drawImage = vi.fn();
        const canvas = { width: OUTPUT, height: OUTPUT, getContext: () => ({ drawImage }), toBlob: vi.fn((done, type, quality) => done({ type, quality })) };
        const img = {};
        const blob = await exportBlob(img, { ...wide, zoom: 2 }, () => canvas);

        expect(drawImage).toHaveBeenCalledWith(img, 150, 50, 100, 100, 0, 0, 512, 512);
        expect(canvas.toBlob).toHaveBeenCalledWith(expect.any(Function), "image/jpeg", 0.9);
        expect(blob.type).toBe("image/jpeg");

    });

});
