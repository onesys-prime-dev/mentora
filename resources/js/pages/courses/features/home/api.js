import state from "../../core/state.js";

export async function fetchHome()
{
    const response = await fetch(

        `${state.apiBaseUrl}/tabs/home`,

        {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        }

    );

    if (!response.ok) {
        throw new Error("Unable to load course home.");
    }

    return response.json();
}