/*
|--------------------------------------------------------------------------
| A search box that searches as you type
|--------------------------------------------------------------------------
| <input data-auto-search> inside a GET form: a short pause after the last key
| sends the form, so there is no button to press. The form is sent as it stands,
| so the other filters beside the box keep their values.
|
| The page reloads with the result, so the box that was being typed in takes
| focus again with the caret after what was typed. Only that box does: a box
| that merely arrives filled (a bookmarked link, the Apply button) is left alone.
*/

const PAUSE = 400;
const KEY = "autoSearchFocus";

function remember(input) {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname, name: input.name }));
    } catch (error) {
        // Private windows can refuse storage; the search still works, only the focus is lost.
    }
}

document.addEventListener("input", (event) => {
    const input = event.target;

    if (!(input instanceof HTMLInputElement) || !("autoSearch" in input.dataset) || !input.form) {
        return;
    }

    clearTimeout(input.__autoSearchTimer);

    input.__autoSearchTimer = setTimeout(() => {
        remember(input);
        input.form.requestSubmit();
    }, PAUSE);
});

// The x that clears a search box fires "search", not "input": clearing should show everything again.
document.addEventListener("search", (event) => {
    const input = event.target;

    if (input instanceof HTMLInputElement && "autoSearch" in input.dataset && input.form && input.value === "") {
        clearTimeout(input.__autoSearchTimer);
        remember(input);
        input.form.requestSubmit();
    }
});

try {
    const saved = JSON.parse(sessionStorage.getItem(KEY) ?? "null");

    sessionStorage.removeItem(KEY);

    if (saved && saved.path === location.pathname) {
        const input = [...document.querySelectorAll("input[data-auto-search]")].find((candidate) => candidate.name === saved.name);

        if (input) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }
    }
} catch (error) {
    // Nothing remembered, nothing to restore.
}
