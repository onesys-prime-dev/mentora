export async function fetchModules()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Modules.");

    }

    return response.json();
}