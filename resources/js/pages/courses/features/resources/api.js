import state from "../../core/state.js";

export async function fetchResources()
{
    const response = await fetch(

        `${state.apiBaseUrl}/tabs/resources`,

        {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        }

    );

    if (!response.ok) {
        throw new Error("Unable to load course resources.");
    }

    return response.json();
}