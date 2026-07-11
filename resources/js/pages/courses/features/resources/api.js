export async function fetchResources()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/resources`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Resources.");

    }

    return response.json();
}