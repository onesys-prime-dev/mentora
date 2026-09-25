/*
|--------------------------------------------------------------------------
| Assessment Hand-off
|--------------------------------------------------------------------------
|
| Quiz/assignment learning items show a summary in Modules; the heavy flows
| (take quiz, quiz builder, settings editor, grading) live in the
| Assessments tab. This switches tabs through its deep links, so the
| browser Back button returns to the learning item (?item=).
|
*/

import { loadFeature } from "../../core/featureLoader.js";

export function bindAssessmentHandoffs() {

    document
        .querySelectorAll("[data-open-assessment][data-assessment-mode]")
        .forEach((button) => {

            button.addEventListener("click", () => {

                openAssessment(
                    Number(button.dataset.openAssessment),
                    button.dataset.assessmentMode
                );

            });

        });

}

export function openAssessment(assessmentId, mode) {

    if (!assessmentId || !mode) {
        return;
    }

    history.pushState(
        {},
        "",
        `${window.coursesConfig.browserBaseUrl}/assessments?assessment=${assessmentId}&${mode}=1`
    );

    window.coursesConfig.activeFeature = "assessments";

    loadFeature("assessments");

}
