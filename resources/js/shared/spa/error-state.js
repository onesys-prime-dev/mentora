/*
|--------------------------------------------------------------------------
| Shared "couldn't load" state with a Retry button
|--------------------------------------------------------------------------
|
| A failed load must never look like an empty list or sit as a skeleton
| forever. This replaces the bare "<p>Unable to load X.</p>" lines: an icon
| and a heading (not colour alone), a one-line explanation, and a Retry that
| re-runs whatever load failed. role="alert" so it is announced once.
|
*/

const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => (
    { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]
));

/**
 * @param {HTMLElement} target   element whose content becomes the error
 * @param {string} what          e.g. "Announcements" -> "We couldn't load Announcements"
 * @param {(() => unknown)|null} retry
 */
export function showLoadError(target, what, retry = null) {

    target.removeAttribute("data-spa-skeleton");

    target.innerHTML = `
        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert">
            <svg class="h-10 w-10 text-[#C33939]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            <span class="text-sm font-semibold text-[#1A1C1E]">We couldn't load ${escapeHtml(what)}</span>
            <span class="text-xs text-[#646464]">Check your connection and try again.</span>
            ${retry ? "<button type=\"button\" data-retry class=\"rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2\">Try again</button>" : ""}
        </div>`;

    if (retry) {

        const button = target.querySelector("[data-retry]");

        button.addEventListener("click", () => retry());

        button.focus({ preventScroll: true });

    }

}
