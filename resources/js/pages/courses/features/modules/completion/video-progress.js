/*
|--------------------------------------------------------------------------
| Video Watch Progress (students)
|--------------------------------------------------------------------------
|
| Counts how far a student has actually *played* a video: the furthest
| point reached by normal playback. Seeking backward is free; seeking past
| that point snaps back, so skipping ahead doesn't count. Progress is
| saved every few seconds (and on pause/end/leave) and "Mark as Completed"
| unlocks live at 90%. The server keeps the highest value it has seen.
|
| Uploaded videos use <video> events; YouTube uses the IFrame Player API.
| Other embeds can't report progress and aren't tracked.
|
*/

import { saveLearningItemProgress } from "../api.js";

import { enableMarkComplete } from "./completion.js";

const COMPLETE_PERCENT = 90;

const SAVE_EVERY_MS = 10000;

/** Largest forward step (seconds) that still counts as normal playback. */
const MAX_PLAY_STEP = 3;

let activeTracker = null;

export function initializeVideoProgress() {

    activeTracker?.flush();

    activeTracker = null;

    const element = document.querySelector("[data-video-progress]");

    if (!element) {
        return;
    }

    const tracker = createTracker(element);

    activeTracker = tracker;

    if (element.tagName === "VIDEO") {

        trackHtmlVideo(element, tracker);

    }

    else if (element.dataset.youtubeId) {

        trackYoutube(element, tracker);

    }

}

/**
 * Saves any unsaved progress now — call before leaving the item.
 */
export function flushVideoProgress() {

    activeTracker?.flush();

}

/*
|--------------------------------------------------------------------------
| Tracker
|--------------------------------------------------------------------------
*/

function createTracker(element) {

    const learningItemId = Number(element.dataset.learningItemId);

    const savedPercent = Number(element.dataset.progress) || 0;

    const bar = document.querySelector("[data-video-progress-bar]");

    const label = document.querySelector("[data-video-progress-label]");

    let duration = 0;

    let furthest = 0;

    let lastSavedPercent = savedPercent;

    let lastSaveAt = Date.now();

    let unlocked = savedPercent >= COMPLETE_PERCENT;

    const percent = () =>
        duration > 0
            ? Math.min(100, Math.floor((furthest / duration) * 100))
            : savedPercent;

    const save = () => {

        const current = percent();

        if (current <= lastSavedPercent) {
            return;
        }

        lastSavedPercent = current;

        lastSaveAt = Date.now();

        saveLearningItemProgress(learningItemId, current)
            .catch((error) => console.error(error));

    };

    const render = () => {

        const current = percent();

        if (bar) {
            bar.style.width = `${current}%`;
        }

        if (label) {
            label.textContent = `${current}% watched`;
        }

        if (!unlocked && current >= COMPLETE_PERCENT) {

            unlocked = true;

            save();

            enableMarkComplete();

        }

        else if (Date.now() - lastSaveAt >= SAVE_EVERY_MS) {

            save();

        }

    };

    return {

        element,

        setDuration(seconds) {

            if (!Number.isFinite(seconds) || seconds <= 0) {
                return;
            }

            duration = seconds;

            // Resume from what the server already has.
            furthest = Math.max(furthest, (savedPercent / 100) * duration);

        },

        get furthest() {
            return furthest;
        },

        /** Record a playback position; returns false if it skipped ahead. */
        observe(seconds) {

            if (seconds <= furthest) {
                return true;
            }

            if (seconds - furthest > MAX_PLAY_STEP) {
                return false;
            }

            furthest = seconds;

            render();

            return true;

        },

        finish() {

            if (duration > 0 && duration - furthest <= MAX_PLAY_STEP) {
                furthest = duration;
            }

            render();

            save();

        },

        flush: save,

    };

}

/*
|--------------------------------------------------------------------------
| Uploaded video (<video>)
|--------------------------------------------------------------------------
*/

function trackHtmlVideo(video, tracker) {

    const start = () => {

        tracker.setDuration(video.duration);

        if (tracker.furthest > 1 && tracker.furthest < video.duration - 1) {
            video.currentTime = tracker.furthest;
        }

    };

    if (video.readyState >= 1) {
        start();
    }

    else {
        video.addEventListener("loadedmetadata", start, { once: true });
    }

    video.addEventListener("timeupdate", () => {

        if (!video.seeking) {
            tracker.observe(video.currentTime);
        }

    });

    video.addEventListener("seeking", () => {

        if (video.currentTime > tracker.furthest + 1) {
            video.currentTime = tracker.furthest;
        }

    });

    video.addEventListener("pause", () => tracker.flush());

    video.addEventListener("ended", () => tracker.finish());

    bindLeave(tracker);

}

/*
|--------------------------------------------------------------------------
| YouTube (IFrame Player API)
|--------------------------------------------------------------------------
*/

function trackYoutube(iframe, tracker) {

    loadYoutubeApi().then((YT) => {

        let poll = null;

        const stop = () => {

            clearInterval(poll);

            poll = null;

        };

        const player = new YT.Player(iframe.id, {

            events: {

                onReady: () => tracker.setDuration(player.getDuration()),

                onStateChange: (event) => {

                    if (event.data === YT.PlayerState.PLAYING) {

                        tracker.setDuration(player.getDuration());

                        stop();

                        poll = setInterval(() => {

                            if (!iframe.isConnected) {

                                stop();

                                return;

                            }

                            if (!tracker.observe(player.getCurrentTime())) {
                                player.seekTo(tracker.furthest, true);
                            }

                        }, 500);

                    }

                    else {

                        stop();

                        if (event.data === YT.PlayerState.ENDED) {
                            tracker.finish();
                        }

                        else if (event.data === YT.PlayerState.PAUSED) {
                            tracker.flush();
                        }

                    }

                },

            },

        });

    }).catch((error) => console.error(error));

    bindLeave(tracker);

}

let youtubeApi = null;

function loadYoutubeApi() {

    if (window.YT?.Player) {
        return Promise.resolve(window.YT);
    }

    youtubeApi ??= new Promise((resolve, reject) => {

        const previous = window.onYouTubeIframeAPIReady;

        window.onYouTubeIframeAPIReady = () => {

            previous?.();

            resolve(window.YT);

        };

        const script = document.createElement("script");

        script.src = "https://www.youtube.com/iframe_api";

        script.onerror = () => reject(new Error("Couldn't load the YouTube player."));

        document.head.append(script);

    });

    return youtubeApi;

}

/*
|--------------------------------------------------------------------------
| Save when leaving the page
|--------------------------------------------------------------------------
*/

function bindLeave(tracker) {

    const onHide = () => {

        if (!tracker.element.isConnected) {

            document.removeEventListener("visibilitychange", onHide);

            return;

        }

        if (document.visibilityState === "hidden") {
            tracker.flush();
        }

    };

    document.addEventListener("visibilitychange", onHide);

}
