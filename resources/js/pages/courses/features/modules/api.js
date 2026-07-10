import state from "../../core/state.js";

export async function fetchModules()
{
    const response = await fetch(

        `${state.apiBaseUrl}/tabs/modules`,

        {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        }

    );

    if (!response.ok) {
        throw new Error("Unable to load course modules.");
    }

    return response.json();
}