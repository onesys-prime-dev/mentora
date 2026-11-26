/*
|--------------------------------------------------------------------------
| Messages — the new-message picker
|--------------------------------------------------------------------------
| Looks people up by name through the server, which lists only those the
| person may start a conversation with, a page at a time (more() reads the
| next page from the server's cursor). Typing is debounced, and an answer
| for a query that has since been replaced is dropped.
|--------------------------------------------------------------------------
*/

const MIN = 2;

export function createPicker({ search, onPick, onChange = () => {}, debounce = 250 }) {
    let timer = null;
    let latest = 0;
    let current = "";

    const picker = {
        results: [],
        state: "idle",
        next: null,

        input(text) {
            clearTimeout(timer);

            const query = text.trim();
            const mine = ++latest;

            current = query;
            picker.next = null;

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
                picker.next = people === null ? null : (data.next ?? null);
                // Sent only to a parent with no linked child, about themselves.
                picker.state = people === null ? "error" : data.reason === "no_linked_child" ? "no_child" : people.length === 0 ? "empty" : "idle";
                onChange();
            }, debounce);
        },

        async more() {
            const mine = latest;
            const after = picker.next;

            if (after === null) {
                return;
            }

            picker.next = null;
            const { status, data } = await search(current, after);

            if (mine !== latest) {
                return;
            }

            if (status === 200 && Array.isArray(data?.recipients)) {
                picker.results = [...picker.results, ...data.recipients];
                picker.next = data.next ?? null;
            } else {
                picker.next = after;
                picker.state = "error";
            }

            onChange();
        },

        pick(entry) {
            onPick(entry);
        },
    };

    return picker;
}
