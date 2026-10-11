/**
 * Toasts: the short "what just happened" message at the bottom right.
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
    success: "border-[#2E7D4F]/40 bg-[#E8F5EC] text-[#1F5A38]",
    warning: "border-[#946200]/40 bg-[#FFF1D6] text-[#6F4A00]",
    danger: "border-[#C33939]/40 bg-[#FDEDEC] text-[#8E2424]",
    info: "border-[#0040A6]/30 bg-white text-[#1A1C1E]",
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
        box.className = "pointer-events-none fixed bottom-6 right-6 z-[70] flex w-[min(24rem,calc(100vw-3rem))] flex-col gap-2";
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
    const timeout = options.timeout ?? (tone === "danger" ? 0 : 5000);

    const toast = document.createElement("div");
    toast.setAttribute("data-toast", tone);
    toast.className = `pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg ${TONES[tone]}`;

    const text = document.createElement("p");
    text.className = "min-w-0 flex-1";

    const label = document.createElement("span");
    label.className = "sr-only";
    label.textContent = `${LABELS[tone]}: `;

    text.append(label, document.createTextNode(message));

    const close = document.createElement("button");
    close.type = "button";
    close.setAttribute("aria-label", "Dismiss");
    close.className = "shrink-0 rounded px-1 text-lg leading-none opacity-70 transition hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50";
    close.textContent = "×";
    close.addEventListener("click", () => toast.remove());

    toast.append(text, close);
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
}
