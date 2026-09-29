import { fetchGrades, recalculateGrades } from "./api.js";
import { state } from "./state.js";
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

export async function initialize()
{
    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        state.data = await fetchGrades();

        container.innerHTML =
            state.data.html;

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
