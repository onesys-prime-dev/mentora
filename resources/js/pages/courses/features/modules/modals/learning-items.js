/*
|--------------------------------------------------------------------------
| Learning Item Create Modal
|--------------------------------------------------------------------------
*/

import { createLearningItem } from "../api.js";

export function bindLearningItemCreateModal({
    create,
    refresh,
    onCreated = null,
}) {

    if (!create) {
        return;
    }

    const form =
        document.querySelector(
            "#learning-item-create-form"
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

    document
        .querySelectorAll(
            "[data-create-learning-item-button]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    form.reset();

                    moduleInput.value =
                        button.dataset.moduleId;

                    learningUnitInput.value =
                        button.dataset.learningUnitId;

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
                    await createLearningItem(

                        window.coursesConfig.offeringId,

                        moduleInput.value,

                        learningUnitInput.value,

                        formData

                    );

                if (!result.success) {

                    alert(result.message);

                    return;

                }

                create.close();

                form.reset();

                // Go straight to the new item's editor.
                if (onCreated && result.learning_item_id) {

                    await onCreated(result.learning_item_id);

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