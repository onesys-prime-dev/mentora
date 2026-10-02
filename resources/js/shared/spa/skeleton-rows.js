/*
|--------------------------------------------------------------------------
| How many placeholder rows a list skeleton draws
|--------------------------------------------------------------------------
|
| Always between 1 and 3 — enough to show the shape of a list without
| drawing a screenful, and never zero, so an empty result still gets a
| row to fade out of. A page-load skeleton can't know the count before the
| fetch returns, so each list remembers how many rows it last showed
| (per feature, course and role) and the next load's skeleton matches it;
| the first visit falls back to the maximum.
|
*/

export const MIN_ROWS = 1;
export const MAX_ROWS = 3;

export const clampRows = (n) => Math.min(MAX_ROWS, Math.max(MIN_ROWS, Number(n) || 0));

const key = (name) =>
    `skeleton-rows:${name}:${window.coursesConfig?.offeringId ?? ""}:${window.coursesConfig?.role ?? ""}`;

export function rememberRows(name, count) {

    try {
        sessionStorage.setItem(key(name), String(count));
    } catch (error) {
        // storage unavailable — skeletons just fall back to the maximum
    }

}

export function rememberedRows(name) {

    try {

        const stored = sessionStorage.getItem(key(name));

        return stored === null ? MAX_ROWS : clampRows(stored);

    } catch (error) {
        return MAX_ROWS;
    }

}
