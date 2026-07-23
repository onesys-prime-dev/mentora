export async function fetchModules()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Modules.");

    }

    return response.json();
}

export async function fetchLearningItem(learningItemId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/modules/items/${learningItemId}`,
        {
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json",
            },
        }
    );

    if (!response.ok) {
        throw new Error("Unable to load learning item.");
    }

    return response.json();
}