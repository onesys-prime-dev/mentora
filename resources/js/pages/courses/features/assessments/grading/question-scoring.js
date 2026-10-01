/*
|--------------------------------------------------------------------------
| Per-question grading (quizzes)
|--------------------------------------------------------------------------
|
| Auto-gradable questions (Multiple Choice, Short Answer with a key) show
| their ✓/✗ badge with an edit icon that reveals Correct/Incorrect/Revert
| buttons; free-response questions (Essay, File Upload, keyless Short
| Answer) get a plain points input. Either path reloads the current
| student/attempt afterward — same no-optimistic-UI approach the rest of
| this screen already uses (submitGrade, reopenGrading, feedback replies).
|
*/

import { gradeQuestion } from "../api.js";

export function bindQuestionScoring(offeringId, reload) {

    document
        .querySelectorAll("[data-grade-work-question]")
        .forEach((card) => {

            const questionId =
                card.dataset.questionId;

            card.querySelector("[data-question-edit-toggle]")
                ?.addEventListener("click", () => {

                    card.querySelector("[data-question-badge]")?.classList.add("hidden");

                    const controls =
                        card.querySelector("[data-question-manual-controls]");

                    controls?.classList.remove("hidden");
                    controls?.classList.add("flex");

                });

            card.querySelectorAll("[data-question-mark]").forEach((button) => {

                button.addEventListener("click", async () => {

                    const submissionId =
                        document.querySelector("[data-expandable-grade-work-actions-panel]")
                            ?.dataset.submissionId;

                    if (!submissionId) {
                        return;
                    }

                    button.disabled = true;

                    try {

                        const result = await gradeQuestion(
                            offeringId,
                            submissionId,
                            questionId,
                            { mode: button.dataset.questionMark }
                        );

                        if (result.success === false) {
                            window.alert(result.message);
                            return;
                        }

                        await reload();

                    } catch (error) {

                        console.error(error);

                        window.alert("Couldn't grade that question — please try again.");

                    } finally {

                        button.disabled = false;

                    }

                });

            });

            const pointsInput =
                card.querySelector("[data-question-points-input]");

            pointsInput?.addEventListener("change", async () => {

                const submissionId =
                    document.querySelector("[data-expandable-grade-work-actions-panel]")
                        ?.dataset.submissionId;

                if (!submissionId) {
                    return;
                }

                const points =
                    Number(pointsInput.value || 0);

                pointsInput.disabled = true;

                try {

                    const result = await gradeQuestion(
                        offeringId,
                        submissionId,
                        questionId,
                        { mode: "points", points }
                    );

                    if (result.success === false) {
                        window.alert(result.message);
                        return;
                    }

                    await reload();

                } catch (error) {

                    console.error(error);

                    window.alert("Couldn't save that score — please try again.");

                } finally {

                    pointsInput.disabled = false;

                }

            });

        });

}
