/**
 * Toasts: the short "what just happened" message, a slim pill at the top centre.
 *
 *   showToast("Ann Lee was invited", { tone: "success" });
 *
 * A server redirect can leave a message in the page as
 * <div data-toast-message data-tone="success">text</div> (see
 * layouts/partials/flash-toasts.php); it is shown once and removed.
 *
 * The region is a polite live region, so a screen reader announces each
 * message without moving focus. Errors stay until closed; the others go after
 * a few seconds.
 */

const TONES = {
    success: { pill: "bg-[#0f5132] text-white", chip: "text-[#0f5132]", glyph: "✓" },
    warning: { pill: "bg-[#6f4a00] text-white", chip: "text-[#6f4a00]", glyph: "!" },
    danger: { pill: "bg-[#7f1d1d] text-white", chip: "text-[#7f1d1d]", glyph: "!" },
    info: { pill: "bg-[#1e3a8a] text-white", chip: "text-[#1e3a8a]", glyph: "i" },
};

const LABELS = { success: "Done", warning: "Warning", danger: "Problem", info: "Note" };

const REGION_ATTR = "data-toast-region";

function region() {
    let box = document.querySelector(`[${REGION_ATTR}]`);

    if (!box) {
        box = document.createElement("div");
        box.setAttribute(REGION_ATTR, "");
        box.setAttribute("role", "status");
        box.setAttribute("aria-live", "polite");
        box.className = "pointer-events-none fixed left-1/2 top-24 z-[70] flex w-[min(34rem,calc(100vw-2rem))] -translate-x-1/2 flex-col items-center gap-2";
        document.body.append(box);
    }

    return box;
}

/**
 * @param {string} message
 * @param {{ tone?: "success"|"warning"|"danger"|"info", timeout?: number }} [options] timeout in ms; 0 keeps it until closed
 * @returns {HTMLElement}
 */
export function showToast(message, options = {}) {
    const tone = options.tone && TONES[options.tone] ? options.tone : "info";
    const timeout = options.timeout ?? (tone === "danger" ? 0 : tone === "warning" ? 6000 : 4000);

    const toast = document.createElement("div");
    toast.setAttribute("data-toast", tone);
    toast.className = `pointer-events-auto flex max-w-full items-center gap-2.5 rounded-full py-2 pl-3 pr-2 text-sm shadow-lg ${TONES[tone].pill}`;

    const chip = document.createElement("span");
    chip.className = `grid h-5 w-5 shrink-0 place-items-center rounded-full bg-white text-xs font-bold leading-none ${TONES[tone].chip}`;
    chip.setAttribute("aria-hidden", "true");
    chip.textContent = TONES[tone].glyph;

    const text = document.createElement("p");
    text.className = "min-w-0 flex-1";

    const label = document.createElement("span");
    label.className = "sr-only";
    label.textContent = `${LABELS[tone]}: `;

    text.append(label, document.createTextNode(message));

    const close = document.createElement("button");
    close.type = "button";
    close.setAttribute("aria-label", "Dismiss");
    close.className = "grid h-6 w-6 shrink-0 place-items-center rounded-full text-lg leading-none opacity-70 transition hover:bg-white/15 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70";
    close.textContent = "×";
    close.addEventListener("click", () => toast.remove());

    toast.append(chip, text, close);
    region().append(toast);

    if (timeout > 0) {
        setTimeout(() => toast.remove(), timeout);
    }

    return toast;
}

/** Shows (and removes) the messages the server left in the page. */
export function showFlashToasts(root = document) {
    for (const el of root.querySelectorAll("[data-toast-message]")) {
        const message = (el.textContent ?? "").trim();

        if (message !== "") {
            showToast(message, { tone: el.dataset.tone ?? "info" });
        }

        el.remove();
    }
}

if (typeof document !== "undefined") {
    showFlashToasts();

    // Success messages drawn into a panel after load (a swapped course page, a saved form) turn into toasts too.
    if (typeof MutationObserver !== "undefined" && document.body) {
        new MutationObserver(() => {
            if (document.querySelector("[data-toast-message]")) {
                showFlashToasts();
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
}
