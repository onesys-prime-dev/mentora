function defaultHeaders() {
    return {
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",
    };
}

function buildQuery(filters = {}) {

    const params = new URLSearchParams();

    if (filters.search) { params.set("search", filters.search); }
    if (filters.sort) { params.set("sort", filters.sort); }
    if (filters.pinned) { params.set("pinned", "1"); }
    if (filters.page) { params.set("page", String(filters.page)); }
    if (filters.focus) { params.set("focus", String(filters.focus)); }

    const qs = params.toString();

    return qs ? `?${qs}` : "";

}

export async function fetchAnnouncements(filters = {}) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements${buildQuery(filters)}`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load Announcements.");
    }

    return response.json();

}

export async function fetchMoreAnnouncements(filters = {}) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/more${buildQuery(filters)}`,
        { headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to load more announcements.");
    }

    return response.json();

}

export async function createAnnouncement(payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements`,
        {
            method: "POST",
            headers: { ...defaultHeaders(), "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to post the announcement.");
    }

    return response.json();

}

export async function updateAnnouncement(announcementId, payload) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/${announcementId}`,
        {
            method: "PATCH",
            headers: { ...defaultHeaders(), "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to update the announcement.");
    }

    return response.json();

}

export async function deleteAnnouncement(announcementId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/${announcementId}`,
        { method: "DELETE", headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to delete the announcement.");
    }

    return response.json();

}

export async function togglePinAnnouncement(announcementId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/${announcementId}/pin`,
        { method: "PATCH", headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to pin the announcement.");
    }

    return response.json();

}

export async function uploadAnnouncementAttachment(file) {

    const formData = new FormData();
    formData.append("file", file);

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/attachments`,
        { method: "POST", headers: defaultHeaders(), body: formData }
    );

    if (!response.ok) {
        throw new Error("Unable to upload that file.");
    }

    return response.json();

}

export async function replyToAnnouncement(announcementId, message) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/${announcementId}/reply`,
        {
            method: "POST",
            headers: { ...defaultHeaders(), "Content-Type": "application/json" },
            body: JSON.stringify({ message }),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to send that reply.");
    }

    return response.json();

}

export async function deleteAnnouncementReply(replyId) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/replies/${replyId}`,
        { method: "DELETE", headers: defaultHeaders() }
    );

    if (!response.ok) {
        throw new Error("Unable to delete that reply.");
    }

    return response.json();

}

export async function reactToAnnouncement(announcementId, emoji) {

    const response = await fetch(
        `${window.coursesConfig.apiBaseUrl}/announcements/${announcementId}/react`,
        {
            method: "POST",
            headers: { ...defaultHeaders(), "Content-Type": "application/json" },
            body: JSON.stringify({ emoji }),
        }
    );

    if (!response.ok) {
        throw new Error("Unable to react to that announcement.");
    }

    return response.json();

}
