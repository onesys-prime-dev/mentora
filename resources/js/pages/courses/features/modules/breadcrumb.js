/*
|--------------------------------------------------------------------------
| Breadcrumb
|--------------------------------------------------------------------------
|
| Handles breadcrumb navigation within the Modules feature. "Modules"
| always goes to the plain list, regardless of which page we're on;
| "<module name>" always goes to that module's own page. Both targets
| are context-independent, so the handlers here are self-sufficient —
| nothing needs to be threaded in from the caller.
|
*/

import { initialize as goToModulesList } from "./index.js";
import { loadModule } from "./module.js";

export function bindBreadcrumbEvents() {

    const modulesCrumb =
        document.querySelector(
            "[data-modules-breadcrumb]"
        );

    modulesCrumb?.addEventListener(
        "click",
        async () => {

            history.pushState(
                {},
                "",
                window.location.pathname
            );

            await goToModulesList();

        }
    );

    const moduleCrumb =
        document.querySelector(
            "[data-module-breadcrumb]"
        );

    moduleCrumb?.addEventListener(
        "click",
        async () => {

            const moduleId =
                Number(moduleCrumb.dataset.moduleId);

            if (!moduleId) {
                return;
            }

            await loadModule(moduleId, goToModulesList);

        }
    );

}
