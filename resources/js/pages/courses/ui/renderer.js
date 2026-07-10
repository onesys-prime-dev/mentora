import state from "../core/state.js";
import features from "../core/features.js";

import { activate } from "./tabs.js";
import { showLoader, hideLoader } from "./loader.js";

let currentFeature = null;

export function initializeRenderer()
{
    state.offeringId = window.coursesConfig.offeringId;

    state.role = window.coursesConfig.role;

    state.apiBaseUrl = window.coursesConfig.apiBaseUrl;

    state.courseBaseUrl = window.coursesConfig.courseBaseUrl;

    state.activeFeature = window.coursesConfig.activeFeature;

    loadFeature(state.activeFeature);
}

export async function loadFeature(featureName)
{
    const feature = features[featureName];

    if (!feature) {

        console.error(`Unknown feature: ${featureName}`);

        return;

    }

    if (
        currentFeature &&
        typeof currentFeature.destroy === "function"
    ) {
        currentFeature.destroy();
    }

    currentFeature = feature;

    state.activeFeature = featureName;

    activate(featureName);

    showLoader();

    try {

        await feature.render();

        if (typeof feature.initialize === "function") {

            feature.initialize();

        }

    }
    catch (error) {

        console.error(error);

    }
    finally {

        hideLoader();

    }
}