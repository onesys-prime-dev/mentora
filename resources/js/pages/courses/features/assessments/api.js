export async function fetchAssessments()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/assessments`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Assessments.");

    }

    return response.json();
}