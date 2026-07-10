import { loadFeature } from "./renderer.js";
import state from "../core/state.js";

export function initializeNavigation()
{
    document.addEventListener("click", handleNavigation);

    window.addEventListener(
        "popstate",
        handleBrowserNavigation
    );
}

function handleNavigation(event)
{
    const button = event.target.closest("[data-course-feature]");

    if (!button) {
        return;
    }

    event.preventDefault();

    const feature = button.dataset.courseFeature;

    history.pushState(

        {},

        "",

        `${state.courseBaseUrl}/${feature}`

    );

    loadFeature(feature);
}

function handleBrowserNavigation()
{
    const segments = location.pathname
        .split("/")
        .filter(Boolean);

    loadFeature(segments.at(-1));
}