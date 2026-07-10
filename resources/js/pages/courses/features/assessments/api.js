import state from "../../core/state.js";

export async function fetchAssessments()
{
    const response = await fetch(

        `${state.apiBaseUrl}/tabs/assessments`,

        {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        }

    );

    if (!response.ok) {
        throw new Error("Unable to load course assessments.");
    }

    return response.json();
}