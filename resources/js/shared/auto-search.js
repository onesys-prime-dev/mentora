/*
|--------------------------------------------------------------------------
| A search box that searches as you type
|--------------------------------------------------------------------------
| <input data-auto-search> inside a GET form: a short pause after the last key
| sends the form, so there is no button to press. The page reloads with the
| result, so the box takes focus again with the caret after what was typed.
*/

const PAUSE = 400;

document.addEventListener("input", (event) => {
    const input = event.target;

    if (!(input instanceof HTMLInputElement) || !("autoSearch" in input.dataset) || !input.form) {
        return;
    }

    clearTimeout(input.__autoSearchTimer);
    input.__autoSearchTimer = setTimeout(() => input.form.requestSubmit(), PAUSE);
});

const searched = document.querySelector("input[data-auto-search]");

if (searched && searched.value !== "") {
    searched.focus();
    searched.setSelectionRange(searched.value.length, searched.value.length);
}
