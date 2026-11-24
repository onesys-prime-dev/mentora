/**
 * The profile photo dialog's square crop: the chosen image sits under a square window the person
 * drags (or nudges with the arrow keys) and zooms with a slider; the window is exported as a 512 px
 * JPEG into the form's hidden file input, so the dialog's own submit posts it.
 */

export const OUTPUT = 512;
export const MAX_ZOOM = 4;

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/**
 * The square of the image the window shows, kept inside the image.
 *
 * @param {{width: number, height: number, zoom: number, cx: number, cy: number}} s centre (cx, cy) in image pixels
 * @returns {{x: number, y: number, side: number}}
 */
export function squareFor({ width, height, zoom, cx, cy }) {
    const side = Math.min(width, height) / clamp(zoom, 1, MAX_ZOOM);

    return {
        x: clamp(cx - side / 2, 0, width - side),
        y: clamp(cy - side / 2, 0, height - side),
        side,
    };
}

/** Moves the centre by a drag of (dx, dy) window pixels: the image follows the pointer. */
export function pan(state, dx, dy, windowPx) {
    const { x, y, side } = squareFor(state);
    const k = side / windowPx;

    return { ...state, cx: x + side / 2 - dx * k, cy: y + side / 2 - dy * k };
}

/** Arrow keys: a step is a twentieth of what the window shows. Returns null for other keys. */
export function nudge(state, key) {
    const step = squareFor(state).side / 20;
    const by = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[key];

    if (!by) {
        return null;
    }

    const { x, y, side } = squareFor(state);

    return { ...state, cx: x + side / 2 + by[0], cy: y + side / 2 + by[1] };
}

/** Draws the window's square of `img` onto `canvas`. */
export function draw(canvas, img, state) {
    const { x, y, side } = squareFor(state);

    canvas.getContext("2d").drawImage(img, x, y, side, side, 0, 0, canvas.width, canvas.height);
}

/** The window as a JPEG blob, OUTPUT px square. */
export function exportBlob(img, state, make = (w, h) => Object.assign(document.createElement("canvas"), { width: w, height: h })) {
    const out = make(OUTPUT, OUTPUT);

    draw(out, img, state);

    return new Promise((resolve) => out.toBlob(resolve, "image/jpeg", 0.9));
}

/** @param {ParentNode} root */
export function bindPhotoCrop(root = document) {
    for (const form of root.querySelectorAll("form[data-photo-crop]")) {
        const choose = form.querySelector("[data-photo-choose]");
        const stage = form.querySelector("[data-photo-stage]");
        const canvas = form.querySelector("[data-photo-canvas]");
        const zoom = form.querySelector("[data-photo-zoom]");
        const exported = form.querySelector("[data-photo-export]");
        const save = form.querySelector("[data-photo-save]");
        const problem = form.querySelector("[data-field-error]");
        let img = null;
        let state = null;
        let drag = null;
        let generation = 0; // a late toBlob of an earlier crop or image must not replace the current one

        const fail = (message) => {
            problem.textContent = message;
            problem.hidden = message === "";
        };

        const clear = () => {
            generation++;
            img = null;
            stage.hidden = true;
            save.disabled = true;
            exported.value = "";
        };

        const sync = () => {
            draw(canvas, img, state);

            // Keep the form's file current, so a submit at any moment posts what is shown.
            const mine = ++generation;

            exportBlob(img, state).then((blob) => {
                if (!blob || !img || mine !== generation) {
                    return;
                }

                const data = new DataTransfer();

                data.items.add(new File([blob], "photo.jpg", { type: "image/jpeg" }));
                exported.files = data.files;
                save.disabled = false;
            });
        };

        choose.addEventListener("change", () => {
            const file = choose.files?.[0];

            fail("");
            clear();

            if (!file) {
                return;
            }

            if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > Number(form.dataset.maxBytes)) {
                fail(form.dataset.limit);

                return;
            }

            const url = URL.createObjectURL(file);
            const image = new Image();

            image.onload = () => {
                URL.revokeObjectURL(url);
                img = image;
                state = { width: image.naturalWidth, height: image.naturalHeight, zoom: 1, cx: image.naturalWidth / 2, cy: image.naturalHeight / 2 };
                zoom.value = "1";
                stage.hidden = false;
                sync();
            };
            image.onerror = () => {
                URL.revokeObjectURL(url);
                fail(form.dataset.limit);
            };
            image.src = url;
        });

        zoom.addEventListener("input", () => {
            if (state) {
                state = { ...state, ...centred(state), zoom: Number(zoom.value) };
                sync();
            }
        });

        // Keep the same centre when zooming (squareFor clamps it back inside).
        const centred = (s) => {
            const { x, y, side } = squareFor(s);

            return { cx: x + side / 2, cy: y + side / 2 };
        };

        canvas.addEventListener("pointerdown", (event) => {
            if (state) {
                drag = { x: event.clientX, y: event.clientY };
                canvas.setPointerCapture?.(event.pointerId);
            }
        });
        canvas.addEventListener("pointermove", (event) => {
            if (drag && state) {
                state = pan(state, event.clientX - drag.x, event.clientY - drag.y, canvas.getBoundingClientRect().width);
                drag = { x: event.clientX, y: event.clientY };
                draw(canvas, img, state);
            }
        });
        const release = () => {
            if (drag) {
                drag = null;
                sync();
            }
        };

        canvas.addEventListener("pointerup", release);
        canvas.addEventListener("pointercancel", release);
        canvas.addEventListener("keydown", (event) => {
            const next = state && nudge(state, event.key);

            if (next) {
                event.preventDefault();
                state = next;
                sync();
            }
        });

        form.addEventListener("reset", () => setTimeout(() => {
            fail("");
            clear();
        }));
    }
}

if (typeof document !== "undefined") {
    bindPhotoCrop();
}
