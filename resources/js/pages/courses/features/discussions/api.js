function buildQuery(params = {}) {

    const query = new URLSearchParams();

    if (params.search) { query.set("search", params.search); }
    if (params.sort && params.sort !== "activity") { query.set("sort", params.sort); }
    if (params.filter && params.filter !== "all") { query.set("filter", params.filter); }
    if (params.page) { query.set("page", String(params.page)); }

    const qs = query.toString();

    return qs ? `?${qs}` : "";

}

const headers = {
    "X-Requested-With": "XMLHttpRequest",
    "Accept": "application/json",
};

export async function fetchDiscussions(params = {})
{
    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/discussions${buildQuery(params)}`,
        { headers }
    );

    if (!response.ok) {
        throw new Error("Unable to load Discussions.");
    }

    return response.json();
}

export async function fetchMoreDiscussions(params = {})
{
    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/discussions/more${buildQuery(params)}`,
        { headers }
    );

    if (!response.ok) {
        throw new Error("Unable to load more discussions.");
    }

    return response.json();
}

function jsonRequest(method, path, body) {

    return fetch(`${window.coursesConfig.apiBaseUrl}/discussions${path}`, {
        method,
        headers: { ...headers, ...(body !== undefined ? { "Content-Type": "application/json" } : {}) },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    }).then((response) => {

        if (!response.ok) {
            throw new Error("That didn't go through. Please try again.");
        }

        return response.json();

    });

}

export const fetchThread = (id) => jsonRequest("GET", `/${id}`);

export const postReply = (threadId, body, attachmentFileIds) =>
    jsonRequest("POST", `/${threadId}/reply`, { body, attachment_file_ids: attachmentFileIds });

export const removeReply = (replyId) => jsonRequest("DELETE", `/replies/${replyId}`);

export const reactTo = (threadId, replyId, emoji) =>
    jsonRequest("POST", `/${threadId}/react`, { reply_id: replyId, emoji });

export const markAnswer = (threadId, replyId) =>
    jsonRequest("POST", `/${threadId}/answer`, { reply_id: replyId });

export async function uploadDiscussionAttachment(file) {

    const formData = new FormData();
    formData.append("file", file);

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/discussions/attachments`,
        { method: "POST", headers, body: formData }
    );

    if (!response.ok) {
        throw new Error("Unable to upload that file.");
    }

    return response.json();

}

export const createThread = (payload) => jsonRequest("POST", "", payload);

export const updateThread = (threadId, payload) => jsonRequest("PATCH", `/${threadId}`, payload);

export const deleteThread = (threadId) => jsonRequest("DELETE", `/${threadId}`);

export const setThreadPinned = (threadId, value) => jsonRequest("PATCH", `/${threadId}/pin`, { value });

export const setThreadLocked = (threadId, value) => jsonRequest("PATCH", `/${threadId}/lock`, { value });
