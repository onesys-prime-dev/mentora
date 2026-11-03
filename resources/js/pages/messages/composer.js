/*
|--------------------------------------------------------------------------
| Messages — composer
|--------------------------------------------------------------------------
| One client_uuid per draft. A send that fails keeps its uuid, so pressing
| Retry cannot post the message twice: the server returns the original.
| Editing the text after a failure is a different draft and gets a new uuid.
|--------------------------------------------------------------------------
*/

export const MAX = 4000;

export function canSend(text, busy) {
    const length = [...text.trim()].length;

    return !busy && length >= 1 && length <= MAX;
}

export function createComposer({ send, onSent, onFailed }) {
    let uuid = null;
    let draft = null;

    const composer = {
        state: "idle",

        async submit(text) {
            if (!canSend(text, composer.state === "sending")) {
                return;
            }

            if (uuid === null || draft !== text) {
                uuid = crypto.randomUUID();
                draft = text;
            }

            composer.state = "sending";

            const { status, data } = await send(text, uuid);

            if (status === 200 || status === 201) {
                uuid = null;
                draft = null;
                composer.state = "idle";
                onSent(data.message);

                return;
            }

            if (status === 422) {
                uuid = null;
            }

            composer.state = "failed";
            onFailed(data?.error ?? "network");
        },
    };

    return composer;
}
