import { enterFeatureTab } from "../../core/featureLoader.js";
import { beginLoad } from "../../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../../shared/spa/error-state.js";
import { renderOverviewSkeleton } from "./skeleton.js";

import { fetchOverview } from "./api.js";
import { rememberRows } from "../../../../shared/spa/skeleton-rows.js";
import { state } from "./state.js";

/*
 * Rows on the Overview open the screens the Assessments tab already has —
 * the student's view of an assessment, Grade Work, the assessment editor —
 * rather than duplicating them. Each loader is self-contained
 * (offeringId, assessmentId, a refresh callback), the same reuse the Grades
 * tab makes.
 */
import {
    loadAssessmentView,
    loadAssessmentEditor,
    loadGradeWork,
} from "../assessments/index.js";

export async function initialize()
{
    state.loading = true;

    const container =
        document.querySelector("#course-content");

    const load = beginLoad(container, renderOverviewSkeleton());

    try {

        state.data = await fetchOverview();

        if (!load.current()) {
            return;
        }

        load.finish();

        container.innerHTML =
            state.data.html;

        rememberRows("overview-deadlines", document.querySelectorAll("[data-overview-open]").length);

        bindRowActions();

    }

    catch (error) {

        console.error(error);

        if (!load.current()) {
            return;
        }

        load.finish();

        showLoadError(container, "Overview", initialize);

    }

    finally {

        state.loading = false;

    }
}

/*
|--------------------------------------------------------------------------
| Row actions
|--------------------------------------------------------------------------
| data-overview-open → a deadline / to-grade row: an assessment
|                      (the student's view, or Grade Work for an instructor)
| data-overview-edit → an unpublished assessment: its editor
|
| Links into the Modules tab are plain [data-course-feature] elements,
| which the course navigation already handles for the whole page.
| `initialize` is the refresh callback, so "back" from any of these lands
| on the Overview the click started from.
*/

function bindRowActions()
{
    const offeringId =
        window.coursesConfig.offeringId;

    const isInstructor =
        window.coursesConfig.role === "instructor";

    document
        .querySelectorAll("[data-overview-open]")
        .forEach((element) => {

            element.addEventListener("click", () => {

                const assessmentId =
                    element.dataset.overviewOpen;

                if (!assessmentId) {
                    return;
                }

                // Instructors land on the Assessments tab, not just its screen.
                if (isInstructor) {
                    loadGradeWork(offeringId, assessmentId, null, enterFeatureTab("assessments"));
                    return;
                }

                loadAssessmentView(offeringId, assessmentId, initialize);

            });

        });

    document
        .querySelectorAll("[data-overview-edit]")
        .forEach((element) => {

            element.addEventListener("click", () => {

                const assessmentId =
                    element.dataset.overviewEdit;

                if (assessmentId) {
                    loadAssessmentEditor(offeringId, assessmentId, enterFeatureTab("assessments"));
                }

            });

        });
}
