/*
|--------------------------------------------------------------------------
| Messages — the new-message picker
|--------------------------------------------------------------------------
| Looks people up by name through the server, which lists only those the
| person may start a conversation with. Typing is debounced, and an answer
| for a query that has since been replaced is dropped.
|--------------------------------------------------------------------------
*/

const MIN = 2;

export function createPicker({ search, onPick, onChange = () => {}, debounce = 250 }) {
    let timer = null;
    let latest = 0;

    const picker = {
        results: [],
        state: "idle",

        input(text) {
            clearTimeout(timer);

            const query = text.trim();
            const mine = ++latest;

            if ([...query].length < MIN) {
                picker.results = [];
                picker.state = "idle";
                onChange();

                return;
            }

            picker.state = "loading";
            onChange();

            timer = setTimeout(async () => {
                const { status, data } = await search(query);

                if (mine !== latest) {
                    return;
                }

                const people = status === 200 && Array.isArray(data?.recipients) ? data.recipients : null;

                picker.results = people ?? [];
                picker.state = people === null ? "error" : people.length === 0 ? "empty" : "idle";
                onChange();
            }, debounce);
        },

        pick(entry) {
            onPick(entry);
        },
    };

    return picker;
}
