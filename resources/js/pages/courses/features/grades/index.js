import { fetchGrades, recalculateGrades } from "./api.js";
import { state } from "./state.js";
import { rememberRows } from "../../../../shared/spa/skeleton-rows.js";
import { renderGradesSkeleton } from "./skeleton.js";
import { loadGradingScheme } from "./scheme/scheme.js";
import { loadGradebookRoster } from "./roster/roster.js";
import { initializeGradeSheet } from "./import-export/grade-sheet.js";

/*
 * Reuses the Assessments feature's own Grade Work screen rather than
 * building a second grading UI — it already has the student sidebar,
 * live scoring, and rubric support this page needs. loadGradeWork() is
 * self-contained (offeringId/assessmentId/studentId + a refresh
 * callback), so importing it here doesn't couple this module to anything
 * assessments-specific beyond that one function.
 */
import { loadGradeWork, loadAssessmentView } from "../assessments/index.js";

import { highlightRow } from "../../../../shared/spa/highlight-row.js";

export async function initialize()
{
    state.loading = true;

    // A dashboard widget (e.g. "Needs Your Attention") can deep-link
    // straight into a specific student's roster row instead of the plain
    // assessment list — mirrors how the Assessments feature reads
    // ?assessment=&grade= for its own deep links.
    const params =
        new URLSearchParams(window.location.search);

    if (params.has("roster")) {

        const studentId =
            params.get("student");

        // Consumed once: strips the query string so the roster's own
        // "back" button (which just re-calls initialize()) lands on the
        // plain Grades list instead of looping back into this deep link.
        history.replaceState({}, "", window.location.pathname);

        await loadGradebookRoster(initialize);

        if (studentId) {
            highlightRow(`[data-roster-row][data-student-id="${studentId}"]`);
        }

        state.loading = false;

        return;

    }

    // A dashboard widget (e.g. "Grading Queue") can deep-link straight
    // into Grade Work for a specific assessment instead of the plain
    // Grades list — same ?assessment=&grade= pair the Assessments feature
    // itself reads, just landing on the Grades tab instead.
    const assessmentId =
        params.get("assessment");

    if (assessmentId && params.has("grade")) {

        const offeringId =
            window.coursesConfig.offeringId;

        await loadGradeWork(
            offeringId,
            assessmentId,
            null,
            initialize,
            { pushState: false }
        );

        state.loading = false;

        return;

    }

    const container =
        document.querySelector("#course-content");

    container.innerHTML = renderGradesSkeleton();

    try {

        state.data = await fetchGrades();

        container.innerHTML =
            state.data.html;

        rememberRows("grades", document.querySelectorAll("[data-assessment]").length);

        bindAssessmentLinks();

        bindGradebookToolbar();

    }

    catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load Grades.</p>";

    }

    finally {

        state.loading = false;

    }
}

/**
 * Instructor: grading scheme editor, Recalculate, offline grade sheet.
 */
function bindGradebookToolbar()
{
    if (!document.querySelector("[data-gradebook-toolbar]")) {
        return;
    }

    document.querySelector("[data-open-grading-scheme]")
        ?.addEventListener("click", () => loadGradingScheme(initialize));

    document.querySelector("[data-open-gradebook-roster]")
        ?.addEventListener("click", () => loadGradebookRoster(initialize));

    document.querySelectorAll("[data-recalculate-grades]").forEach((button) => {
        button.addEventListener("click", async () => {

            button.disabled = true;

            try {
                await recalculateGrades();
            } catch (error) {
                console.error(error);
            }

            await initialize();

        });
    });

    initializeGradeSheet(initialize);
}

function bindAssessmentLinks()
{
    const offeringId =
        window.coursesConfig.offeringId;

    // Students: a row opens that assessment (score, attempts, feedback) —
    // the same view its card opens on the Assessments tab.
    if (window.coursesConfig.role !== "instructor") {

        document
            .querySelectorAll("[data-assessment]")
            .forEach((element) => {

                element.addEventListener("click", () => {

                    const assessmentId =
                        element.dataset.assessment;

                    if (assessmentId) {
                        loadAssessmentView(offeringId, assessmentId, initialize);
                    }

                });

            });

        return;
    }

    document
        .querySelectorAll("[data-assessment]")
        .forEach((element) => {

            element.addEventListener("click", () => {

                const assessmentId =
                    element.dataset.assessment;

                if (!assessmentId) {
                    return;
                }

                // Landing back here (not the Assessments tab) after
                // grading is what makes sense given where the click
                // started, so `refresh` points at this module's own
                // initialize rather than the assessments feature's.
                loadGradeWork(
                    offeringId,
                    assessmentId,
                    null,
                    initialize
                );

            });

        });
}
