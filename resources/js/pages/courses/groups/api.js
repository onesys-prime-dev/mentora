// Importing the CSRF helper puts the token on every change this module sends.
import "../../../shared/csrf.js";

const base = () => window.coursesConfig.apiBaseUrl;

const HEADERS = { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" };

async function getJson(url, what) {

    const response = await fetch(url, { headers: HEADERS, credentials: "same-origin" });

    if (!response.ok) {
        throw new Error(`Unable to load ${what}.`);
    }

    return response.json();

}

/** The tab's HTML: the set list and the dialogs. */
export const fetchGroupsTab = () => getJson(`${base()}/groups`, "Groups");

/** One set: { set, groups: [{ id, name, members }], ungrouped, limits }. */
export const fetchGroupSet = (setId) => getJson(`${base()}/group-sets/${setId}`, "the group set");

/**
 * A change. Resolves { ok, status, body } and never throws for an answer the server gave
 * (a 422 carries { message, errors }); a network failure rejects.
 */
export async function sendChange(method, path, body = {}, root = "/group-sets") {

    const response = await fetch(`${base()}${root}${path}`, {
        method,
        headers: { ...HEADERS, "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify(body),
    });

    return { ok: response.ok, status: response.status, body: await response.json().catch(() => ({})) };

}

/** An assignment's (or, with kind "learning-items", an item's) groups: { config|null, groups, ungrouped, sets, activities, flags, lockReason }. */
export const fetchActivityGroups = (id, kind = "assessments") => getJson(`${base()}/${kind}/${id}/groups`, "the activity's groups");

/** Student proposals waiting for approval and what is left after the window: { proposals, minSize, summary }. */
export const fetchActivityProposals = (assessmentId) => getJson(`${base()}/assessments/${assessmentId}/groups/proposals`, "the proposed groups");

/** A change to an assignment's (or item's) groups; answers as sendChange. */
export const sendActivityChange = (id, method, path, body = {}, kind = "assessments") => sendChange(method, path, body, `/${kind}/${id}/groups`);

/** Grade Work's group view: { groups: [{ id, name, status, memberCount }], ungrouped, ungroupedPolicy, blockers, flags, lockReason }. */
export const fetchGroupGrading = (assessmentId) => getJson(`${base()}/assessments/${assessmentId}/group-grading`, "the groups to grade");

/** One group to grade: { group, status, config, criteria, submission, groupScores, groupScore, feedback, members, flags, lockReason }. */
export const fetchGroupGradingDetail = (assessmentId, groupId) => getJson(`${base()}/assessments/${assessmentId}/group-grading/${groupId}`, "the group's work");

/** A grading write (save, reopen, a decision); answers as sendChange. */
export const sendGroupGrading = (assessmentId, path, body = {}) => sendChange("POST", path, body, `/assessments/${assessmentId}/group-grading`);

/** The student's own group of an activity: { isGroupActivity, myGroup, candidates, ... }. */
export const fetchMyGroup = (id, kind = "assessments") => getJson(`${base()}/${kind}/${id}/my-group`, "your group");

/** join | leave | switch; answers as sendChange. */
export const sendMyGroup = (id, action, body = {}, kind = "assessments") => sendChange("POST", `/${action}`, body, `/${kind}/${id}/my-group`);
