/*
|--------------------------------------------------------------------------
| Modules API
|--------------------------------------------------------------------------
|
| Handles all HTTP requests for the Modules feature.
|
*/

function defaultHeaders() {

    return {

        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",

    };

}

/*
|--------------------------------------------------------------------------
| Modules
|--------------------------------------------------------------------------
*/

export async function fetchModules() {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules`,

        {

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load modules.");

    }

    return response.json();

}

export async function createModule(
    offeringId,
    formData
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules`,

        {

            method: "POST",

            headers: defaultHeaders(),

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error("Unable to create module.");

    }

    return response.json();

}

export async function updateModule(
    offeringId,
    moduleId,
    formData
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}`,

        {

            method: "POST",

            headers: defaultHeaders(),

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error("Unable to update module.");

    }

    return response.json();

}

export async function updateModuleStatus(
    moduleId,
    status
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}/status`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify({

                status,

            }),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to update module status."
        );

    }

    return response.json();

}

export async function deleteModule(
    offeringId,
    moduleId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}`,

        {

            method: "DELETE",

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to delete module.");

    }

    return response.json();

}

export async function reorderModules(order) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/reorder`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type": "application/json",

            },

            body: JSON.stringify({

                order,

            }),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to reorder modules."
        );

    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Learning Units
|--------------------------------------------------------------------------
*/

export async function createLearningUnit(
    offeringId,
    moduleId,
    formData
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}/learning-units`,

        {

            method: "POST",

            headers: defaultHeaders(),

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error("Unable to create learning unit.");

    }

    return response.json();

}

export async function updateLearningUnit(
    offeringId,
    moduleId,
    learningUnitId,
    formData
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}/learning-units/${learningUnitId}`,

        {

            method: "POST",

            headers: defaultHeaders(),

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error("Unable to update learning unit.");

    }

    return response.json();

}

export async function updateLearningUnitStatus(
    learningUnitId,
    status
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/learning-units/${learningUnitId}/status`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type": "application/json",

            },

            body: JSON.stringify({

                status,

            }),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to update learning unit status."
        );

    }

    return response.json();

}

export async function deleteLearningUnit(
    offeringId,
    moduleId,
    learningUnitId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}/learning-units/${learningUnitId}`,

        {

            method: "DELETE",

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to delete learning unit.");

    }

    return response.json();

}

export async function reorderLearningUnits(
    units
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/learning-units/reorder`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify({

                learning_units:
                    units,

            }),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to reorder learning units."
        );

    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Learning Items
|--------------------------------------------------------------------------
*/

export async function fetchLearningItemView(
    learningItemId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/items/${learningItemId}`,

        {

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load learning item.");

    }

    return response.json();

}

export async function fetchLearningItemEditor(
    learningItemId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/items/edit/${learningItemId}`,

        {

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load learning item editor.");

    }

    return response.json();

}

export async function createLearningItem(
    offeringId,
    moduleId,
    learningUnitId,
    formData
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}/learning-units/${learningUnitId}/learning-items`,

        {

            method: "POST",

            headers: defaultHeaders(),

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to create learning item."
        );

    }

    return response.json();

}

export async function updateLearningItem(
    learningItemId,
    data
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/items/${learningItemId}`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify(data),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to update learning item."
        );

    }

    return response.json();

}

export async function deleteLearningItem(
    learningItemId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/items/${learningItemId}`,

        {

            method: "DELETE",

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to delete learning item."
        );

    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Update Video Learning Item
|--------------------------------------------------------------------------
|
| Updates:
| - learning_items.content
| - learning_resources
|
*/

export async function updateVideoItem(
    learningItemId,
    formData
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/items/${learningItemId}/video`,

        {

            method: "POST",

            headers: {
                ...defaultHeaders(),
            },

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to update video learning item."
        );

    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Learning Item Ordering
|--------------------------------------------------------------------------
*/

export async function reorderLearningItems(
    items
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/items/reorder`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify({

                items,

            }),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to reorder learning items."
        );

    }

    return response.json();

}