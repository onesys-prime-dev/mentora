/*
|--------------------------------------------------------------------------
| Messages — safety words
|--------------------------------------------------------------------------
| The reasons a message can be reported for, and the plain sentence to show
| when a block, report or setting could not be saved. The server decides
| everything; this only chooses words.
|--------------------------------------------------------------------------
*/

export const REASONS = {
    harassment: "Harassment",
    spam: "Spam",
    inappropriate: "Inappropriate",
    other: "Something else",
};

/**
 * @param {{status: number, data: any}} result a reply from the api client
 * @param {string} fallback what to say for any other failure
 */
export function describeFailure(result, fallback) {
    if (result.status === 429) {
        return result.data?.message ?? "You're doing that too fast. Wait a moment and try again.";
    }

    if (result.status === 0) {
        return "Couldn't reach the server. Check your connection and try again.";
    }

    return fallback;
}
