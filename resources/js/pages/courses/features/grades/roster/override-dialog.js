/*
|--------------------------------------------------------------------------
| What the final-grade dialog shows
|--------------------------------------------------------------------------
| Where the institution has deans, changing a final grade is a request that
| climbs a chain, and the dialog has three faces:
|   - no open request  -> "Request an override" (who it goes to is spelled out)
|   - sent back        -> the chairperson's comment, the request to edit and send
|                         again, or take back
|   - waiting          -> where it stands, with only "Withdraw request"
| Elsewhere the dialog is the plain override it always was. This file only
| decides which face from the row's data; roster.js draws it and sends it.
|--------------------------------------------------------------------------
*/

/**
 * @param {"direct"|"request"} mode  from the grid: how a change to a final grade is made
 * @param {string} route             "It goes to the chairperson (...) to endorse, then to the dean to decide."
 * @param {Record<string, string|undefined>} data  the row's data-* attributes (a button's dataset)
 */
export function overrideDialogState(mode, route, data)
{
    if (mode !== "request") {
        return {
            mode: "direct",
            title: "Override Final Grade",
            submitLabel: "Save",
            placeholder: "Leave blank to clear the override",
            note: "",
            requestId: null,
            statusText: "",
            readOnly: false,
            canWithdraw: false,
            percentage: data.isOverride === "1" ? (data.currentPercentage ?? "") : "",
            reason: data.isOverride === "1" ? (data.overrideReason ?? "") : "",
        };
    }

    const base = {
        mode: "request",
        placeholder: "Leave blank to ask for the override to be removed",
        requestId: null,
        statusText: "",
        readOnly: false,
        canWithdraw: false,
        percentage: "",
        reason: "",
    };

    if (!data.requestId) {
        return {
            ...base,
            title: "Request an override",
            submitLabel: "Send request",
            note: `This does not change the grade. ${route}`.trim(),
        };
    }

    const asked = data.requestPercentage === undefined || data.requestPercentage === ""
        ? "removing the override"
        : `${data.requestPercentage}%`;

    if (data.requestStatus === "returned") {
        return {
            ...base,
            title: "Edit your request",
            submitLabel: "Send again",
            note: "It was sent back. Change it and send it again, or withdraw it.",
            requestId: data.requestId,
            statusText: `Sent back${data.requestComment ? `: “${data.requestComment}”` : "."}`,
            canWithdraw: true,
            percentage: data.requestPercentage ?? "",
            reason: data.requestReason ?? "",
        };
    }

    return {
        ...base,
        title: "Your request",
        submitLabel: "",
        note: "The grade stays as calculated until it is decided.",
        requestId: data.requestId,
        statusText: `Asked for ${asked}: waiting for ${data.requestWaiting || "the next person"}.`,
        readOnly: true,
        canWithdraw: true,
        percentage: data.requestPercentage ?? "",
        reason: data.requestReason ?? "",
    };
}
