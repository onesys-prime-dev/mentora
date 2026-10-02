function buildQuery(params = {}) {

    const query = new URLSearchParams();

    if (params.module) { query.set("module", params.module); }
    if (params.unit) { query.set("unit", params.unit); }
    if (params.search) { query.set("search", params.search); }
    if (params.sort) { query.set("sort", params.sort); }
    if (params.type) { query.set("type", params.type); }
    if (params.view) { query.set("view", params.view); }

    const qs = query.toString();

    return qs ? `?${qs}` : "";

}

export async function fetchResources(params = {})
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/resources${buildQuery(params)}`,

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
