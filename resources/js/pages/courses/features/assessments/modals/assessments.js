/*
|--------------------------------------------------------------------------
| Assessment Create Modal
|--------------------------------------------------------------------------
*/

import { createAssessment } from "../api.js";
import { csrfExpired } from "../../../../../shared/csrf-expired.js";

export function bindAssessmentCreateModal({
    create,
    refresh,
    onCreated = null,
}) {

    if (!create) {
        return;
    }

    const form =
        document.querySelector(
            "#assessment-create-form"
        );

    if (!form) {
        return;
    }

    const moduleInput =
        form.querySelector(
            "[name='module_id']"
        );

    const learningUnitInput =
        form.querySelector(
            "[name='learning_unit_id']"
        );

    const learningUnitOptions =
        learningUnitInput
            ? Array.from(learningUnitInput.options)
            : [];

    if (moduleInput && learningUnitInput) {

        moduleInput.addEventListener(
            "change",
            () => {

                const selectedModuleId =
                    moduleInput.value;

                learningUnitOptions.forEach(
                    (option) => {

                        if (!option.value) {
                            return;
                        }

                        const matches =
                            option.dataset.moduleId
                                === selectedModuleId;

                        option.hidden = !matches;

                    }
                );

                learningUnitInput.value = "";
                learningUnitInput.disabled = !selectedModuleId;

            }
        );

    }

    document
        .querySelectorAll(
            "[data-create-assessment-button]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    form.reset();

                    if (learningUnitInput) {

                        learningUnitOptions.forEach(
                            (option) => {

                                if (option.value) {
                                    option.hidden = true;
                                }

                            }
                        );

                        learningUnitInput.disabled = true;

                    }

                    create.open();

                }
            );

        });

    form.addEventListener(
        "submit",
        async (event) => {

            event.preventDefault();

            const formData =
                new FormData(form);

            try {

                const result =
                    await createAssessment(

                        window.coursesConfig.offeringId,

                        moduleInput.value,

                        learningUnitInput.value,

                        formData

                    );

                if (!result.success) {

                    if (!csrfExpired(result)) alert(result.message);

                    return;

                }

                create.close();

                form.reset();

                // Go straight to the new assessment's editor.
                if (onCreated && result.assessment_id) {

                    await onCreated(result.assessment_id);

                    return;

                }

                refresh();

            }

            catch (error) {

                console.error(error);

            }

        }
    );

}