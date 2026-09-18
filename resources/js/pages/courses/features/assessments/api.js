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

export async function fetchQuizBuilder(
    offeringId,
    assessmentId
) {

    const response = await fetch(

        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/quiz-builder`,

        {

            headers: defaultHeaders(),

        }

    );

    if (!response.ok) {

        throw new Error("Unable to load quiz builder.");

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

/*
|--------------------------------------------------------------------------
| Quiz Questions
|--------------------------------------------------------------------------
*/

export async function fetchQuestion(offeringId, questionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/questions/${questionId}`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load question.");
    }

    return response.json();

}

export async function createQuestion(offeringId, assessmentId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/questions`,
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
        throw new Error("Unable to create question.");
    }

    return response.json();

}

export async function updateQuestion(offeringId, questionId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/questions/${questionId}`,
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
        throw new Error("Unable to update question.");
    }

    return response.json();

}

export async function deleteQuestion(offeringId, questionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/questions/${questionId}`,
        {
            method: "DELETE",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to delete question.");
    }

    return response.json();

}

export async function duplicateQuestion(offeringId, questionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/questions/${questionId}/duplicate`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to duplicate question.");
    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Quiz Attempt (student taking a quiz)
|--------------------------------------------------------------------------
*/

export async function fetchQuizAttempt(offeringId, assessmentId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/take`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load the quiz.");
    }

    return response.json();

}

export async function saveQuizAnswer(offeringId, submissionId, questionId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/questions/${questionId}/answer`,
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
        throw new Error("Unable to save your answer.");
    }

    return response.json();

}

export async function submitQuizAttempt(offeringId, submissionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/submit`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to submit the quiz.");
    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Grade Work
|--------------------------------------------------------------------------
*/

export async function fetchGradeWork(offeringId, assessmentId, studentId) {

    const url = studentId
        ? `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/grade-work/${studentId}`
        : `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/grade-work`;

    const response = await fetch(url, { headers: defaultHeaders() });

    if (!response.ok) {
        throw new Error("Unable to load grade work.");
    }

    return response.json();

}

export async function startGrading(offeringId, submissionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/start-grading`,
        {
            method: "PATCH",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to start grading.");
    }

    return response.json();

}

export async function submitGrade(offeringId, submissionId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/grade`,
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
        throw new Error("Unable to submit grade.");
    }

    return response.json();

}

export async function reorderQuestions(offeringId, assessmentId, questionIds) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/questions/reorder`,
        {
            method: "PATCH",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ question_ids: questionIds }),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to reorder questions.");
    }

    return response.json();

}