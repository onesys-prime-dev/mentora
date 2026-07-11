export async function fetchGrade()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/grade`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Grade.");

    }

    return response.json();
}