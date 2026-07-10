import state from "../../core/state.js";

export async function fetchGrade()
{
    const response = await fetch(

        `${state.apiBaseUrl}/tabs/grade`,

        {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        }

    );

    if (!response.ok) {
        throw new Error("Unable to load course grade.");
    }

    return response.json();
}