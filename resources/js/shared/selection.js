/**
 * Which rows of a list are ticked, for list pages with bulk actions.
 *
 * Plain state, no DOM: a page binds it to its checkboxes. Ids are compared as
 * strings, because that is what a checkbox's value is.
 */

/**
 * @typedef {'none' | 'some' | 'all'} SelectionState
 */

/**
 * @param {Iterable<string | number>} [initial] ids that start ticked
 */
export function createSelection(initial = []) {
    /** @type {Set<string>} */
    const chosen = new Set(Array.from(initial, String));

    return {
        /** Ticks or unticks one row. */
        set(id, checked) {
            if (checked) {
                chosen.add(String(id));
            } else {
                chosen.delete(String(id));
            }
        },

        /** Flips one row. @returns {boolean} whether it is now ticked */
        toggle(id) {
            const key = String(id);

            if (chosen.has(key)) {
                chosen.delete(key);

                return false;
            }

            chosen.add(key);

            return true;
        },

        /** Ticks every given row (the page's rows), leaving others as they are. */
        selectAll(ids) {
            for (const id of ids) {
                chosen.add(String(id));
            }
        },

        /** Unticks every given row, or everything when none are given. */
        clear(ids) {
            if (ids === undefined) {
                chosen.clear();

                return;
            }

            for (const id of ids) {
                chosen.delete(String(id));
            }
        },

        has: (id) => chosen.has(String(id)),

        count: () => chosen.size,

        /** @returns {string[]} the ticked ids, in the order they were ticked */
        ids: () => Array.from(chosen),

        /**
         * Whether none, some or all of the given rows are ticked. This is what
         * the "select all" box shows (unticked, dashes, ticked).
         *
         * @param {Iterable<string | number>} rows the rows on the page
         * @returns {SelectionState}
         */
        stateOf(rows) {
            const all = Array.from(rows, String);

            if (all.length === 0) {
                return "none";
            }

            const ticked = all.filter((id) => chosen.has(id)).length;

            if (ticked === 0) {
                return "none";
            }

            return ticked === all.length ? "all" : "some";
        },
    };
}

/**
 * The words that go with a count: "No one selected", "1 person selected".
 *
 * @param {number} count
 * @param {{ one?: string, many?: string }} [nouns]
 */
export function selectionLabel(count, nouns = {}) {
    const { one = "person", many = "people" } = nouns;

    if (count <= 0) {
        return `No ${many === "people" ? "one" : many} selected`;
    }

    return `${count} ${count === 1 ? one : many} selected`;
}
