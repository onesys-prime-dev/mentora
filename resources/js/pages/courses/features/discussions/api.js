export async function fetchDiscussions()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/discussions`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Discussions.");

    }

    return response.json();
}