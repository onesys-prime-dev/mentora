/*
|--------------------------------------------------------------------------
| Messages — API client
|--------------------------------------------------------------------------
| One method per endpoint under messages/api. Every method resolves
| { status, data } and never throws: a network failure is status 0, a reply
| that is not JSON (a 404 page, say) has data null. The CSRF header is added
| by shared/csrf.js, which wraps fetch.
|--------------------------------------------------------------------------
*/

function query(params) {
    const pairs = Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== "");

    return pairs.length === 0 ? "" : `?${pairs.map(([key, value]) => `${key}=${encodeURIComponent(value)}`).join("&")}`;
}

export function createApi(base, fetchImpl = (...args) => fetch(...args)) {
    async function request(method, path, body) {
        const init = { method, headers: { Accept: "application/json" }, credentials: "same-origin" };

        if (body !== undefined) {
            init.headers["Content-Type"] = "application/json";
            init.body = JSON.stringify(body);
        }

        try {
            const response = await fetchImpl(`${base}/${path}`, init);
            const data = await response.json().catch(() => null);

            return { status: response.status, data };
        } catch {
            return { status: 0, data: null };
        }
    }

    return {
        conversations: ({ filter, unread } = {}) => request("GET", `conversations${query({ filter: filter === "all" ? undefined : filter, unread: unread ? 1 : undefined })}`),
        start: (recipientId, subjectStudentId) => request("POST", "conversations", { recipient_id: recipientId, subject_student_id: subjectStudentId ?? null }),
        page: (id, { before, after, limit } = {}) => request("GET", `conversations/${id}/messages${query({ before, after, limit })}`),
        send: (id, body, clientUuid) => request("POST", `conversations/${id}/messages`, { body, client_uuid: clientUuid }),
        markRead: (id, messageId) => request("POST", `conversations/${id}/read`, { message_id: messageId }),
        hide: (id, messageId) => request("POST", `conversations/${id}/messages/${messageId}/hide`, {}),
        block: (userId) => request("POST", "blocks", { user_id: userId }),
        unblock: (userId) => request("POST", `blocks/${userId}/remove`, {}),
        report: (id, messageId, reason, note) => request("POST", `conversations/${id}/messages/${messageId}/report`, { reason, note }),
        preferences: () => request("GET", "preferences"),
        savePreferences: (prefs) => request("POST", "preferences", prefs),
        groupCandidates: (q) => request("GET", `group-candidates${query({ q })}`),
        createGroup: (title, memberIds) => request("POST", "groups", { title, member_ids: memberIds }),
        remove: (id, messageId) => request("POST", `conversations/${id}/messages/${messageId}/remove`, {}),
        lock: (id) => request("POST", `conversations/${id}/lock`, {}),
        unlock: (id) => request("POST", `conversations/${id}/unlock`, {}),
        unread: () => request("GET", "unread-count"),
        recipients: (q) => request("GET", `recipients${query({ q })}`),
    };
}
