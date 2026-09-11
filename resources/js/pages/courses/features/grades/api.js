export async function fetchGrades()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/grades`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Grades.");

    }

    return response.json();
}