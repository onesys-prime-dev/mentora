/**
 * The reports filter bar: the program list follows the chosen college.
 * Without this script both selects still work (the server checks that the
 * program belongs to the college); this only saves a wrong pairing.
 */

import { keepProgram, programsFor } from "../../shared/report-filters.js";

const college = document.querySelector("select[name='college']");
const program = document.querySelector("select[name='program']");

if (college && program && program.dataset.programs) {
    let programs = [];

    try {
        programs = JSON.parse(program.dataset.programs);
    } catch {
        programs = [];
    }

    const render = () => {
        const available = programsFor(college.value, programs);
        const keep = keepProgram(program.value, available);

        program.replaceChildren(
            new Option("Any program", ""),
            ...available.map((item) => new Option(`${item.code} · ${item.name}`, String(item.id))),
        );

        program.value = keep;
    };

    college.addEventListener("change", render);
    render();
}
