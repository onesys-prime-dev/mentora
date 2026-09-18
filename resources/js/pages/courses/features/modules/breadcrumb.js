/*
|--------------------------------------------------------------------------
| Breadcrumb
|--------------------------------------------------------------------------
|
| Handles breadcrumb navigation within the Modules feature.
|
*/

export function bindBreadcrumbEvents(refresh) {

    const breadcrumb =
        document.querySelector(
            "[data-modules-breadcrumb]"
        );

    if (!breadcrumb) {
        return;
    }

    breadcrumb.addEventListener(
        "click",
        async () => {

            history.pushState(
                {},
                "",
                window.location.pathname
            );

            await refresh();

        }
    );

}