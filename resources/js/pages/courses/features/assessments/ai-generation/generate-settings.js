/*
|--------------------------------------------------------------------------
| Generate settings
|--------------------------------------------------------------------------
|
| What the teacher last asked for, kept in this browser per course so the
| next generation starts from it. Pure helpers: the form reads and writes
| them, and a blocked or full storage just means the defaults.
*/

export const DEFAULTS = Object.freeze({
    count: 10,
    points: "",
    types: { multiple_choice: 10 },
    difficulty: "mixed",
    level: "any",
    choices: 4,
    language: "",
    focus: "",
});

const KEY_PREFIX = "mentora.aiGenerate.";

/**
 * @param {unknown} raw
 * @returns {typeof DEFAULTS}
 */
export function sanitize(raw) {

    const value = raw && typeof raw === "object" ? raw : {};
    const number = (n, fallback, min, max) => {

        const parsed = Number(n);

        return Number.isFinite(parsed) ? Math.max(min, Math.min(max, Math.round(parsed))) : fallback;

    };
    const pick = (v, allowed, fallback) => (allowed.includes(v) ? v : fallback);
    const types = {};

    Object.entries(value.types && typeof value.types === "object" ? value.types : {}).forEach(([key, count]) => {

        if (/^[a-z_]{3,24}$/.test(key) && Number(count) > 0) {
            types[key] = Math.min(100, Math.round(Number(count)));
        }

    });

    return {
        count: number(value.count, DEFAULTS.count, 1, 100),
        points: value.points === "" || value.points === undefined ? "" : String(Math.max(0, Number(value.points) || 0)),
        types: Object.keys(types).length > 0 ? types : { ...DEFAULTS.types },
        difficulty: pick(value.difficulty, ["mixed", "easier", "harder"], DEFAULTS.difficulty),
        level: pick(value.level, ["any", "recall", "understand", "apply"], DEFAULTS.level),
        choices: number(value.choices, DEFAULTS.choices, 3, 5),
        language: typeof value.language === "string" ? value.language.slice(0, 40) : "",
        focus: typeof value.focus === "string" ? value.focus.slice(0, 200) : "",
    };

}

/**
 * Short words for what differs from the defaults, shown beside "Fine-tune".
 *
 * @param {{difficulty: string, level: string, choices: number, language: string, focus: string}} settings
 * @returns {string}
 */
export function fineTuneSummary(settings) {

    const parts = [];

    if (settings.difficulty !== "mixed") {
        parts.push(settings.difficulty);
    }

    if (settings.level !== "any") {
        parts.push(settings.level);
    }

    if (settings.choices !== 4) {
        parts.push(`${settings.choices} choices`);
    }

    if (settings.language !== "") {
        parts.push(settings.language);
    }

    if (settings.focus !== "") {
        parts.push("focused");
    }

    return parts.length === 0 ? "" : `(${parts.join(", ")})`;

}

/** @param {string|number} courseKey */
export function recall(courseKey, storage = safeStorage()) {

    try {
        return sanitize(JSON.parse(storage?.getItem(KEY_PREFIX + courseKey) || "null"));
    } catch {
        return sanitize(null);
    }

}

/**
 * @param {string|number} courseKey
 * @param {typeof DEFAULTS} settings
 */
export function remember(courseKey, settings, storage = safeStorage()) {

    try {
        storage?.setItem(KEY_PREFIX + courseKey, JSON.stringify(sanitize(settings)));
    } catch {
        // Storage can be blocked or full: the next generation simply starts from the defaults.
    }

}

function safeStorage() {

    try {
        return window.localStorage;
    } catch {
        return undefined;
    }

}
