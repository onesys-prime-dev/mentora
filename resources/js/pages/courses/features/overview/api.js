export async function fetchOverview()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/overview`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Overview.");

    }

    return response.json();
}