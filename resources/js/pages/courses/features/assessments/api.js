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

export async function clearAllQuestions(offeringId, assessmentId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/questions`,
        {
            method: "DELETE",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to clear questions.");
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

export async function fetchGradeWork(offeringId, assessmentId, studentId, attemptId = null) {

    const base = studentId
        ? `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/grade-work/${studentId}`
        : `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/grade-work`;

    const url = attemptId ? `${base}?attempt=${attemptId}` : base;

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

/**
 * Puts graded work back into Grading so its grade can be changed.
 */
export async function reopenGrading(offeringId, submissionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/reopen-grading`,
        {
            method: "PATCH",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to reopen grading.");
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

export async function gradeQuestion(offeringId, submissionId, questionId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/questions/${questionId}/grade`,
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
        throw new Error("Unable to grade that question.");
    }

    return response.json();

}

export async function postFeedback(offeringId, submissionId, message) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/submissions/${submissionId}/feedback`,
        {
            method: "POST",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ message }),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to send feedback.");
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

/*
|--------------------------------------------------------------------------
| AI Question Generation
|--------------------------------------------------------------------------
*/

export async function startGeneration(offeringId, formData) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generate-questions`,
        {
            method: "POST",
            headers: defaultHeaders(),
            body: formData,
        }
    );

    if (!response.ok) {
        throw new Error("Unable to start question generation.");
    }

    return response.json();

}

export async function fetchNextChunk(offeringId, batchId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generation-batches/${batchId}/next-chunk`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to generate the next chunk.");
    }

    return response.json();

}

export async function fetchGenerationBatch(offeringId, batchId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generation-batches/${batchId}`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load generation batch.");
    }

    return response.json();

}

export async function cancelGenerationBatch(offeringId, batchId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generation-batches/${batchId}/cancel`,
        { method: "POST", headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to cancel generation.");
    }

    return response.json();

}

export async function fetchPendingGeneration(offeringId, assessmentId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/pending-generation`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to check for pending questions.");
    }

    return response.json();

}

export async function updateGeneratedQuestion(offeringId, generatedQuestionId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generated-questions/${generatedQuestionId}`,
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
        throw new Error("Unable to update the generated question.");
    }

    return response.json();

}

export async function acceptGeneratedQuestion(offeringId, generatedQuestionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generated-questions/${generatedQuestionId}/accept`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to accept the generated question.");
    }

    return response.json();

}

export async function discardGeneratedQuestion(offeringId, generatedQuestionId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generated-questions/${generatedQuestionId}/discard`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to discard the generated question.");
    }

    return response.json();

}

export async function acceptAllGeneratedQuestions(offeringId, batchId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/generation-batches/${batchId}/accept-all`,
        {
            method: "POST",
            headers: defaultHeaders(),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to accept all generated questions.");
    }

    return response.json();

}

/*
|--------------------------------------------------------------------------
| Import / Export
|--------------------------------------------------------------------------
*/

/**
 * @param {{versions?: number, paper?: string, shuffleQuestions?: boolean, shuffleChoices?: boolean, keyOnly?: boolean}} [print]
 *   only for the printable test
 */
export function exportQuestionsUrl(offeringId, assessmentId, format = "docx", print = {}) {

    const params = new URLSearchParams({ format });

    if (format.startsWith("test-")) {

        params.set("versions", String(print.versions || 1));
        params.set("paper", print.paper || "A4");

        if (print.shuffleQuestions) {
            params.set("shuffle_questions", "1");
        }

        if (print.shuffleChoices) {
            params.set("shuffle_choices", "1");
        }

        if (print.keyOnly) {
            params.set("key_only", "1");
        }

    }

    return `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/export?${params.toString()}`;

}

/**
 * The server answers 200 with {success:false, message} for an import it
 * understood but refuses, and 422 with the same body for a file it will not
 * read; either way the teacher is shown the server's own sentence.
 */
async function importJson(response, fallback) {

    try {
        const body = await response.json();

        if (body && typeof body === "object") {
            return body;
        }
    } catch {
        // Not JSON (a server error page): fall through to the fallback sentence.
    }

    throw new Error(fallback);

}

export function importSampleUrl(offeringId, assessmentId) {

    return `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/import/sample`;

}

export async function previewImport(offeringId, assessmentId, formData) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/import/preview`,
        {
            method: "POST",
            headers: defaultHeaders(),
            body: formData,
        }
    );

    return importJson(response, "Unable to read this file.");

}

/**
 * @param {{token: string, skip_keys: string[], remove_ids: number[], acknowledge_responses: boolean}} choices
 */
export async function applyImport(offeringId, assessmentId, choices) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/import/apply`,
        {
            method: "POST",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify(choices),
        }
    );

    return importJson(response, "Unable to apply this import.");

}

export async function undoImport(offeringId, assessmentId, token) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/import/undo`,
        {
            method: "POST",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ token }),
        }
    );

    return importJson(response, "Unable to undo this import.");

}

/*
|--------------------------------------------------------------------------
| Add from another quiz
|--------------------------------------------------------------------------
*/

export async function fetchQuestionSources(offeringId, assessmentId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/question-sources`,
        { headers: defaultHeaders() }
    );

    return importJson(response, "Unable to load your other quizzes.");

}

export async function fetchSourceQuestions(offeringId, assessmentId, sourceId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/question-sources/${sourceId}`,
        { headers: defaultHeaders() }
    );

    return importJson(response, "Unable to load the questions of that quiz.");

}

export async function copyQuestions(offeringId, assessmentId, sourceId, questionIds) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/assessments/${assessmentId}/question-sources/copy`,
        {
            method: "POST",
            headers: {
                ...defaultHeaders(),
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ source_assessment_id: sourceId, question_ids: questionIds }),
        }
    );

    return importJson(response, "Unable to add those questions.");

}
