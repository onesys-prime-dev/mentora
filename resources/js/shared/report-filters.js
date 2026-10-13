/**
 * The report filters' state: choosing a department narrows the programs that
 * can be chosen, and a program that no longer fits is dropped. Pure, so the
 * rules are testable without a page.
 */

/**
 * @typedef {{id: number | string, name: string, department_id: number | string}} Program
 */

/**
 * The programs that belong to a department (all of them for none).
 *
 * @param {string | number | null | undefined} departmentId
 * @param {Program[]} programs
 * @returns {Program[]}
 */
export function programsFor(departmentId, programs) {
    const wanted = String(departmentId ?? "");

    if (wanted === "") {
        return programs;
    }

    return programs.filter((program) => String(program.department_id) === wanted);
}

/**
 * The program to keep selected once the choices changed: the current one if it
 * is still offered, else none.
 *
 * @param {string | number | null | undefined} current
 * @param {Program[]} available
 * @returns {string}
 */
export function keepProgram(current, available) {
    const wanted = String(current ?? "");

    return available.some((program) => String(program.id) === wanted) ? wanted : "";
}

/**
 * The state of the whole filter set, for "is anything narrowing this?".
 *
 * @param {Record<string, string | null | undefined>} values
 * @returns {boolean}
 */
export function isFiltered(values) {
    return Object.values(values).some((value) => String(value ?? "").trim() !== "");
}
