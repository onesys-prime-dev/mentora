import { fetchGrades } from "./api.js";
import { state } from "./state.js";

/*
 * Reuses the Assessments feature's own Grade Work screen rather than
 * building a second grading UI — it already has the student sidebar,
 * live scoring, and rubric support this page needs. loadGradeWork() is
 * self-contained (offeringId/assessmentId/studentId + a refresh
 * callback), so importing it here doesn't couple this module to anything
 * assessments-specific beyond that one function.
 */
import { loadGradeWork } from "../assessments/index.js";

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

function bindAssessmentLinks()
{
    if (window.coursesConfig.role !== "instructor") {
        return;
    }

    const offeringId =
        window.coursesConfig.offeringId;

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
