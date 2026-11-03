/*
|--------------------------------------------------------------------------
| Messages — polling schedule
|--------------------------------------------------------------------------
| Short polling instead of push (design 6.2). The task runs every `fast`
| ms while the tab is visible and every `slow` ms while it is hidden or
| after a failure. A task answers "ok", or "gone" when the conversation is
| no longer the person's, which stops the poller for good. A tick never
| starts while the last one is still running.
|--------------------------------------------------------------------------
*/

export function createPoller({ task, visible = () => true, fast, slow = 30000, setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = (id) => clearTimeout(id) }) {
    let handle = null;
    let pending = false;
    let running = false;

    function disarm() {
        if (handle !== null) {
            clearTimer(handle);
            handle = null;
        }
    }

    function arm(delay = visible() ? fast : slow) {
        disarm();

        if (running) {
            handle = setTimer(tick, delay);
        }
    }

    async function run() {
        pending = true;

        let outcome;

        try {
            outcome = await task();
        } catch {
            outcome = "error";
        }

        pending = false;

        if (outcome === "gone") {
            running = false;
            disarm();

            return;
        }

        arm(outcome === "error" ? slow : undefined);
    }

    function tick() {
        handle = null;

        if (!running) {
            return;
        }

        if (pending) {
            arm();

            return;
        }

        run();
    }

    return {
        start() {
            running = true;
            arm();
        },

        stop() {
            running = false;
            disarm();
        },

        nudge() {
            if (!running || pending) {
                return;
            }

            disarm();
            run();
        },
    };
}

/** Polls again at once when the tab comes back. Returns a function that removes the listener. */
export function watchVisibility(poller, doc = document) {
    const onChange = () => {
        if (doc.visibilityState === "visible") {
            poller.nudge();
        }
    };

    doc.addEventListener("visibilitychange", onChange);

    return () => doc.removeEventListener("visibilitychange", onChange);
}

/** Numbers each request so an answer that arrives after the person moved on can be dropped. */
export function createGuard() {
    let current = 0;

    return {
        next: () => ++current,
        isCurrent: (n) => n === current,
    };
}
