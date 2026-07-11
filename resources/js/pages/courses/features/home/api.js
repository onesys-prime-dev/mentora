export async function fetchHome()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/home`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Home.");

    }

    return response.json();
}