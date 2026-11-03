/*
|--------------------------------------------------------------------------
| Messages — choosing the members of a new staff group
|--------------------------------------------------------------------------
| Looks people up by name through the server, which lists only those the
| chairperson or dean may add. Typing is debounced, an answer for a query
| that has since been replaced is dropped, and the chosen people are kept
| by id so a person is never picked twice.
|--------------------------------------------------------------------------
*/

const MIN = 2;

export const MAX_MEMBERS = 30;

export function createGroupPicker({ search, onChange = () => {}, debounce = 250 }) {
    let timer = null;
    let latest = 0;
    const chosen = new Map();

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

                const people = status === 200 && Array.isArray(data?.candidates) ? data.candidates : null;

                picker.results = people ?? [];
                picker.state = people === null ? "error" : people.length === 0 ? "empty" : "idle";
                onChange();
            }, debounce);
        },

        toggle(entry) {
            if (chosen.has(entry.id)) {
                chosen.delete(entry.id);
            } else {
                chosen.set(entry.id, entry);
            }

            onChange();
        },

        isChosen: (id) => chosen.has(id),

        chosen: () => [...chosen.values()],

        ids: () => [...chosen.keys()],

        reset() {
            clearTimeout(timer);
            latest += 1;
            chosen.clear();
            picker.results = [];
            picker.state = "idle";
            onChange();
        },
    };

    return picker;
}
