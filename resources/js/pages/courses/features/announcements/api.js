export async function fetchAnnouncements()
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/announcements`,

        {

            headers: {

                "X-Requested-With": "XMLHttpRequest",

                "Accept": "application/json",

            },

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load Announcements.");

    }

    return response.json();
}