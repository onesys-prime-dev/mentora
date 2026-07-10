import state from "../../core/state.js";

export async function fetchDiscussions()
{
    const response = await fetch(

        `${state.apiBaseUrl}/tabs/discussions`,

        {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        }

    );

    if (!response.ok) {
        throw new Error("Unable to load course discussions.");
    }

    return response.json();
}