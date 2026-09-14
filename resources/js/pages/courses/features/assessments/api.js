function defaultHeaders() {
    return {
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",
    };
}

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

export async function createAssessment(offeringId, moduleId, learningUnitId, formData)
{
    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/modules/${moduleId}/learning-units/${learningUnitId}/assessments`,

        {

            method: "POST",

            headers: defaultHeaders(),

            body: formData,

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to create assessment."
        );

    }

    return response.json();
}

export async function updateAssessment(offeringId, assessmentId, payload)
{
    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify(payload),

        }

    );

    if (!response.ok) {

        throw new Error(
            "Unable to update assessment."
        );

    }

    return response.json();
}

export async function deleteAssessment(offeringId, assessmentId)
{
    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}`,

        {
            method: "DELETE",

            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {

        throw new Error("Unable to delete assessment.");

    }

    return response.json();
}

export async function fetchAssessmentView(
    offeringId,
    assessmentId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/view`,

        {

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load assessment view.");

    }

    return response.json();

}

export async function fetchAssessmentEditor(
    offeringId,
    assessmentId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/editor`,

        {

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load assessment editor.");

    }

    return response.json();

}

export async function updateAssessmentInstructions(
    offeringId,
    assessmentId,
    instructions
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/instructions`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify({
                instructions,
            }),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to save instructions.");

    }

    return response.json();

}

export async function updateSubmissionSettings(
    offeringId,
    assessmentId,
    settings
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/submission-settings`,

        {

            method: "PATCH",

            headers: {

                ...defaultHeaders(),

                "Content-Type":
                    "application/json",

            },

            body: JSON.stringify(settings),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to save submission settings.");

    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Rubrics
|--------------------------------------------------------------------------
*/

export async function fetchRubrics(offeringId, assessmentId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/rubrics`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load rubrics.");
    }

    return response.json();

}

export async function fetchRubric(offeringId, rubricId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/rubrics/${rubricId}`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load rubric.");
    }

    return response.json();

}

export async function createRubric(offeringId, assessmentId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/rubrics`,
        {
            method: "POST",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to create rubric.");
    }

    return response.json();

}

export async function selectRubric(offeringId, assessmentId, rubricId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/rubrics/${rubricId}/select`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to select rubric.");
    }

    return response.json();

}

export async function updateRubric(offeringId, rubricId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/rubrics/${rubricId}`,
        {
            method: "PATCH",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to update rubric.");
    }

    return response.json();

}

export async function deleteRubric(offeringId, rubricId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/rubrics/${rubricId}`,
        {
            method: "DELETE",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to delete rubric.");
    }

    return response.json();

}

export async function createRubricCriterion(offeringId, rubricId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/rubrics/${rubricId}/criteria`,
        {
            method: "POST",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to add criteria.");
    }

    return response.json();

}

export async function updateRubricCriterion(offeringId, criterionId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/rubric-criteria/${criterionId}`,
        {
            method: "PATCH",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to update criteria.");
    }

    return response.json();

}

export async function deleteRubricCriterion(offeringId, criterionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/rubric-criteria/${criterionId}`,
        {
            method: "DELETE",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to delete criteria.");
    }

    return response.json();

}