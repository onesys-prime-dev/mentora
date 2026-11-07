/*
|--------------------------------------------------------------------------
| Learning Item Create Modal
|--------------------------------------------------------------------------
*/

import { createLearningItem } from "../api.js";
import { csrfExpired } from "../../../../../shared/csrf-expired.js";
import { bindPublishFields } from "./publish-fields.js";

// A quiz is published with its questions, and a new one has none.
const QUIZ_FIRST = "A quiz needs a question before it can be published. Add it as a draft first.";

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

    const publish =
        bindPublishFields(form.querySelector("[data-publish-fields]"));

    const keepQuizDraft = () => {

        publish.lockPublish(
            form.querySelector("[name='item_type']:checked")?.value === "quiz" ? QUIZ_FIRST : null
        );

    };

    form
        .querySelectorAll("[name='item_type']")
        .forEach((input) => input.addEventListener("change", keepQuizDraft));

    document
        .querySelectorAll(
            "[data-create-learning-item-button]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    form.reset();

                    publish.reset();

                    // Hidden from students while its module, or else its unit, is a draft (the Hidden chip's rule).
                    publish.setHiddenBy(
                        button.dataset.moduleStatus !== "published"
                            ? "module"
                            : (button.dataset.unitStatus !== "published" ? "unit" : null)
                    );

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

                    if (!csrfExpired(result)) alert(result.message);

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