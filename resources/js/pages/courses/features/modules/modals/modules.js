import { csrfExpired } from "../../../../../shared/csrf-expired.js";

import {
    createModule,
    updateModule,
    deleteModule,
} from "../api.js";

/*
|--------------------------------------------------------------------------
| Module Modals
|--------------------------------------------------------------------------
|
| refresh reloads whatever screen is currently showing (the list on
| index.php, or the module itself on module.php). onDeleted runs instead
| after a successful delete, since the module that screen depends on may
| no longer exist — defaults to refresh (fine on the list; module.php
| passes its own "go back to the list" callback).
|
*/

export function bindModuleModals({
    editor,
    delete: deleteModal,
    refresh,
    onDeleted = refresh,
}) {

    bindEditor(editor, refresh);

    bindDelete(deleteModal, onDeleted);

}

/*
|--------------------------------------------------------------------------
| Module Editor
|--------------------------------------------------------------------------
*/

function bindEditor(modal, refresh) {

    const form =
        document.querySelector("#module-editor-form");

    if (!form) {
        return;
    }

    const mode =
        form.querySelector("[name='mode']");

    const moduleId =
        form.querySelector("[name='module_id']");

    const title =
        form.querySelector("[name='title']");

    const description =
        form.querySelector("[name='description']");

    const heading =
        document.querySelector("[data-module-editor-title]");

    const subtitle =
        document.querySelector("[data-module-editor-subtitle]");

    const submit =
        document.querySelector("[data-module-editor-submit]");

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    document
        .querySelector("[data-create-module-button]")
        ?.addEventListener("click", () => {

            form.reset();

            mode.value = "create";

            moduleId.value = "";

            heading.textContent =
                "Add Module";

            subtitle.textContent =
                "Create a new learning module for this course.";

            submit.textContent =
                "Create Module";

            modal.open();

        });

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll("[data-edit-module]")
        .forEach((button) => {

            button.addEventListener("click", () => {

                mode.value = "edit";

                moduleId.value =
                    button.dataset.moduleId;

                title.value =
                    button.dataset.moduleTitle;

                description.value =
                    button.dataset.moduleDescription;

                heading.textContent =
                    "Edit Module";

                subtitle.textContent =
                    "Update this learning module.";

                submit.textContent =
                    "Save Changes";

                modal.open();

            });

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

                if (mode.value === "create") {

                    result =
                        await createModule(
                            window.coursesConfig.offeringId,
                            formData
                        );

                } else {

                    result =
                        await updateModule(
                            window.coursesConfig.offeringId,
                            moduleId.value,
                            formData
                        );

                }

                if (!result.success) {

                    if (!csrfExpired(result)) alert(result.message);

                    return;

                }

                modal.close();

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
| Delete Module
|--------------------------------------------------------------------------
*/

function bindDelete(modal, onDeleted) {

    const form =
        document.querySelector("#module-delete-form");

    if (!form) {
        return;
    }

    const moduleId =
        form.querySelector("[name='module_id']");

    const title =
        document.querySelector("[data-delete-module-title]");

    document
        .querySelectorAll("[data-delete-module]")
        .forEach((button) => {

            button.addEventListener("click", () => {

                moduleId.value =
                    button.dataset.moduleId;

                if (title) {

                    title.textContent =
                        button.dataset.moduleTitle;

                }

                modal.open();

            });

        });

    form.addEventListener(
        "submit",
        async (event) => {

            event.preventDefault();

            try {

                const result =
                    await deleteModule(

                        window.coursesConfig.offeringId,

                        moduleId.value

                    );

                if (!result.success) {

                    if (!csrfExpired(result)) alert(result.message);

                    return;

                }

                modal.close();

                onDeleted();

            }

            catch (error) {

                console.error(error);

            }

        }
    );

}