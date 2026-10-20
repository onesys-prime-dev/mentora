function defaultHeaders()
{
    return {
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",
    };
}

function base()
{
    return `${window.coursesConfig.apiBaseUrl}/grades`;
}

async function request(url, method = "GET", body = undefined)
{
    const options = { method, headers: defaultHeaders() };

    if (body !== undefined) {
        options.headers = { ...options.headers, "Content-Type": "application/json" };
        options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    if (!response.ok) {
        throw new Error(`Request failed (${response.status}).`);
    }

    return response.json();
}

export async function fetchGrades()
{
    const response = await fetch(base(), { headers: defaultHeaders() });

    if (!response.ok) {
        throw new Error("Unable to load Grades.");
    }

    return response.json();
}

/*
|--------------------------------------------------------------------------
| Grading scheme
|--------------------------------------------------------------------------
*/

export const fetchGradingScheme = () => request(`${base()}/scheme`);

export const updateGradingScheme = (payload) => request(`${base()}/scheme`, "PATCH", payload);

export const createGradeCategory = (payload) => request(`${base()}/scheme/categories`, "POST", payload);

export const updateGradeCategory = (categoryId, payload) =>
    request(`${base()}/scheme/categories/${categoryId}`, "PATCH", payload);

export const deleteGradeCategory = (categoryId) =>
    request(`${base()}/scheme/categories/${categoryId}`, "DELETE");

export const createGradeItem = (categoryId, payload) =>
    request(`${base()}/scheme/categories/${categoryId}/items`, "POST", payload);

export const updateGradeItem = (itemId, payload) =>
    request(`${base()}/scheme/items/${itemId}`, "PATCH", payload);

export const deleteGradeItem = (itemId) => request(`${base()}/scheme/items/${itemId}`, "DELETE");

export const resyncGradeItem = (itemId) => request(`${base()}/scheme/items/${itemId}/resync`, "POST");

export const recalculateGrades = () => request(`${base()}/recalculate`, "POST");

export const fetchFinalization = () => request(`${base()}/finalization`);

export const finalizeGrades = () => request(`${base()}/finalization`, "POST");

export const reopenGrades = (reason) => request(`${base()}/finalization/reopen`, "POST", { reason });

/*
|--------------------------------------------------------------------------
| Grading periods
|--------------------------------------------------------------------------
*/

export const createGradingPeriod = (payload) => request(`${base()}/scheme/periods`, "POST", payload);

export const updateGradingPeriod = (periodId, payload) =>
    request(`${base()}/scheme/periods/${periodId}`, "PATCH", payload);

export const deleteGradingPeriod = (periodId) => request(`${base()}/scheme/periods/${periodId}`, "DELETE");

/*
|--------------------------------------------------------------------------
| Gradebook grid (manual scores + override)
|--------------------------------------------------------------------------
*/

export const fetchGradebookRoster = () => request(`${base()}/roster`);

export const updateManualScore = (itemId, studentId, score) =>
    request(`${base()}/roster/items/${itemId}/students/${studentId}`, "PATCH", { score });

export const setIncomplete = (studentId, incomplete, reason = "", until = "") =>
    request(`${base()}/roster/students/${studentId}/incomplete`, "PATCH", { incomplete, reason, until });

export const overrideGrade = (studentId, percentage, reason) =>
    request(`${base()}/roster/students/${studentId}/override`, "PATCH", { percentage, reason });

/*
|--------------------------------------------------------------------------
| Offline grade sheet
|--------------------------------------------------------------------------
*/

export function gradeSheetExportUrl(format = "xlsx")
{
    return `${base()}/export?format=${encodeURIComponent(format)}`;
}

export async function previewGradeSheet(formData)
{
    const response = await fetch(`${base()}/import/preview`, {
        method: "POST",
        headers: defaultHeaders(),
        body: formData,
    });

    if (!response.ok) {
        throw new Error("Unable to read the grade sheet.");
    }

    return response.json();
}

export const applyGradeSheet = (payload) => request(`${base()}/import/apply`, "POST", payload);

/*
|--------------------------------------------------------------------------
| Requests to override a final grade (where the institution has deans)
|--------------------------------------------------------------------------
*/

export const updateOverrideRequest = (requestId, percentage, reason) =>
    request(`${base()}/override-requests/${requestId}`, "PATCH", { percentage, reason });

export const withdrawOverrideRequest = (requestId) =>
    request(`${base()}/override-requests/${requestId}`, "DELETE");
