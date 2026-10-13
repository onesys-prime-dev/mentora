/**
 * The brand colour rules, the same as App\Services\Institutions\BrandColours
 * (the server is the one that decides; this is for the live preview, so
 * someone sees "too light, 3.1:1" before they press Save).
 */

export const MIN_RATIO = 4.5;

const TINT_STRENGTH = 0.05;
const DARKEN = 0.62;

/**
 * "#RRGGBB" in capitals from "#abc", "abc", "0040a6" or "#0040A6"; null when
 * it is not a colour written in hex.
 *
 * @param {string | null | undefined} value
 * @returns {string | null}
 */
export function normalise(value) {
    let hex = String(value ?? "")
        .trim()
        .toUpperCase()
        .replace(/^#/, "");

    if (/^[0-9A-F]{3}$/.test(hex)) {
        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }

    return /^[0-9A-F]{6}$/.test(hex) ? `#${hex}` : null;
}

/**
 * @param {string} hex normalised
 * @returns {number[]}
 */
function channels(hex) {
    return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
}

function toHex(r, g, b) {
    const part = (c) => Math.max(0, Math.min(255, Math.round(c))).toString(16).toUpperCase().padStart(2, "0");

    return `#${part(r)}${part(g)}${part(b)}`;
}

/** WCAG relative luminance, 0 (black) to 1 (white). */
export function luminance(hex) {
    const [r, g, b] = channels(hex).map((c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio, 1 to 21. */
export function contrast(a, b) {
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** The hover colour: the primary, darker. */
export function dark(primary) {
    const [r, g, b] = channels(primary);

    return toHex(r * DARKEN, g * DARKEN, b * DARKEN);
}

/** The pale background: the primary, mostly white. */
export function tint(primary) {
    const mix = (c) => c * TINT_STRENGTH + 255 * (1 - TINT_STRENGTH);
    const [r, g, b] = channels(primary);

    return toHex(mix(r), mix(g), mix(b));
}

/**
 * What a typed colour means for the primary or the accent.
 *
 * @param {string} value what was typed
 * @param {"primary" | "accent"} role
 * @returns {{hex: string | null, ratio: number | null, ok: boolean, message: string}}
 */
export function check(value, role) {
    const hex = normalise(value);

    if (hex === null) {
        return { hex: null, ratio: null, ok: false, message: "Write the colour as six hex digits, such as #0040A6." };
    }

    const against = role === "primary" ? tint(hex) : "#FFFFFF";
    const ratio = contrast(hex, against);
    const ok = ratio >= MIN_RATIO;
    const shown = (Math.floor(ratio * 10) / 10).toFixed(1);

    return {
        hex,
        ratio,
        ok,
        message: ok
            ? `Contrast ${shown}:1, passes AA.`
            : `Too light: contrast ${shown}:1, and at least ${MIN_RATIO.toFixed(1)}:1 is needed. Choose a darker colour.`,
    };
}
