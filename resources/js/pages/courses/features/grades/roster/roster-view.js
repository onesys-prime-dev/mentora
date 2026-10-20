/*
|--------------------------------------------------------------------------
| Gradebook grid: search, filter and sort
|--------------------------------------------------------------------------
|
| Works on the rows already on the page (no request): each row carries
| data-search, data-name, data-grade, data-tag and data-override. The rules
| are plain functions so they can be tested without a page.
|
*/

/** Whether a student's row shows for the search text and the "show" filter. */
export function matchesRow(student, query, filter)
{
    const text = String(query ?? "").trim().toLowerCase();

    if (text !== "" && !student.search.includes(text)) {
        return false;
    }

    switch (filter) {

        case "attention":
            return student.tag === "At risk" || student.tag === "Failed";

        case "inc":
            return student.tag === "INC";

        case "ungraded":
            return student.grade === null;

        case "override":
            return student.override === true;

        default:
            return true;

    }
}

/** A comparison function for the "sort by" choice; students without a grade go last either way. */
export function compareStudents(sort)
{
    const byName = (a, b) => a.name.localeCompare(b.name);

    return (a, b) => {

        if (sort === "grade-desc" || sort === "grade-asc") {

            if (a.grade === null || b.grade === null) {
                return a.grade === b.grade ? byName(a, b) : (a.grade === null ? 1 : -1);
            }

            const difference = sort === "grade-desc" ? b.grade - a.grade : a.grade - b.grade;

            return difference !== 0 ? difference : byName(a, b);

        }

        return byName(a, b);

    };
}

/** Reads a row's data attributes. */
export function studentOf(row)
{
    const grade = row.dataset.grade;

    return {
        element: row,
        search: row.dataset.search ?? "",
        name: row.dataset.name ?? "",
        grade: grade === undefined || grade === "" ? null : Number(grade),
        tag: row.dataset.tag ?? "",
        override: row.dataset.override === "1",
    };
}

/** Wires the grid's search box and the two selects. */
export function bindRosterControls(container)
{
    const controls = container.querySelector("[data-roster-controls]");

    if (!controls) {
        return;
    }

    const search = controls.querySelector("[data-roster-search]");
    const filter = controls.querySelector("[data-roster-filter]");
    const sort = controls.querySelector("[data-roster-sort]");
    const count = controls.querySelector("[data-roster-count]");
    const noMatch = container.querySelector("[data-roster-no-match]");

    const body = noMatch?.parentElement;

    const students = Array.from(container.querySelectorAll("[data-roster-row]")).map(studentOf);

    const apply = () => {

        const ordered = [...students].sort(compareStudents(sort.value));

        let shown = 0;

        ordered.forEach((student) => {

            const visible = matchesRow(student, search.value, filter.value);

            student.element.classList.toggle("hidden", !visible);

            shown += visible ? 1 : 0;

            // Moving the row (not copying it) keeps its inputs, timers and listeners.
            body?.insertBefore(student.element, noMatch);

        });

        noMatch?.classList.toggle("hidden", shown > 0);

        if (count) {
            count.textContent = shown === students.length
                ? `${students.length} ${students.length === 1 ? "student" : "students"}`
                : `Showing ${shown} of ${students.length}`;
        }

    };

    search.addEventListener("input", apply);
    filter.addEventListener("change", apply);
    sort.addEventListener("change", apply);

    apply();
}
