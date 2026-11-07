/*
|--------------------------------------------------------------------------
| What the quiz page watches while a student answers
|--------------------------------------------------------------------------
| Two options of a quiz, both honest about what a web page can do:
|
|   - data-detect-tab-switch: records that the student LEFT the page (the tab
|     was hidden, or the window lost focus) and for how long. A page cannot see
|     which tab or site they went to, so that is not recorded and not claimed.
|
|   - data-lock-browser: asks for fullscreen and turns off copying, cutting,
|     pasting, the right-click menu and Ctrl/Cmd+P, recording each attempt and
|     leaving fullscreen. This discourages leaving; it cannot prevent it (only
|     a locked-down browser can), and the page tells the student as much.
|
| What is seen is handed to record() (the server keeps it for the teacher).
| The watching stops when stop() is called or the form leaves the page (the quiz
| was handed in).
*/

const BLOCKED = ["copy", "cut", "paste", "contextmenu"];

const MESSAGES = {
    copy: "Copying is turned off for this quiz.",
    cut: "Cutting is turned off for this quiz.",
    paste: "Pasting is turned off for this quiz.",
    contextmenu: "The right-click menu is turned off for this quiz.",
    print: "Printing is turned off for this quiz.",
};

/**
 * @param {object} options
 * @param {HTMLFormElement|null} options.form the quiz's answer form, which carries which options are on
 * @param {(event: object) => void} options.record gives an event to the server
 * @param {(message: string) => void} options.notify tells the student something short
 * @param {() => number} [options.now] the clock in milliseconds (a test replaces it)
 * @returns {{active: boolean, stop: () => void}}
 */
export function bindAttemptGuard({ form, record, notify, now = () => Date.now() }) {

    const detect = form?.dataset.detectTabSwitch === "1";
    const lock = form?.dataset.lockBrowser === "1";

    if (!form || (!detect && !lock)) {
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

    // Handed in: the form is gone, so the watching is over.
    const live = () => {

        if (form.isConnected) {
            return true;
        }

        stop();

        return false;

    };

    /*
    | Leaving the page
    */

    if (detect) {

        let leftAt = null;

        const leave = () => {

            if (live() && leftAt === null) {
                leftAt = now();
            }

        };

        const back = () => {

            if (!live() || leftAt === null) {
                return;
            }

            const seconds = Math.round((now() - leftAt) / 1000);

            leftAt = null;

            // A blink (a click on the browser's own bar) is not leaving.
            if (seconds >= 1) {
                record({ kind: "left_page", duration_seconds: seconds });
            }

        };

        // The tab hiding and the window losing focus are one absence, counted once.
        on(document, "visibilitychange", () => (document.visibilityState === "hidden" ? leave() : back()));
        on(window, "blur", leave);
        on(window, "focus", back);

    }

    /*
    | The lock
    */

    if (lock) {

        const blocked = (detail) => {

            record({ kind: "blocked_action", detail });
            notify(MESSAGES[detail]);

        };

        BLOCKED.forEach((type) => {

            on(document, type, (event) => {

                if (!live()) {
                    return;
                }

                event.preventDefault();
                blocked(type);

            });

        });

        on(document, "keydown", (event) => {

            if (live() && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "p") {
                event.preventDefault();
                blocked("print");
            }

        });

        // Printing from the browser's menu cannot be stopped from here, only recorded.
        on(window, "beforeprint", () => {

            if (live()) {
                record({ kind: "blocked_action", detail: "print" });
            }

        });

        document.querySelector("[data-guard-fullscreen]")?.addEventListener("click", async () => {

            try {

                await document.documentElement.requestFullscreen();

            } catch {

                notify("Your browser did not allow fullscreen. Try the F11 key.");

            }

        });

        let wasFullscreen = false;

        on(document, "fullscreenchange", () => {

            if (!live()) {
                return;
            }

            if (document.fullscreenElement) {

                wasFullscreen = true;

                return;

            }

            if (wasFullscreen) {

                wasFullscreen = false;
                record({ kind: "left_fullscreen" });
                notify("You left fullscreen. This is recorded. Press Go fullscreen to continue in fullscreen.");

            }

        });

    }

    return { active: true, stop };

}
