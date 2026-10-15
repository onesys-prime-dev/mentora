/*
|--------------------------------------------------------------------------
| Review tray helpers
|--------------------------------------------------------------------------
|
| Pure functions that decide what a staged question says about itself: the
| chips on its card, where it came from, and what "accept all" will do. The
| server marks `needs_look` and `duplicate_of`; these only read them.
*/

export const SOURCE_SIMILARITY_SOFT = 0.4;
export const SOURCE_SIMILARITY_HARD = 0.6;

/**
 * Chips for a card, most serious first.
 *
 * @param {object} question as listed by the server
 * @returns {{label: string, tone: "danger"|"warning"|"ok"}[]}
 */
export function cardChips(question) {

    const chips = [];
    const verification = question.verification || {};

    if (question.math_check === "mismatch") {
        chips.push({ label: "Marked answer looks wrong", tone: "danger" });
    }

    if (verification.solver_agrees === false) {
        chips.push({ label: "A second check disagreed", tone: "danger" });
    }

    if (question.duplicate_of) {
        chips.push({ label: "Already in this quiz", tone: "warning" });
    }

    if (verification.grounded === false) {
        chips.push({ label: "Not found in the material", tone: "warning" });
    }

    const similarity = Number(question.similarity_score);

    if (similarity >= SOURCE_SIMILARITY_HARD) {
        chips.push({ label: "Copies the source closely", tone: "warning" });
    } else if (similarity > SOURCE_SIMILARITY_SOFT) {
        chips.push({ label: "Similar to source", tone: "warning" });
    }

    if (chips.length === 0 && verification.solver_agrees === true) {
        chips.push({ label: "Checked", tone: "ok" });
    }

    return chips;

}

/**
 * "From Reading title, page 4" or an empty string when nothing is known.
 *
 * @param {{source_title?: string|null, source_page?: number|null}} question
 */
export function sourceLine(question) {

    const title = (question.source_title || "").trim();
    const page = Number(question.source_page);

    if (title === "" && !(page > 0)) {
        return "";
    }

    const where = title === "" ? "the material" : title;

    return page > 0 ? `From ${where}, page ${page}` : `From ${where}`;

}

/**
 * What to say under the tray when fewer questions came back than asked for.
 *
 * @param {{missing: number, reasons: {count: number, text: string}[], not_enough_material: boolean}} shortfall
 * @returns {string} empty when nothing is missing
 */
export function shortfallText(shortfall) {

    if (!shortfall || shortfall.missing < 1) {
        return "";
    }

    const asked = shortfall.missing === 1 ? "1 question is missing" : `${shortfall.missing} questions are missing`;
    const parts = [];

    if (shortfall.not_enough_material) {
        parts.push("there was not enough distinct material to write more");
    }

    shortfall.reasons.forEach((reason) => {
        parts.push(`${reason.count} ${reason.count === 1 ? "was dropped because" : "were dropped because"} ${reason.text}`);
    });

    return parts.length === 0 ? `${asked}.` : `${asked}: ${parts.join("; ")}.`;

}

/**
 * One line per selected item: how many questions came from it.
 *
 * @param {{title: string, count: number}[]} coverage
 * @returns {{title: string, text: string, empty: boolean}[]}
 */
export function coverageRows(coverage) {

    return (coverage || []).map((row) => ({
        title: row.title || "Untitled",
        text: row.count === 0 ? "no questions" : `${row.count} ${row.count === 1 ? "question" : "questions"}`,
        empty: row.count === 0,
    }));

}

/** The ways a staged question can be replaced, in the order the menu shows them. */
export const REPLACE_CHOICES = [
    { mode: "different", label: "A different question" },
    { mode: "easier", label: "Easier" },
    { mode: "harder", label: "Harder" },
];

/**
 * The other question types a staged question could become.
 *
 * @param {string} current
 * @returns {string[]}
 */
export function otherTypes(current) {

    return ["Multiple Choice", "Short Answer", "Essay", "File Upload"].filter((type) => type !== current);

}

/**
 * What accept all will do, so it can be said before it is done.
 *
 * @param {{needs_look?: boolean}[]} questions
 * @returns {{total: number, clear: number, needLook: number}}
 */
export function acceptCounts(questions) {

    const needLook = questions.filter((q) => q.needs_look).length;

    return { total: questions.length, clear: questions.length - needLook, needLook };

}

/**
 * The sentence after an accept-all.
 *
 * @param {{accepted_count?: number, skipped_count?: number}} result
 */
export function acceptReport(result) {

    const added = result.accepted_count || 0;
    const skipped = result.skipped_count || 0;

    const first = `${added} ${added === 1 ? "question was" : "questions were"} added`;

    return skipped > 0 ? `${first}; ${skipped} left to review.` : `${first}.`;

}
