/*
|--------------------------------------------------------------------------
| Learning Unit Modals
|--------------------------------------------------------------------------
|
| Handles Create, Update, and Delete Learning Units.
|
*/

import {
    createLearningUnit,
    updateLearningUnit,
    deleteLearningUnit,
} from "../api.js";

import { state } from "../state.js";

export function bindLearningUnitModals({

    editor,
    delete: deleteModal,
    refresh,

}) {

    bindLearningUnitEditor(
        editor,
        refresh
    );

    bindLearningUnitDelete(
        deleteModal,
        refresh
    );

}

/*
|--------------------------------------------------------------------------
| Editor
|--------------------------------------------------------------------------
*/

function bindLearningUnitEditor(
    modalController,
    refresh
) {

    if (!modalController) {
        return;
    }

    const form =
        document.querySelector(
            "#learning-unit-editor-form"
        );

    if (!form) {
        return;
    }

    let currentModuleId = null;

    const modeInput =
        form.querySelector(
            "[name='mode']"
        );

    const idInput =
        form.querySelector(
            "[name='learning_unit_id']"
        );

    const titleInput =
        form.querySelector(
            "[name='title']"
        );

    const descriptionInput =
        form.querySelector(
            "[name='description']"
        );

    const heading =
        document.querySelector(
            "[data-learning-unit-editor-title]"
        );

    const subtitle =
        document.querySelector(
            "[data-learning-unit-editor-subtitle]"
        );

    const submitButton =
        document.querySelector(
            "[data-learning-unit-editor-submit]"
        );

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            "[data-create-learning-unit-button]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    currentModuleId =
                        Number(
                            button.dataset.moduleId
                        );

                    form.reset();

                    modeInput.value =
                        "create";

                    idInput.value =
                        "";

                    heading.textContent =
                        "Add Learning Unit";

                    subtitle.textContent =
                        "Create a new learning unit for this module.";

                    submitButton.textContent =
                        "Create Learning Unit";

                    modalController.open();

                }
            );

        });

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            "[data-edit-learning-unit]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    currentModuleId =
                        Number(
                            button.dataset.moduleId
                        );

                    modeInput.value =
                        "edit";

                    idInput.value =
                        button.dataset.learningUnitId;

                    titleInput.value =
                        button.dataset.learningUnitTitle;

                    descriptionInput.value =
                        button.dataset.learningUnitDescription;

                    heading.textContent =
                        "Edit Learning Unit";

                    subtitle.textContent =
                        "Update this learning unit.";

                    submitButton.textContent =
                        "Save Changes";

                    modalController.open();

                }
            );

        });

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        "submit",
        async (event) => {

            event.preventDefault();

            const formData =
                new FormData(form);

            let result;

            try {

                if (
                    modeInput.value ===
                    "create"
                ) {

                    result =
                        await createLearningUnit(

                            window.coursesConfig.offeringId,

                            currentModuleId,

                            formData

                        );

                }

                else {

                    result =
                        await updateLearningUnit(

                            window.coursesConfig.offeringId,

                            currentModuleId,

                            idInput.value,

                            formData

                        );

                }

                if (!result.success) {

                    alert(
                        result.message
                    );

                    return;

                }

                modalController.close();

                form.reset();

                refresh();

            }

            catch (error) {

                console.error(error);

            }

        }
    );

}

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

function bindLearningUnitDelete(
    modalController,
    refresh
) {

    if (!modalController) {
        return;
    }

    const form =
        document.querySelector(
            "#learning-unit-delete-form"
        );

    if (!form) {
        return;
    }

    let currentModuleId = state.activeModuleId ?? null;

    const idInput =
        form.querySelector(
            "[name='learning_unit_id']"
        );

    document
        .querySelectorAll(
            "[data-delete-learning-unit]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    currentModuleId =
                        Number(
                            button.dataset.moduleId
                        );

                    idInput.value =
                        button.dataset.learningUnitId;

                    modalController.open();

                }
            );

        });

    form.addEventListener(
        "submit",
        async (event) => {

            event.preventDefault();

            try {

                const result =
                    await deleteLearningUnit(

                        window.coursesConfig.offeringId,

                        currentModuleId,

                        idInput.value

                    );

                if (!result.success) {

                    alert(
                        result.message
                    );

                    return;

                }

                modalController.close();

                refresh();

            }

            catch (error) {

                console.error(error);

            }

        }
    );

}