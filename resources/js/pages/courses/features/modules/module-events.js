/*
|--------------------------------------------------------------------------
| Module Accordion
|--------------------------------------------------------------------------
|
| Handles expanding and collapsing course modules.
|
*/

import { state } from "./state.js";

export function bindModuleEvents() {

    const modules =
        document.querySelectorAll("[data-module]");

    if (!modules.length) {
        return;
    }

    modules.forEach((module) => {

        module.addEventListener("click", () => {

            const moduleId =
                module.dataset.module;

            toggleModule(moduleId);

        });

    });

}

function toggleModule(moduleId) {

    const content =
        document.querySelector(
            `[data-module-content="${moduleId}"]`
        );

    const arrow =
        document.querySelector(
            `[data-module-arrow="${moduleId}"]`
        );

    if (!content || !arrow) {
        return;
    }

    const isOpen =
        content.classList.contains("max-h-[2000px]");

    closeAllModules();

    if (isOpen) {

        state.activeModuleId = null;

        updateModuleWrappers();

        return;

    }

    openModule(
        content,
        arrow
    );

    state.activeModuleId = Number(moduleId);

    updateModuleWrappers();

}

function closeAllModules() {

    document
        .querySelectorAll("[data-module-content]")
        .forEach((content) => {

            content.classList.remove(
                "max-h-[2000px]",
                "opacity-100",
                "pb-6"
            );

            content.classList.add(
                "max-h-0",
                "opacity-0"
            );

        });

    document
        .querySelectorAll("[data-module-arrow]")
        .forEach((arrow) => {

            arrow.classList.remove(
                "rotate-180"
            );

        });

    updateModuleWrappers();

}

function openModule(
    content,
    arrow
) {

    content.classList.remove(
        "max-h-0",
        "opacity-0"
    );

    content.classList.add(
        "max-h-[2000px]",
        "opacity-100",
        "pb-6"
    );

    arrow.classList.add(
        "rotate-180"
    );

}

export function updateModuleWrappers() {

    document

        .querySelectorAll("[data-module-wrapper]")

        .forEach(wrapper => {

            const active =

                Number(wrapper.dataset.moduleId) ===
                state.activeModuleId;

            wrapper.classList.toggle(
                "bg-blue-100/50",
                active
            );

            wrapper.classList.toggle(
                "rounded-2xl",
                active
            );

        });

}