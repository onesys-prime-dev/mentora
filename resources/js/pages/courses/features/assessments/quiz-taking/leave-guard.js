/*
|--------------------------------------------------------------------------
| Leaving a quiz that is in progress
|--------------------------------------------------------------------------
| While the attempt is open the student is warned before they:
|
|   - refresh, close the tab or type another address: the browser's own
|     warning (beforeunload). Browsers do not let a page choose its words;
|   - follow a link, a course tab or the back arrow inside the page, or press the
|     browser's Back button: our own dialog, with the words for the quiz
|     (leaveMessage). Answers are saved as they are typed either way.
|
| The warning stops when stop() is called (the quiz was handed in) or the quiz
| form has left the page.
*/

export function leaveMessage(timed) {

    return timed
        ? "Your answers are saved, but the timer keeps running while you're away. Leave anyway?"
        : "Your answers are saved. Leave this page?";

}

// Things inside the page that take the student somewhere else.
const LEAVERS = "[data-course-feature], [data-back-to-assessment-view]";

/**
 * @param {object} options
 * @param {HTMLFormElement|null} options.form the quiz's answer form
 * @param {boolean} options.timed whether the quiz has a time limit
 * @param {(words: {title: string, text: string, yes: string, no: string}) => Promise<boolean>} options.ask
 * @param {(url: string) => void} [options.assign] goes to an address (a test replaces it)
 * @param {{pushState: Function, back: Function}} [options.history]
 * @param {string} [options.quizUrl] the quiz's own address, put back when Back is held
 * @returns {{active: boolean, stop: () => void}}
 */
export function bindLeaveGuard({
    form,
    timed,
    ask,
    assign = (url) => window.location.assign(url),
    history: pageHistory = window.history,
    quizUrl = window.location.href,
}) {

    if (!form) {
        return { active: false, stop() {} };
    }

    const listeners = [];

    function on(target, type, handler, options) {

        target.addEventListener(type, handler, options);
        listeners.push(() => target.removeEventListener(type, handler, options));

    }

    function stop() {

        listeners.splice(0).forEach((remove) => remove());

    }

    const live = () => {

        if (form.isConnected) {
            return true;
        }

        stop();

        return false;

    };

    const words = () => ({
        title: "Leave this quiz?",
        text: leaveMessage(timed),
        yes: "Leave anyway",
        no: "Stay",
    });

    let asking = false;

    /** Asks once at a time; runs `proceed` after the guard is off when the answer is yes. */
    async function confirmLeave(proceed) {

        if (asking) {
            return;
        }

        asking = true;

        try {

            if (await ask(words())) {

                stop();
                proceed();

            }

        } finally {

            asking = false;

        }

    }

    // Refresh, close, another address.
    on(window, "beforeunload", (event) => {

        if (live()) {

            event.preventDefault();
            event.returnValue = "";

        }

    });

    // Links and the page's own ways out. Capturing, so it comes before whatever the page does with them.
    on(document, "click", (event) => {

        if (!live() || !(event.target instanceof Element)) {
            return;
        }

        const link = event.target.closest("a[href]");

        if (link && !isLeavingLink(link)) {
            return;
        }

        const leaver = link ?? event.target.closest(LEAVERS);

        if (!leaver) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        confirmLeave(() => (link ? assign(link.href) : leaver.click()));

    }, true);

    // The browser's Back button: the page has already moved, so put the quiz's address back and ask.
    on(window, "popstate", (event) => {

        if (!live()) {
            return;
        }

        event.stopImmediatePropagation();
        pageHistory.pushState(null, "", quizUrl);

        confirmLeave(() => pageHistory.back());

    }, true);

    return { active: true, stop };

}

/** A link that would take the page away: not a new tab, a download, a script or a place within this page. */
function isLeavingLink(link) {

    const href = link.getAttribute("href") ?? "";
    const hrefNormalized = href.trim().toLowerCase();

    if (
        link.target === "_blank" ||
        link.hasAttribute("download") ||
        hrefNormalized.startsWith("#") ||
        hrefNormalized.startsWith("javascript:") ||
        hrefNormalized.startsWith("data:") ||
        hrefNormalized.startsWith("vbscript:")
    ) {
        return false;
    }

    return true;

}
